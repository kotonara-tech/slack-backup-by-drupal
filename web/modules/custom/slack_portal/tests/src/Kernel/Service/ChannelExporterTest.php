<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for ChannelExporter — full per-channel export pipeline.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\CanonicalMessageFormatter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\CursorIterator;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackFetcher;
use Drupal\slack_portal\Service\SlackFileDownloader;
use Drupal\slack_portal\Service\ThreadFolder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for ChannelExporter.
 *
 * Exercises the full per-channel export pipeline:
 * history → replies → format → fold → inline-file download → write.
 *
 * The slack_portal module is NOT enabled to avoid heavy contrib deps.
 * All services are constructed directly. Two separate MockHandlers are used:
 * - MockHandler A: drives the JoliCode Slack API client (conversations.history
 *   then conversations.replies).
 * - MockHandler B: drives the Guzzle HTTP client inside SlackFileDownloader
 *   (file download).
 *
 * @covers \Drupal\slack_portal\Service\ChannelExporter
 */
#[CoversClass(\Drupal\slack_portal\Service\ChannelExporter::class)]
#[Group('slack_portal')]
class ChannelExporterTest extends KernelTestBase {

  /**
   * Only system is needed to set up the public:// stream wrapper.
   *
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Test-only placeholder token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test'; // pragma: allowlist secret

  /**
   * Builds a Slack API client (MockHandler A) with queued Slack API responses.
   *
   * Queued in call order:
   * 1. conversations.history response (parent with file + standalone).
   * 2. conversations.replies response (for thread_ts 1.0).
   *
   * @return array{0: \JoliCode\Slack\Api\Client, 1: \GuzzleHttp\Handler\MockHandler}
   *   A tuple of [SlackApiClient, MockHandler].
   */
  private function buildSlackClient(): array {
    $historyBody = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '1.0',
          'thread_ts' => '1.0',
          'reply_count' => 1,
          'user' => 'U1',
          'text' => 'parent',
          'files' => [
            [
              'id' => 'F1',
              'name' => 'a.png',
              'mimetype' => 'image/png',
              'url_private' => 'https://files.slack.com/F1',
              'size' => 7,
            ],
          ],
        ],
        [
          'ts' => '9.0',
          'user' => 'U2',
          'text' => 'standalone',
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    $repliesBody = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '1.0',
          'thread_ts' => '1.0',
          'text' => 'parent',
        ],
        [
          'ts' => '2.0',
          'thread_ts' => '1.0',
          'user' => 'U2',
          'text' => 'reply1',
        ],
      ],
    ]);

    $mockA = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) $historyBody),
      new Response(200, ['Content-Type' => 'application/json'], (string) $repliesBody),
    ]);

    $client = (new SlackClientFactory())->createWithHandler($mockA, self::TEST_TOKEN);

    return [$client, $mockA];
  }

  /**
   * Builds a SlackFileDownloader backed by MockHandler B.
   *
   * Queued with a single 200 response body 'PNGDATA' (7 bytes).
   *
   * @return \Drupal\slack_portal\Service\SlackFileDownloader
   *   The downloader under test.
   */
  private function buildFileDownloader(): SlackFileDownloader {
    $mockB = new MockHandler([
      new Response(200, [], 'PNGDATA'),
    ]);
    $stack = HandlerStack::create($mockB);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');

    return new SlackFileDownloader($guzzle, $fileSystem, new NullLogger());
  }

  /**
   * Tests the full export pipeline for a channel with a thread and a file.
   *
   * Given:
   *   - conversations.history yields a thread-root parent (ts=1.0) with one
   *     file attachment (F1, a.png, 7 bytes) and a standalone message (ts=9.0).
   *   - conversations.replies for thread 1.0 yields the parent + one reply
   *     (ts=2.0); the parent is excluded by SlackFetcher.fetchReplies().
   *   - MockHandler B delivers 'PNGDATA' (7 bytes) for the file download.
   *
   * When exportChannel() is called with channelMeta
   * ['id'=>'C1','name'=>'general'].
   *
   * Then:
   *   1. The channel JSON is written to
   *      public://slack_archive/latest/channels/C1.json.
   *   2. Top-level messages contains exactly 2 entries (parent 1.0 and
   *      standalone 9.0).
   *   3. The standalone message has no 'replies' key (ThreadFolder leaves
   *      standalones unmodified).
   *   4. The parent (1.0) has a 'replies' array containing exactly one reply
   *      with slack_ts '2.0'.
   *   5. The parent's files[0] has local_path 'files/F1.png' and
   *      url_private 'REDACTED'.
   *   6. The downloaded file exists at
   *      public://slack_archive/latest/files/F1.png with contents 'PNGDATA'.
   *   7. The return value is ['messages' => 2] (top-level count).
   *   8. The token string 'xoxp-test' does NOT appear anywhere in the written
   *      channel JSON.
   */
  public function testExportChannelFullPipeline(): void {
    // Arrange.
    [$client] = $this->buildSlackClient();
    $downloader = $this->buildFileDownloader();

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');

    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());
    $formatter = new CanonicalMessageFormatter();
    $folder = new ThreadFolder();
    $writer = new CanonicalJsonWriter($fileSystem, new NullLogger());
    $logger = new NullLogger();

    $exporter = new ChannelExporter(
      $fetcher,
      $formatter,
      $folder,
      $downloader,
      $writer,
      $logger,
    );

    $channelMeta = [
      'id' => 'C1',
      'name' => 'general',
      'type' => 'public_channel',
    ];

    // Act.
    $result = $exporter->exportChannel($client, self::TEST_TOKEN, $channelMeta);

    // --- Assertion 1: channel JSON file exists. ---
    $channelUri = 'public://slack_archive/latest/channels/C1.json';
    $realPath = $fileSystem->realpath($channelUri);
    $this->assertNotFalse($realPath, 'Channel JSON URI must resolve to a real path.');
    $this->assertFileExists((string) $realPath, 'Channel JSON file must exist on disk.');

    // Decode the written JSON for subsequent assertions.
    $raw = file_get_contents((string) $realPath);
    $this->assertNotFalse($raw, 'Channel JSON file must be readable.');
    $decoded = json_decode((string) $raw, TRUE);
    $this->assertIsArray($decoded, 'Channel JSON must decode to an array.');

    /** @var array<int,array<string,mixed>> $messages */
    $messages = $decoded['messages'];

    // --- Assertion 2: exactly 2 top-level messages. ---
    $this->assertCount(
      2,
      $messages,
      'Top-level messages must contain exactly 2 entries (parent + standalone).',
    );

    // Identify parent (ts=1.0) and standalone (ts=9.0) by slack_ts.
    $parent = NULL;
    $standalone = NULL;
    foreach ($messages as $msg) {
      if ($msg['slack_ts'] === '1.0') {
        $parent = $msg;
      }
      elseif ($msg['slack_ts'] === '9.0') {
        $standalone = $msg;
      }
    }
    $this->assertNotNull($parent, 'Parent message (ts=1.0) must be present.');
    $this->assertNotNull($standalone, 'Standalone message (ts=9.0) must be present.');

    // --- Assertion 3: standalone has no 'replies' key. ---
    $this->assertArrayNotHasKey(
      'replies',
      $standalone,
      'ThreadFolder must leave standalone messages without a "replies" key.',
    );

    // --- Assertion 4: parent has exactly one reply with slack_ts='2.0'. ---
    $this->assertArrayHasKey(
      'replies',
      $parent,
      'Thread-root parent must have a "replies" key after folding.',
    );
    $this->assertCount(
      1,
      $parent['replies'],
      'Parent must have exactly one reply.',
    );
    $this->assertSame(
      '2.0',
      $parent['replies'][0]['slack_ts'],
      'The single reply must have slack_ts "2.0".',
    );

    // --- Assertion 5: parent file has local_path + REDACTED url_private. ---
    $this->assertArrayHasKey(
      'files',
      $parent,
      'Parent message must have a "files" key.',
    );
    $this->assertCount(1, $parent['files'], 'Parent must have exactly one file entry.');
    $file = $parent['files'][0];
    $this->assertSame(
      'files/F1.png',
      $file['local_path'],
      'File local_path must be the relative path "files/F1.png".',
    );
    $this->assertSame(
      'REDACTED',
      $file['url_private'],
      'File url_private must be replaced with "REDACTED" in the written JSON.',
    );

    // --- Assertion 6: downloaded file exists with correct contents. ---
    $fileUri = 'public://slack_archive/latest/files/F1.png';
    $fileRealPath = $fileSystem->realpath($fileUri);
    $this->assertNotFalse($fileRealPath, 'Downloaded file URI must resolve to a real path.');
    $this->assertFileExists((string) $fileRealPath, 'Downloaded file must exist on disk.');
    $this->assertSame(
      'PNGDATA',
      file_get_contents((string) $fileRealPath),
      'Downloaded file content must be "PNGDATA".',
    );

    // --- Assertion 7: return value is ['messages' => 2]. ---
    $this->assertSame(
      ['messages' => 2],
      $result,
      'exportChannel() must return ["messages" => 2] (top-level count).',
    );

    // --- Assertion 8: token must not appear in channel JSON. ---
    $this->assertStringNotContainsString(
      self::TEST_TOKEN,
      (string) $raw,
      'The Slack token must NOT appear anywhere in the written channel JSON.',
    );
  }

  /**
   * Tests that a file with an uppercase extension is saved with lowercase ext.
   *
   * Given a file named 'IMAGE.PNG' (uppercase extension),
   * When exportChannel() downloads it,
   * Then the local_path uses the lowercase extension 'png',
   * And the file is written to files/F2.png (not files/F2.PNG).
   */
  public function testFileExtensionIsLowercased(): void {
    // Arrange: history with a message whose file has an uppercase extension.
    $historyBody = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '5.0',
          'user' => 'U3',
          'text' => 'uppercase ext',
          'files' => [
            [
              'id' => 'F2',
              'name' => 'IMAGE.PNG',
              'mimetype' => 'image/png',
              'url_private' => 'https://files.slack.com/F2',
              'size' => 4,
            ],
          ],
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    $mockA = new MockHandler([
      new Response(
        200,
        ['Content-Type' => 'application/json'],
        (string) $historyBody,
      ),
    ]);
    $client = (new SlackClientFactory())->createWithHandler(
      $mockA,
      self::TEST_TOKEN,
    );

    $mockB = new MockHandler([new Response(200, [], 'DATA')]);
    $stack = HandlerStack::create($mockB);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $downloader = new SlackFileDownloader(
      $guzzle,
      $fileSystem,
      new NullLogger(),
    );

    $exporter = new ChannelExporter(
      new SlackFetcher(new CursorIterator(), new NullLogger()),
      new CanonicalMessageFormatter(),
      new ThreadFolder(),
      $downloader,
      new CanonicalJsonWriter($fileSystem, new NullLogger()),
      new NullLogger(),
    );

    // Act.
    $exporter->exportChannel(
      $client,
      self::TEST_TOKEN,
      ['id' => 'C2', 'name' => 'upper', 'type' => 'public_channel'],
    );

    // Assert: written channel JSON uses lowercase extension.
    $channelUri = 'public://slack_archive/latest/channels/C2.json';
    $realPath = $fileSystem->realpath($channelUri);
    $this->assertNotFalse($realPath);
    $decoded = json_decode(
      (string) file_get_contents((string) $realPath),
      TRUE,
    );
    $this->assertIsArray($decoded);
    /** @var array<int,array<string,mixed>> $msgs */
    $msgs = $decoded['messages'];
    $this->assertSame(
      'files/F2.png',
      $msgs[0]['files'][0]['local_path'],
      'Uppercase extension must be lowercased in local_path.',
    );

    // Assert: file exists at the lowercase path.
    $fileUri = 'public://slack_archive/latest/files/F2.png';
    $this->assertNotFalse($fileSystem->realpath($fileUri));
    $this->assertFileExists(
      (string) $fileSystem->realpath($fileUri),
      'File must be written with lowercase extension.',
    );
  }

  /**
   * Tests an external (non-Slack-host) file is preserved, not REDACTed.
   *
   * When SlackFileDownloader skips a file whose url_private points off Slack
   * (security guard), ChannelExporter must NOT claim a local copy nor destroy
   * the original URL: local_path stays NULL and url_private is preserved, so
   * the only reference to the attachment is not lost. The returned files count
   * counts only files actually downloaded.
   */
  public function testExternalFileIsPreservedNotRedacted(): void {
    $externalUrl = 'https://docs.google.com/document/d/EXT/edit';
    $historyBody = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '3.0',
          'user' => 'U4',
          'text' => 'external file',
          'files' => [
            [
              'id' => 'FEXT',
              'name' => 'doc.gdoc',
              'mimetype' => 'application/vnd.google-apps.document',
              'url_private' => $externalUrl,
              'size' => 0,
            ],
          ],
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    $mockA = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) $historyBody),
    ]);
    $client = (new SlackClientFactory())->createWithHandler($mockA, self::TEST_TOKEN);

    // MockHandler B would throw if any download request were attempted.
    $mockB = new MockHandler([]);
    $stack = HandlerStack::create($mockB);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $downloader = new SlackFileDownloader($guzzle, $fileSystem, new NullLogger());

    $exporter = new ChannelExporter(
      new SlackFetcher(new CursorIterator(), new NullLogger()),
      new CanonicalMessageFormatter(),
      new ThreadFolder(),
      $downloader,
      new CanonicalJsonWriter($fileSystem, new NullLogger()),
      new NullLogger(),
    );

    // Act.
    $result = $exporter->exportChannel(
      $client,
      self::TEST_TOKEN,
      ['id' => 'C3', 'name' => 'ext', 'type' => 'public_channel'],
    );

    // Assert: no file was downloaded → files count is 0.
    $this->assertSame(0, $result['files'], 'No external file may be counted as downloaded.');

    // Assert: written JSON preserves the original URL, local_path stays NULL.
    $realPath = $fileSystem->realpath('public://slack_archive/latest/channels/C3.json');
    $this->assertNotFalse($realPath);
    $decoded = json_decode((string) file_get_contents((string) $realPath), TRUE);
    $this->assertIsArray($decoded);
    $file = $decoded['messages'][0]['files'][0];
    $this->assertSame(
      $externalUrl,
      $file['url_private'],
      'External url_private must be preserved (not REDACTED) so the file is recoverable.',
    );
    $this->assertNull(
      $file['local_path'],
      'local_path must stay NULL when the file was not downloaded.',
    );
  }

}
