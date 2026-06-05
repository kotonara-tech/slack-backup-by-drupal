<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackFetchQueueWorker — per-channel queue processing.
 */

namespace Drupal\Tests\slack_portal\Kernel\Plugin\QueueWorker;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\Plugin\QueueWorker\SlackFetchQueueWorker;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackTokenProvider;
use JoliCode\Slack\Api\Client as SlackApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for SlackFetchQueueWorker.
 *
 * Constructs the worker directly (not via plugin manager) using real
 * ExportStateService + CanonicalJsonWriter and mocks for SlackClientFactory
 * and ChannelExporter (both non-final). SlackTokenProvider is final so we
 * supply a Settings-based token (resolver 1) to avoid touching encryption;
 * modules key+encrypt+real_aes are enabled so the container can instantiate
 * the real service correctly even though decryption is never invoked.
 *
 * @covers \Drupal\slack_portal\Plugin\QueueWorker\SlackFetchQueueWorker
 */
#[CoversClass(\Drupal\slack_portal\Plugin\QueueWorker\SlackFetchQueueWorker::class)]
#[Group('slack_portal')]
class SlackFetchQueueWorkerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'key', 'encrypt', 'real_aes'];

  /**
   * Test-only placeholder token (resolved from Settings, not encryption).
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test'; // pragma: allowlist secret

  /**
   * Builds a SlackFetchQueueWorker with the given mocks and real services.
   *
   * @param \Drupal\slack_portal\Service\SlackClientFactory $clientFactory
   *   The (possibly mocked) client factory.
   * @param \Drupal\slack_portal\Service\ChannelExporter $channelExporter
   *   The (possibly mocked) channel exporter.
   * @param \Drupal\slack_portal\Service\ExportStateService $stateService
   *   The real state service.
   * @param \Drupal\slack_portal\Service\CanonicalJsonWriter $jsonWriter
   *   The real JSON writer.
   *
   * @return \Drupal\slack_portal\Plugin\QueueWorker\SlackFetchQueueWorker
   *   The worker under test.
   */
  private function buildWorker(
    SlackClientFactory $clientFactory,
    ChannelExporter $channelExporter,
    ExportStateService $stateService,
    CanonicalJsonWriter $jsonWriter,
  ): SlackFetchQueueWorker {
    // SlackTokenProvider is final — use Settings resolver (no encryption).
    $tokenProvider = new SlackTokenProvider(
      new Settings([
        // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
        'slack_user_token' => self::TEST_TOKEN, // pragma: allowlist secret
      ]),
      $this->container->get('state'),
      $this->container->get('encryption'),
      $this->container->get('encrypt.encryption_profile.manager'),
    );

    return new SlackFetchQueueWorker(
      [],
      'slack_portal_fetch',
      ['cron' => ['time' => 60]],
      $tokenProvider,
      $clientFactory,
      $channelExporter,
      $jsonWriter,
      $stateService,
      $this->container->get('datetime.time'),
      new NullLogger(),
    );
  }

  /**
   * Tests processItem() with a single-channel export completes the run.
   *
   * Given ExportStateService has been started with total=1, users=4,
   * And ChannelExporter mock returns ['messages'=>3] for channel C1,
   * When processItem(['channel_meta'=>['id'=>'C1','name'=>'general',
   *   'type'=>'public_channel']]) is called,
   * Then getStatus() shows processed==1, messages==3, status=='done',
   * channels has the C1 entry, and the manifest JSON exists at
   * public://slack_archive/latest/manifest.json with counts.channels==1,
   * counts.messages==3, counts.users==4.
   */
  public function testProcessItemCompletesWhenSingleChannel(): void {
    // Arrange: mocks.
    $mockSlackClient = $this->createMock(SlackApiClient::class);

    /** @var \Drupal\slack_portal\Service\SlackClientFactory&\PHPUnit\Framework\MockObject\MockObject $clientFactory */
    $clientFactory = $this->createMock(SlackClientFactory::class);
    $clientFactory->method('create')->willReturn($mockSlackClient);

    /** @var \Drupal\slack_portal\Service\ChannelExporter&\PHPUnit\Framework\MockObject\MockObject $channelExporter */
    $channelExporter = $this->createMock(ChannelExporter::class);
    $channelExporter
      ->expects($this->once())
      ->method('exportChannel')
      ->willReturn(['messages' => 3]);

    // Real services.
    $stateService = new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $jsonWriter = new CanonicalJsonWriter($fileSystem, new NullLogger());

    // Pre-condition: start with 1 channel total, 4 users.
    $stateService->start(1, 4);

    $worker = $this->buildWorker(
      $clientFactory,
      $channelExporter,
      $stateService,
      $jsonWriter,
    );

    // Act.
    $worker->processItem([
      'channel_meta' => [
        'id' => 'C1',
        'name' => 'general',
        'type' => 'public_channel',
      ],
    ]);

    // Assert: state.
    $status = $stateService->getStatus();
    $this->assertSame(1, $status['processed']);
    $this->assertSame(3, $status['messages']);
    $this->assertSame('done', $status['status'], 'Status must be done after last channel.');
    $this->assertCount(1, $status['channels']);
    $this->assertSame('C1', $status['channels'][0]['id']);

    // Assert: manifest.json written.
    $manifestUri = 'public://slack_archive/latest/manifest.json';
    $realPath = $fileSystem->realpath($manifestUri);
    $this->assertNotFalse($realPath, 'manifest.json URI must resolve.');
    $this->assertFileExists((string) $realPath, 'manifest.json must exist on disk.');

    $decoded = json_decode((string) file_get_contents((string) $realPath), TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame(1, $decoded['schema_version']);
    $this->assertArrayHasKey('generated_at', $decoded, 'manifest must have generated_at.');
    $this->assertSame(1, $decoded['counts']['channels']);
    $this->assertSame(3, $decoded['counts']['messages']);
    $this->assertSame(4, $decoded['counts']['users']);
  }

  /**
   * Tests that processing the first of two channels leaves status 'running'.
   *
   * Given ExportStateService has been started with total=2, users=2,
   * And ChannelExporter mock returns ['messages'=>5] for channel C1,
   * When processItem is called for C1 only,
   * Then getStatus() shows processed==1, messages==5, status=='running'
   * (NOT done), and no manifest.json exists.
   */
  public function testProcessItemStillRunningWhenNotAllChannelsDone(): void {
    // Arrange: mocks.
    $mockSlackClient = $this->createMock(SlackApiClient::class);

    /** @var \Drupal\slack_portal\Service\SlackClientFactory&\PHPUnit\Framework\MockObject\MockObject $clientFactory */
    $clientFactory = $this->createMock(SlackClientFactory::class);
    $clientFactory->method('create')->willReturn($mockSlackClient);

    /** @var \Drupal\slack_portal\Service\ChannelExporter&\PHPUnit\Framework\MockObject\MockObject $channelExporter */
    $channelExporter = $this->createMock(ChannelExporter::class);
    $channelExporter
      ->expects($this->once())
      ->method('exportChannel')
      ->willReturn(['messages' => 5]);

    // Real services.
    $stateService = new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $jsonWriter = new CanonicalJsonWriter($fileSystem, new NullLogger());

    // Pre-condition: start with 2 channels total.
    $stateService->start(2, 2);

    $worker = $this->buildWorker(
      $clientFactory,
      $channelExporter,
      $stateService,
      $jsonWriter,
    );

    // Act: process only the first of 2 channels.
    $worker->processItem([
      'channel_meta' => [
        'id' => 'C1',
        'name' => 'general',
        'type' => 'public_channel',
      ],
    ]);

    // Assert: still running, not done.
    $status = $stateService->getStatus();
    $this->assertSame(1, $status['processed']);
    $this->assertSame(5, $status['messages']);
    $this->assertSame('running', $status['status'], 'Status must remain running.');

    // Assert: no manifest.json yet.
    $manifestUri = 'public://slack_archive/latest/manifest.json';
    // Manifest must not exist when only the first of two channels was done.
    $manifestRealPath = $fileSystem->realpath($manifestUri);
    if ($manifestRealPath !== FALSE) {
      $this->assertFileDoesNotExist(
        (string) $manifestRealPath,
        'manifest.json must not be written before all channels are done.',
      );
    }
    else {
      // Realpath returns FALSE when the file does not exist — that is correct.
      $this->assertTrue(TRUE, 'manifest.json does not exist as expected.');
    }
  }

}
