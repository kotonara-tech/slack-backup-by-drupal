<?php

declare(strict_types=1);

/**
 * @file
 * Queue worker that processes a single Slack channel export item.
 */

namespace Drupal\slack_portal\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes a single Slack channel export via the background queue.
 *
 * Each queue item carries one channel's metadata and an optional oldest
 * timestamp. The worker exports the channel, records state, and — when all
 * channels are complete — writes the final manifest and transitions status
 * to 'done'. On error the item is re-queued by Drupal's queue system
 * (the worker rethrows after recording the failure in ExportStateService).
 *
 * SECRETS RULE: The Slack token must never appear in log messages or in
 * the error string passed to ExportStateService::fail(). All error strings
 * stored in State are pre-masked by this class.
 */
#[QueueWorker(
  id: 'slack_portal_fetch',
  title: new TranslatableMarkup('Slack Portal channel fetch'),
  cron: ['time' => 60],
)]
final class SlackFetchQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a SlackFetchQueueWorker.
   *
   * @param array<string,mixed> $configuration
   *   Plugin configuration array.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\slack_portal\Service\SlackTokenProvider $tokenProvider
   *   Resolves the Slack user token from settings / env / encrypted State.
   * @param \Drupal\slack_portal\Service\SlackClientFactory $clientFactory
   *   Builds the Slack API client with Bearer auth + retry backoff.
   * @param \Drupal\slack_portal\Service\ChannelExporter $channelExporter
   *   Runs the full per-channel export pipeline.
   * @param \Drupal\slack_portal\Service\CanonicalJsonWriter $jsonWriter
   *   Writes canonical JSON (manifest) to the archive tree.
   * @param \Drupal\slack_portal\Service\ExportStateService $stateService
   *   Tracks and updates the background export status.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Provides the current Unix timestamp for generated_at in the manifest.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (logger.channel.slack_portal).
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly SlackTokenProvider $tokenProvider,
    private readonly SlackClientFactory $clientFactory,
    private readonly ChannelExporter $channelExporter,
    private readonly CanonicalJsonWriter $jsonWriter,
    private readonly ExportStateService $stateService,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('slack_portal.token_provider'),
      $container->get('slack_portal.client_factory'),
      $container->get('slack_portal.channel_exporter'),
      $container->get('slack_portal.json_writer'),
      $container->get('slack_portal.export_state'),
      $container->get('datetime.time'),
      $container->get('logger.channel.slack_portal'),
    );
  }

  /**
   * Processes one channel export queue item.
   *
   * @param mixed $data
   *   Expected shape: ['channel_meta'=>array<string,mixed>, 'oldest'=>?int].
   *   'channel_meta' must contain at minimum 'id', 'name', and 'type'.
   *
   * @throws \Throwable
   *   Re-thrown after recording the masked error in ExportStateService so that
   *   Drupal's queue system can retry or move to a failed-items queue.
   */
  public function processItem($data): void {
    /** @var array<string,mixed> $channelMeta */
    $channelMeta = (array) ($data['channel_meta'] ?? []);
    $oldest = isset($data['oldest']) ? (int) $data['oldest'] : NULL;

    try {
      $token = $this->tokenProvider->getToken();
      $client = $this->clientFactory->create($token);
      $result = $this->channelExporter->exportChannel(
        $client,
        $token,
        $channelMeta,
        $oldest,
      );

      $channelId = (string) ($channelMeta['id'] ?? '');
      $channelName = (string) ($channelMeta['name'] ?? $channelId);
      $channelType = (string) ($channelMeta['type'] ?? 'public_channel');

      $indexEntry = [
        'id' => $channelId,
        'name' => $channelName,
        'type' => $channelType,
        'file' => "channels/{$channelId}.json",
      ];

      $this->stateService->recordChannel($indexEntry, (int) ($result['messages'] ?? 0));

      if ($this->stateService->isComplete()) {
        $this->writeManifestAndFinish();
      }
    }
    catch (\Throwable $e) {
      $channelId = (string) ($channelMeta['id'] ?? 'unknown');
      // Pre-mask: only the channel ID (non-sensitive) is included.
      // The token is intentionally omitted from this string.
      $maskedError = "channel {$channelId} export failed";
      $this->stateService->fail($maskedError);

      $this->logger->error(
        'Slack channel export failed for channel {channel_id}: {message}',
        [
          // Log the channel ID but NOT the token.
          'channel_id' => $channelId,
          'message' => $e->getMessage(),
        ],
      );

      // Re-throw so Drupal's queue system handles retry / dead-letter.
      throw $e;
    }
  }

  /**
   * Writes the final manifest JSON and transitions the state to 'done'.
   */
  private function writeManifestAndFinish(): void {
    $s = $this->stateService->getStatus();
    $manifest = [
      'schema_version' => 1,
      'generated_at' => gmdate(
        'Y-m-d\TH:i:s\Z',
        $this->time->getCurrentTime(),
      ),
      'counts' => [
        'channels' => (int) ($s['total'] ?? 0),
        'messages' => (int) ($s['messages'] ?? 0),
        'users' => (int) ($s['users'] ?? 0),
        'files' => 0,
      ],
      'channels' => (array) ($s['channels'] ?? []),
    ];
    $this->jsonWriter->writeManifest($manifest);
    $this->stateService->finish();
  }

}
