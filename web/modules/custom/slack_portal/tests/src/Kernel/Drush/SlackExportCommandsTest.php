<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackExportCommands — full workspace export via fake Slack.
 */

namespace Drupal\Tests\slack_portal\Kernel\Drush;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\slack_portal\Drush\Commands\SlackExportCommands;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\CanonicalMessageFormatter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\CursorIterator;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackFetcher;
use Drupal\slack_portal\Service\SlackFileDownloader;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Drupal\slack_portal\Service\ThreadFolder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use JoliCode\Slack\Api\Client as SlackApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for SlackExportCommands.
 *
 * Exercises the full workspace export: users → channels → per-channel export
 * (history + replies) → files.list → manifest.
 *
 * The slack_portal module is NOT enabled to avoid heavy contrib deps.
 * All services are constructed directly. A test subclass of SlackClientFactory
 * injects a MockHandler so the command's Slack API calls are fully controlled.
 *
 * MockHandler response ORDER (must match command's actual call sequence):
 *  1. users.list (U1 alice)
 *  2. conversations.list (C1 public + D1 im)
 *  3. conversations.history C1 (parent ts=1.0 reply_count=1; standalone ts=9.0)
 *  4. conversations.replies for C1 thread ts=1.0 (parent+reply)
 *  5. conversations.history for D1 (1 plain message, no thread)
 *  6. files.list (empty)
 *
 * @covers \Drupal\slack_portal\Drush\Commands\SlackExportCommands
 */
#[CoversClass(\Drupal\slack_portal\Drush\Commands\SlackExportCommands::class)]
#[Group('slack_portal')]
class SlackExportCommandsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Test-only placeholder token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test-token'; // pragma: allowlist secret

  /**
   * {@inheritdoc}
   *
   * Creates the private:// directory and sets file_private_path so that
   * CoreServiceProvider registers the private stream wrapper when the kernel
   * boots.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $private_dir = $this->siteDirectory . '/private';
    mkdir($private_dir, 0775, TRUE);
    $this->setSetting('file_private_path', $private_dir);
  }

  /**
   * Builds the fixture MockHandler with responses in the exact call order.
   *
   * Order: (1) users.list, (2) conversations.list, (3) conversations.history
   * C1, (4) conversations.replies C1 thread 1.0, (5) conversations.history D1,
   * (6) files.list.
   *
   * @return \GuzzleHttp\Handler\MockHandler
   *   The fixture MockHandler with all ordered responses.
   */
  private function buildMainMockHandler(): MockHandler {
    $usersResponse = json_encode([
      'ok' => TRUE,
      'members' => [
        [
          'id' => 'U1',
          'name' => 'alice',
          'real_name' => 'Alice',
          'profile' => [
            'display_name' => 'al',
            'email' => 'a@x.test',
            'image_192' => 'https://x/av.png',
          ],
          'is_bot' => FALSE,
          'deleted' => FALSE,
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    $conversationsListResponse = json_encode([
      'ok' => TRUE,
      'channels' => [
        [
          'id' => 'C1',
          'name' => 'general',
          'is_private' => FALSE,
          'is_im' => FALSE,
          'is_mpim' => FALSE,
          'topic' => ['value' => 't'],
          'purpose' => ['value' => 'p'],
        ],
        [
          'id' => 'D1',
          'is_im' => TRUE,
          'user' => 'U1',
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    // C1 history: thread-root parent (ts=1.0, reply_count=1) + standalone.
    $c1HistoryResponse = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '1.0',
          'thread_ts' => '1.0',
          'reply_count' => 1,
          'user' => 'U1',
          'text' => 'thread parent',
        ],
        [
          'ts' => '9.0',
          'user' => 'U1',
          'text' => 'standalone',
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    // C1 replies for ts=1.0: parent + one reply (parent filtered out).
    $c1RepliesResponse = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '1.0',
          'thread_ts' => '1.0',
          'text' => 'thread parent',
        ],
        [
          'ts' => '2.0',
          'thread_ts' => '1.0',
          'user' => 'U1',
          'text' => 'reply',
        ],
      ],
    ]);

    // D1 history: 1 plain message (no thread, no replies call).
    $d1HistoryResponse = json_encode([
      'ok' => TRUE,
      'messages' => [
        [
          'ts' => '5.0',
          'user' => 'U1',
          'text' => 'dm message',
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    // files.list: empty result.
    $filesListResponse = json_encode([
      'ok' => TRUE,
      'files' => [],
      'paging' => ['count' => 0, 'total' => 0, 'page' => 1, 'pages' => 1],
    ]);

    return new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) $usersResponse),
      new Response(200, ['Content-Type' => 'application/json'], (string) $conversationsListResponse),
      new Response(200, ['Content-Type' => 'application/json'], (string) $c1HistoryResponse),
      new Response(200, ['Content-Type' => 'application/json'], (string) $c1RepliesResponse),
      new Response(200, ['Content-Type' => 'application/json'], (string) $d1HistoryResponse),
      new Response(200, ['Content-Type' => 'application/json'], (string) $filesListResponse),
    ]);
  }

  /**
   * Builds the SlackExportCommands under test using direct construction.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mockHandler
   *   The MockHandler to inject into the test SlackClientFactory subclass.
   *
   * @return \Drupal\slack_portal\Drush\Commands\SlackExportCommands
   *   The command under test.
   */
  private function buildCommand(MockHandler $mockHandler): SlackExportCommands {
    // Anonymous subclass of SlackClientFactory: overrides create() to use our
    // MockHandler instead of the real Guzzle transport.
    $factory = new class() extends SlackClientFactory {

      /** @var \GuzzleHttp\Handler\MockHandler|null */
      public ?MockHandler $h = NULL;

      /**
       * {@inheritdoc}
       */
      public function create(string $token, ?int $maxRetries = NULL): SlackApiClient {
        return $this->createWithHandler($this->h, $token);
      }

    };
    $factory->h = $mockHandler;

    // Settings with the test token (TEST_TOKEN is a placeholder only).
    // The encrypt deps are mocked because the Settings path is used here.
    // Preserve all existing settings (including file_private_path) while
    // injecting the test token so the private:// stream wrapper stays usable.
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    $tokenProvider = new SlackTokenProvider(
      new Settings(Settings::getAll() + ['slack_user_token' => self::TEST_TOKEN]),
      $state,
      $this->createMock(EncryptServiceInterface::class),
      $this->createMock(EncryptionProfileManagerInterface::class),
    );

    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    // Empty MockHandler for the file downloader (no inline files in fixtures).
    $emptyFileMock = new MockHandler([]);
    $stack = HandlerStack::create($emptyFileMock);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');

    $downloader = new SlackFileDownloader($guzzle, $fileSystem, new NullLogger());
    $writer = new CanonicalJsonWriter($fileSystem, new NullLogger());

    $exporter = new ChannelExporter(
      $fetcher,
      new CanonicalMessageFormatter(),
      new ThreadFolder(),
      $downloader,
      $writer,
      new NullLogger(),
    );

    return new SlackExportCommands(
      $tokenProvider,
      $factory,
      $fetcher,
      $exporter,
      $writer,
      new NullLogger(),
    );
  }

  /**
   * Tests the full workspace export returns EXIT_SUCCESS and writes all files.
   *
   * Given:
   *   - users.list returns 1 user (U1 alice).
   *   - conversations.list returns 1 public channel (C1) and 1 IM (D1).
   *   - C1 history: thread-root (ts=1.0, reply_count=1) + standalone (ts=9.0).
   *   - C1 replies for 1.0: parent + reply ts=2.0.
   *   - D1 history: 1 plain message (ts=5.0), no thread → no replies call.
   *   - files.list: empty.
   *
   * When export(['since' => '90d']) is called.
   *
   * Then:
   *   1. Returns 0 (EXIT_SUCCESS).
   *   2. users.json: 1 user; id=U1, display_name=al, email=a@x.test.
   *   3. channels/C1.json: type public_channel; 2 top-level messages;
   *      parent ts=1.0 has replies with reply ts=2.0; standalone ts=9.0.
   *   4. channels/D1.json: type im; 1 top-level message.
   *   5. manifest.json: counts.channels=2, counts.users=1, messages>=3;
   *      channels index lists both C1 and D1 with file keys.
   *   6. Idempotency: re-running with fresh fixtures gives identical C1.json.
   *   7. Token does NOT appear in any archive file.
   */
  public function testExportFullWorkspace(): void {
    // --- Arrange ---
    $mock = $this->buildMainMockHandler();
    $cmd = $this->buildCommand($mock);

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');

    // --- Act ---
    $exitCode = $cmd->export(['since' => '90d']);

    // --- Assertion 1: returns EXIT_SUCCESS ---
    $this->assertSame(0, $exitCode, 'export() must return 0 (EXIT_SUCCESS).');

    // --- Assertion 2: users.json ---
    $usersUri = 'private://slack_archive/latest/users.json';
    $usersPath = $fileSystem->realpath($usersUri);
    $this->assertNotFalse($usersPath, 'users.json URI must resolve to a real path.');
    $this->assertFileExists((string) $usersPath, 'users.json must exist on disk.');
    $users = json_decode((string) file_get_contents((string) $usersPath), TRUE);
    $this->assertIsArray($users, 'users.json must decode to an array.');
    $this->assertCount(1, $users, 'users.json must contain exactly 1 user.');
    $this->assertSame('U1', $users[0]['id'], 'User id must be U1.');
    $this->assertSame('al', $users[0]['display_name'], 'display_name must be al.');
    $this->assertSame('a@x.test', $users[0]['email'], 'email must be a@x.test.');

    // --- Assertion 3: channels/C1.json ---
    $c1Uri = 'private://slack_archive/latest/channels/C1.json';
    $c1Path = $fileSystem->realpath($c1Uri);
    $this->assertNotFalse($c1Path, 'channels/C1.json URI must resolve to a real path.');
    $this->assertFileExists((string) $c1Path, 'channels/C1.json must exist on disk.');
    $c1 = json_decode((string) file_get_contents((string) $c1Path), TRUE);
    $this->assertIsArray($c1);
    $this->assertSame('public_channel', $c1['channel']['type'], 'C1 type must be public_channel.');
    $messages = $c1['messages'];
    $this->assertCount(2, $messages, 'C1 must have 2 top-level messages.');
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
    $this->assertNotNull($parent, 'Parent message ts=1.0 must be present.');
    $this->assertNotNull($standalone, 'Standalone message ts=9.0 must be present.');
    $this->assertArrayHasKey('replies', $parent, 'Parent must have replies after folding.');
    $this->assertCount(1, $parent['replies'], 'Parent must have exactly 1 reply.');
    $this->assertSame('2.0', $parent['replies'][0]['slack_ts'], 'Reply must have slack_ts=2.0.');

    // --- Assertion 4: channels/D1.json ---
    $d1Uri = 'private://slack_archive/latest/channels/D1.json';
    $d1Path = $fileSystem->realpath($d1Uri);
    $this->assertNotFalse($d1Path, 'channels/D1.json URI must resolve to a real path.');
    $this->assertFileExists((string) $d1Path, 'channels/D1.json must exist on disk.');
    $d1 = json_decode((string) file_get_contents((string) $d1Path), TRUE);
    $this->assertIsArray($d1);
    $this->assertSame('im', $d1['channel']['type'], 'D1 type must be im.');
    $this->assertCount(1, $d1['messages'], 'D1 must have 1 top-level message.');

    // --- Assertion 5: manifest.json ---
    $manifestUri = 'private://slack_archive/latest/manifest.json';
    $manifestPath = $fileSystem->realpath($manifestUri);
    $this->assertNotFalse($manifestPath, 'manifest.json URI must resolve to a real path.');
    $this->assertFileExists((string) $manifestPath, 'manifest.json must exist on disk.');
    $manifest = json_decode((string) file_get_contents((string) $manifestPath), TRUE);
    $this->assertIsArray($manifest);
    $this->assertSame(2, $manifest['counts']['channels'], 'counts.channels must be 2.');
    $this->assertSame(1, $manifest['counts']['users'], 'counts.users must be 1.');
    $this->assertGreaterThanOrEqual(3, $manifest['counts']['messages'], 'counts.messages must be >= 3.');
    $this->assertCount(2, $manifest['channels'], 'channels index must list 2 channels.');
    $channelIds = array_column($manifest['channels'], 'id');
    $this->assertContains('C1', $channelIds, 'channels index must include C1.');
    $this->assertContains('D1', $channelIds, 'channels index must include D1.');
    foreach ($manifest['channels'] as $entry) {
      $this->assertArrayHasKey('file', $entry, 'Each channels entry must have a file key.');
    }

    // --- Assertion 6: Idempotency ---
    // Record sha1 of C1.json before the second run.
    $c1ContentBefore = file_get_contents((string) $c1Path);
    $this->assertNotFalse($c1ContentBefore);
    $sha1Before = sha1((string) $c1ContentBefore);

    // Re-run with a fresh MockHandler queue (responses were consumed).
    $mock2 = $this->buildMainMockHandler();
    $cmd2 = $this->buildCommand($mock2);
    $exitCode2 = $cmd2->export(['since' => '90d']);
    $this->assertSame(0, $exitCode2, 'Second run must also return EXIT_SUCCESS.');

    $c1ContentAfter = file_get_contents((string) $c1Path);
    $this->assertNotFalse($c1ContentAfter);
    $sha1After = sha1((string) $c1ContentAfter);
    $this->assertSame($sha1Before, $sha1After, 'C1.json must be byte-identical on second run (idempotency).');

    // --- Assertion 7: token must NOT appear in any archive file ---
    $baseDir = 'private://slack_archive/latest';
    $baseDirReal = (string) $fileSystem->realpath($baseDir);
    $this->assertDirectoryExists($baseDirReal, 'Archive base directory must exist.');
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($baseDirReal, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      /** @var \SplFileInfo $file */
      if ($file->isFile()) {
        $content = file_get_contents($file->getPathname());
        $this->assertStringNotContainsString(
          self::TEST_TOKEN,
          (string) $content,
          sprintf('Token must NOT appear in archive file: %s', $file->getPathname()),
        );
      }
    }
  }

}
