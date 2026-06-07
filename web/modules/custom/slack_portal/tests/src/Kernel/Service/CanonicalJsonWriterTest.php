<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for CanonicalJsonWriter — deterministic, idempotent JSON writes.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\CanonicalArchive;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for CanonicalJsonWriter.
 *
 * Uses the public:// stream wrapper which requires Kernel environment (DB).
 * Only the system module is enabled to avoid heavy M2 contrib deps. The
 * writer is constructed directly with core services.
 *
 * @covers \Drupal\slack_portal\Service\CanonicalJsonWriter
 */
#[CoversClass(\Drupal\slack_portal\Service\CanonicalJsonWriter::class)]
#[Group('slack_portal')]
class CanonicalJsonWriterTest extends KernelTestBase {

  /**
   * Only the system module is needed to set up public:// stream wrapper.
   *
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The Drupal file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * The CanonicalJsonWriter under test.
   *
   * @var \Drupal\slack_portal\Service\CanonicalJsonWriter
   */
  private CanonicalJsonWriter $writer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $this->fileSystem = $fileSystem;
    $this->writer = new CanonicalJsonWriter($this->fileSystem, new NullLogger());
  }

  /**
   * Tests that writeChannel produces valid, round-trippable JSON.
   *
   * Given channel metadata and folded messages,
   * When writeChannel() is called,
   * Then the file exists at channels/<channelId>.json under baseDir(),
   * And the decoded content exactly equals the expected canonical structure.
   */
  public function testWriteChannelProducesValidRoundTrippableJson(): void {
    // Arrange.
    $channelMeta = [
      'id' => 'C123',
      'name' => 'general',
      'type' => 'public_channel',
    ];
    $foldedMessages = [
      [
        'slack_ts' => '1.0',
        'text' => 'hi',
        'replies' => [],
      ],
    ];

    // Act.
    $uri = $this->writer->writeChannel('C123', $channelMeta, $foldedMessages);

    // Assert: returned URI is the expected stable path.
    $expectedUri = 'public://slack_archive/latest/channels/C123.json';
    $this->assertSame($expectedUri, $uri, 'writeChannel() must return the exact stable URI.');

    // Assert: file exists on disk.
    $realPath = $this->fileSystem->realpath($uri);
    $this->assertNotFalse($realPath, 'realpath() must resolve the public:// URI after write.');
    $this->assertFileExists((string) $realPath, 'Channel JSON file must exist on disk.');

    // Assert: round-trip decode equals the expected canonical structure.
    $raw = file_get_contents((string) $realPath);
    $this->assertNotFalse($raw, 'File must be readable.');
    $decoded = json_decode((string) $raw, TRUE);
    $expected = [
      'schema_version' => 1,
      'channel' => $channelMeta,
      'messages' => $foldedMessages,
    ];
    $this->assertSame($expected, $decoded, 'Decoded JSON must match the canonical structure.');
  }

  /**
   * Tests that writeChannel is byte-identical on re-run (idempotent).
   *
   * Given the same channel metadata and messages,
   * When writeChannel() is called twice,
   * Then both calls return the same URI,
   * And the file content is byte-identical across runs (no rename-on-conflict),
   * And no duplicate file (e.g. C123_0.json) exists.
   */
  public function testWriteChannelIsIdempotentByteIdentical(): void {
    // Arrange.
    $channelMeta = ['id' => 'C123', 'name' => 'general', 'type' => 'public_channel'];
    $foldedMessages = [['slack_ts' => '1.0', 'text' => 'hi', 'replies' => []]];

    // Act: write twice.
    $uri1 = $this->writer->writeChannel('C123', $channelMeta, $foldedMessages);
    $uri2 = $this->writer->writeChannel('C123', $channelMeta, $foldedMessages);

    // Assert: both calls return the same stable URI.
    $this->assertSame($uri1, $uri2, 'Both calls must return the same stable URI.');

    // Assert: content is byte-identical.
    $realPath = $this->fileSystem->realpath($uri1);
    $this->assertNotFalse($realPath, 'realpath() must resolve the URI.');
    $content1 = file_get_contents((string) $realPath);
    // Re-read after second write.
    $realPath2 = $this->fileSystem->realpath($uri2);
    $content2 = file_get_contents((string) $realPath2);
    $this->assertSame(
      $content1,
      $content2,
      'File content must be byte-identical across re-runs.',
    );

    // Assert: no duplicate file (e.g. C123_0.json) exists.
    $channelDir = $this->fileSystem->realpath(
      'public://slack_archive/latest/channels',
    );
    $this->assertNotFalse($channelDir, 'channels directory must exist.');
    $duplicatePath = $channelDir . '/C123_0.json';
    $this->assertFileDoesNotExist(
      $duplicatePath,
      'No rename-on-conflict duplicate must be created.',
    );
  }

  /**
   * Tests that writeUsers and writeManifest produce valid JSON.
   *
   * Given a list of users and a manifest array,
   * When writeUsers() and writeManifest() are called,
   * Then each file exists at the correct path under baseDir(),
   * And the decoded content round-trips to the original arrays,
   * And baseDir() returns the expected stable base directory.
   */
  public function testWriteUsersAndManifestPaths(): void {
    // Assert baseDir() equals the shared constant.
    $this->assertSame(
      CanonicalArchive::BASE_DIR,
      $this->writer->baseDir(),
      'baseDir() must return the stable archive base directory.',
    );

    // Arrange.
    $users = [
      ['id' => 'U1', 'name' => 'alice', 'real_name' => 'Alice A', 'is_bot' => FALSE],
      ['id' => 'U2', 'name' => 'bob', 'real_name' => 'Bob B', 'is_bot' => FALSE],
    ];
    $manifest = [
      'schema_version' => 1,
      'counts' => ['channels' => 1],
    ];

    // Act.
    $usersUri = $this->writer->writeUsers($users);
    $manifestUri = $this->writer->writeManifest($manifest);

    // Assert: returned URIs are the expected stable paths.
    $this->assertSame(
      'public://slack_archive/latest/users.json',
      $usersUri,
      'writeUsers() must return the stable users.json URI.',
    );
    $this->assertSame(
      'public://slack_archive/latest/manifest.json',
      $manifestUri,
      'writeManifest() must return the stable manifest.json URI.',
    );

    // Assert: users.json exists and round-trips correctly.
    $usersRealPath = $this->fileSystem->realpath($usersUri);
    $this->assertNotFalse($usersRealPath, 'realpath() must resolve users.json URI.');
    $this->assertFileExists((string) $usersRealPath, 'users.json must exist on disk.');
    $decodedUsers = json_decode(file_get_contents((string) $usersRealPath), TRUE);
    $this->assertSame($users, $decodedUsers, 'users.json must round-trip to the original array.');

    // Assert: manifest.json exists and round-trips correctly.
    $manifestRealPath = $this->fileSystem->realpath($manifestUri);
    $this->assertNotFalse($manifestRealPath, 'realpath() must resolve manifest.json URI.');
    $this->assertFileExists((string) $manifestRealPath, 'manifest.json must exist on disk.');
    $decodedManifest = json_decode(file_get_contents((string) $manifestRealPath), TRUE);
    $this->assertSame($manifest, $decodedManifest, 'manifest.json must round-trip to the original array.');
  }

}
