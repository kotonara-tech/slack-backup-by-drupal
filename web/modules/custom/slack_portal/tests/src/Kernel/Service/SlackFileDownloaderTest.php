<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackFileDownloader — Bearer-auth streaming + idempotency.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\Service\SlackFileDownloader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for SlackFileDownloader.
 *
 * Uses the public:// stream wrapper which requires Kernel environment (DB).
 * The slack_portal module is NOT enabled here to avoid heavy M2 contrib deps
 * (search_api, facets, jsonapi_search_api). The downloader is constructed
 * directly with core services.
 *
 * @covers \Drupal\slack_portal\Service\SlackFileDownloader
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackFileDownloader::class)]
#[Group('slack_portal')]
class SlackFileDownloaderTest extends KernelTestBase {

  /**
   * Only the system module is needed to set up public:// stream wrapper.
   *
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Test-only placeholder token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
  private const TEST_TOKEN = 'xoxp-test-token'; // pragma: allowlist secret

  /**
   * The Drupal file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $this->fileSystem = $fileSystem;
  }

  /**
   * Builds a Guzzle client with MockHandler and optional history middleware.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler to drive.
   * @param array<array{request:\Psr\Http\Message\RequestInterface,response:mixed}> $history
   *   A by-reference array Guzzle populates with request/response pairs.
   *
   * @return \GuzzleHttp\Client
   *   A plain Guzzle client backed by the MockHandler.
   */
  private function buildGuzzleClient(MockHandler $mock, array &$history = []): Client {
    $stack = HandlerStack::create($mock);
    if ($history !== []) {
      $stack->push(Middleware::history($history), 'history');
    }
    else {
      // Always attach history so we can inspect it, even if passed empty array.
      $stack->push(Middleware::history($history), 'history');
    }
    return new Client(['handler' => $stack]);
  }

  /**
   * Tests that a file is streamed to disk with correct Bearer authorization.
   *
   * Given a MockHandler queued with a 200 response body 'PNGDATA',
   * When download() is called with a url_private, token, and dest stream URI,
   * Then the file exists on disk with content 'PNGDATA',
   * And the outgoing HTTP request used GET with an Authorization: Bearer header,
   * And the method returns the destination stream URI.
   */
  public function testStreamsFileToDiskWithBearer(): void {
    // Arrange.
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], 'PNGDATA'),
    ]);
    $httpClient = $this->buildGuzzleClient($mock, $history);
    $downloader = new SlackFileDownloader($httpClient, $this->fileSystem, new NullLogger());

    $destUri = 'public://slack_archive/latest/files/F1.png';
    $urlPrivate = 'https://files.slack.com/files-pri/T1/F1.png';

    // Act.
    $result = $downloader->download($urlPrivate, self::TEST_TOKEN, $destUri);

    // Assert: return value is the destination URI.
    $this->assertSame($destUri, $result, 'download() must return the destination stream URI.');

    // Assert: file exists on disk with expected content.
    $realPath = $this->fileSystem->realpath($destUri);
    $this->assertNotFalse($realPath, 'realpath() must resolve the public:// URI after download.');
    $this->assertFileExists((string) $realPath, 'Downloaded file must exist on disk.');
    $this->assertSame('PNGDATA', file_get_contents((string) $realPath), 'File content must match the mock response body.');

    // Assert: the request used GET with Bearer Authorization.
    $this->assertCount(1, $history, 'Exactly one HTTP request must have been made.');
    $request = $history[0]['request'];
    $this->assertSame('GET', $request->getMethod(), 'HTTP method must be GET.');
    $this->assertSame($urlPrivate, (string) $request->getUri(), 'Request URL must match url_private.');
    $this->assertSame(
      'Bearer ' . self::TEST_TOKEN, // pragma: allowlist secret
      $request->getHeaderLine('Authorization'),
      'Authorization header must contain Bearer token.',
    );
  }

  /**
   * Tests that an existing file with matching size skips the HTTP download.
   *
   * Given a destination file already exists with content 'EXISTING' (8 bytes),
   * When download() is called with expectedSize=8 and a MockHandler that would
   * fail if invoked (empty queue),
   * Then no HTTP request is made (MockHandler remains full/empty/unchanged),
   * And the method returns the destination stream URI unchanged.
   */
  public function testSkipsDownloadWhenFileExistsWithMatchingSize(): void {
    // Arrange: pre-write the destination file.
    $destUri = 'public://slack_archive/latest/files/F2.png';
    $existingContent = 'EXISTING';

    $dir = dirname($destUri);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $realPath = $this->fileSystem->realpath($dir) . '/' . basename($destUri);
    file_put_contents($realPath, $existingContent);

    // An empty MockHandler — any request attempt would throw an exception.
    $mock = new MockHandler([]);
    $history = [];
    $httpClient = $this->buildGuzzleClient($mock, $history);
    $downloader = new SlackFileDownloader($httpClient, $this->fileSystem, new NullLogger());

    // Act.
    $result = $downloader->download(
      'https://files.slack.com/files-pri/T1/F2.png',
      self::TEST_TOKEN, // pragma: allowlist secret
      $destUri,
      expectedSize: strlen($existingContent),
    );

    // Assert: no HTTP request was made (idempotency).
    $this->assertCount(0, $history, 'No HTTP request must be made when file already exists with matching size.');
    $this->assertCount(0, $mock, 'MockHandler must remain empty (no requests consumed).');

    // Assert: return value is still the destination URI.
    $this->assertSame($destUri, $result, 'download() must return the destination stream URI on skip.');

    // Assert: the existing file was not modified.
    $this->assertSame($existingContent, file_get_contents($realPath), 'Existing file content must be preserved on skip.');
  }

}
