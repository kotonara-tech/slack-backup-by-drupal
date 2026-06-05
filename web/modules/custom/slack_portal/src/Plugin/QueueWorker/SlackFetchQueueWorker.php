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
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\SecretMasker;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes a single Slack channel export via the background queue.
 *
 * Each queue item carries one channel's metadata, an optional oldest
 * timestamp, and an attempt counter. The worker exports the channel and
 * records state. On a per-channel error it does NOT re-throw: while attempts
 * remain (< MAX_ATTEMPTS) it re-enqueues the channel with an incremented
 * attempt (bounded retry, status stays 'running'); once attempts are exhausted
 * it records a terminal per-channel failure. When every channel is accounted
 * for (success or terminal failure) it writes the final manifest and calls
 * finish(), which yields 'done' (no failures) or 'error' (some failed).
 *
 * SECRETS RULE: The Slack token must never appear in log messages or in any
 * error string stored in State. Log messages are passed through SecretMasker
 * and failure strings contain only the (non-sensitive) channel id.
 */
#[QueueWorker(
  id: 'slack_portal_fetch',
  title: new TranslatableMarkup('Slack Portal channel fetch'),
  cron: ['time' => 60],
)]
final class SlackFetchQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The queue this worker drains and re-enqueues retries into.
   */
  private const QUEUE_NAME = 'slack_portal_fetch';

  /**
   * Maximum per-channel processing attempts before recording a failure.
   */
  private const MAX_ATTEMPTS = 3;

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
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory, used to re-enqueue a channel for a bounded retry.
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
    private readonly QueueFactory $queueFactory,
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
      $container->get('queue'),
    );
  }

  /**
   * Processes one channel export queue item.
   *
   * @param mixed $data
   *   Expected shape: ['channel_meta'=>array<string,mixed>, 'oldest'=>?int,
   *   'attempt'=>?int]. 'channel_meta' must contain at minimum 'id'.
   */
  public function processItem($data): void {
    /** @var array<string,mixed> $channelMeta */
    $channelMeta = (array) ($data['channel_meta'] ?? []);
    $oldest = isset($data['oldest']) ? (int) $data['oldest'] : NULL;
    $attempt = (int) ($data['attempt'] ?? 0);
    $channelId = (string) ($channelMeta['id'] ?? 'unknown');

    try {
      $token = $this->tokenProvider->getToken();
      $client = $this->clientFactory->create($token, $this->tokenProvider->getMaxRetries());
      $result = $this->channelExporter->exportChannel(
        $client,
        $token,
        $channelMeta,
        $oldest,
      );

      $channelName = (string) ($channelMeta['name'] ?? $channelId);
      $channelType = (string) ($channelMeta['type'] ?? 'public_channel');

      $indexEntry = [
        'id' => $channelId,
        'name' => $channelName,
        'type' => $channelType,
        'file' => "channels/{$channelId}.json",
      ];

      $this->stateService->recordChannel(
        $indexEntry,
        (int) ($result['messages'] ?? 0),
        (int) ($result['files'] ?? 0),
      );
    }
    catch (\Throwable $e) {
      // Log a token-free, redacted message (defence-in-depth: the exception
      // text should never carry a token, but mask it anyway).
      $this->logger->error(
        'Slack channel export failed for channel {channel_id} (attempt {attempt}): {message}',
        [
          'channel_id' => $channelId,
          'attempt' => $attempt + 1,
          'message' => SecretMasker::mask($e->getMessage()),
        ],
      );

      if ($attempt + 1 < self::MAX_ATTEMPTS) {
        // Bounded retry: re-enqueue with an incremented attempt and do NOT
        // re-throw, so Drupal consumes the current item. The run stays
        // 'running' (this channel is neither processed nor failed yet).
        $this->queueFactory->get(self::QUEUE_NAME)->createItem([
          'channel_meta' => $channelMeta,
          'oldest' => $oldest,
          'attempt' => $attempt + 1,
        ]);
      }
      else {
        // Retries exhausted: record a terminal per-channel failure with a
        // pre-masked, token-free message so the run can still complete.
        $this->stateService->recordFailure(
          $channelId,
          "channel {$channelId} export failed after " . self::MAX_ATTEMPTS . ' attempts',
        );
      }
    }

    // Finalise once every channel is accounted for (success or terminal
    // failure). A re-enqueue leaves the run incomplete, so this is a no-op
    // in that case.
    if ($this->stateService->isComplete()) {
      $this->writeManifestAndFinish();
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
        'channels' => (int) ($s['processed'] ?? 0),
        'failed' => (int) ($s['failed'] ?? 0),
        'messages' => (int) ($s['messages'] ?? 0),
        'users' => (int) ($s['users'] ?? 0),
        'files' => (int) ($s['files'] ?? 0),
      ],
      'channels' => (array) ($s['channels'] ?? []),
    ];
    $this->jsonWriter->writeManifest($manifest);
    $this->stateService->finish();
  }

}
