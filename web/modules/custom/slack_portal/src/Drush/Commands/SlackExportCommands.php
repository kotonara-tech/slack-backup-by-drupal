<?php

declare(strict_types=1);

/**
 * @file
 * Drush command for a full workspace Slack export (inline, no queue).
 */

namespace Drupal\slack_portal\Drush\Commands;

use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\ChannelExporter;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackFetcher;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Drupal\slack_portal\Service\SlackWorkspaceMapper;
use Drush\Attributes\Command;
use Drush\Attributes\Option;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command that exports the full Slack workspace inline.
 *
 * Runs inline (no queue / batch) so Drush's lack of web timeout applies.
 * Execution order per run: (1) users.list → users.json,
 * (2) conversations.list → per-channel history/replies → channels/<id>.json,
 * (3) files.list → file count for manifest,
 * (4) manifest.json.
 *
 * The Slack token is NEVER written to any archive file or log line.
 */
final class SlackExportCommands extends DrushCommands {

  /**
   * PSR-3 logger for slack_portal channel.
   *
   * Uses a private field instead of a constructor-promoted readonly property
   * because DrushCommands declares a non-readonly $logger property that
   * cannot be redeclared as readonly.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $portalLogger;

  /**
   * Constructs a SlackExportCommands instance.
   *
   * @param \Drupal\slack_portal\Service\SlackTokenProvider $tokenProvider
   *   Resolves the Slack user token from settings / env.
   * @param \Drupal\slack_portal\Service\SlackClientFactory $clientFactory
   *   Builds the authenticated JoliCode Slack API client.
   * @param \Drupal\slack_portal\Service\SlackFetcher $fetcher
   *   Cursor-paginated Slack data fetcher.
   * @param \Drupal\slack_portal\Service\ChannelExporter $channelExporter
   *   Full per-channel export pipeline.
   * @param \Drupal\slack_portal\Service\CanonicalJsonWriter $writer
   *   Writes canonical JSON to the archive tree.
   * @param \Psr\Log\LoggerInterface $portalLogger
   *   A PSR-3 logger (logger.channel.slack_portal). Named $portalLogger to
   *   avoid collision with the non-readonly $logger property in DrushCommands.
   * @param \Drupal\slack_portal\Service\SlackWorkspaceMapper $mapper
   *   Pure mapper for raw Slack user/channel arrays to canonical shapes.
   */
  public function __construct(
    private readonly SlackTokenProvider $tokenProvider,
    private readonly SlackClientFactory $clientFactory,
    private readonly SlackFetcher $fetcher,
    private readonly ChannelExporter $channelExporter,
    private readonly CanonicalJsonWriter $writer,
    LoggerInterface $portalLogger,
    private readonly SlackWorkspaceMapper $mapper = new SlackWorkspaceMapper(),
  ) {
    parent::__construct();
    $this->portalLogger = $portalLogger;
  }

  /**
   * Creates a new SlackExportCommands via DI (Drush 13 container factory).
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new SlackExportCommands instance.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('slack_portal.token_provider'),
      $container->get('slack_portal.client_factory'),
      $container->get('slack_portal.fetcher'),
      $container->get('slack_portal.channel_exporter'),
      $container->get('slack_portal.json_writer'),
      $container->get('logger.channel.slack_portal'),
      $container->get('slack_portal.workspace_mapper'),
    );
  }

  /**
   * Exports the full Slack workspace to canonical JSON and logs progress.
   *
   * @param array<string,mixed> $options
   *   Drush command options. Supported keys:
   *   - 'since': days of history to export (e.g. '90d' or '30'; default 90).
   *
   * @return int
   *   EXIT_SUCCESS (0) on success.
   */
  #[Command(name: 'slack:export')]
  #[Option(name: 'since', description: 'Days of history to export, e.g. 90d or 30 (default: 90d).')]
  public function export(array $options = ['since' => '90d']): int {
    // --- Step 1: Parse --since ---
    $sinceRaw = (string) ($options['since'] ?? '90d');
    $days = $this->parseDays($sinceRaw);
    $oldest = time() - $days * 86400;

    $this->portalLogger->notice(
      'Starting Slack export: since={days}d, oldest_ts={oldest}',
      ['days' => $days, 'oldest' => $oldest],
    );

    // --- Step 2: Build API client ---
    $token = $this->tokenProvider->getToken();
    $client = $this->clientFactory->create($token, $this->tokenProvider->getMaxRetries());

    // --- Step 3: Export users ---
    $rawUsers = [];
    foreach ($this->fetcher->fetchUsers($client) as $rawUser) {
      $rawUsers[] = $rawUser;
    }
    $canonicalUsers = array_map(
      fn(array $u): array => $this->mapper->toCanonicalUser($u),
      $rawUsers,
    );
    $this->writer->writeUsers($canonicalUsers);
    $userCount = count($canonicalUsers);
    $this->portalLogger->notice('Exported {count} users.', ['count' => $userCount]);

    // --- Step 4: Export channels ---
    $channelCount = 0;
    $messageTotal = 0;
    $channelsIndex = [];

    foreach ($this->fetcher->fetchChannels($client, 'public_channel,private_channel,mpim,im') as $rawChannel) {
      $channelMeta = $this->mapper->toChannelMeta($rawChannel);
      $channelId = (string) $channelMeta['id'];
      $channelName = (string) $channelMeta['name'];

      $this->portalLogger->notice(
        'Exporting channel {name} ({id})',
        ['name' => $channelName, 'id' => $channelId],
      );

      $stats = $this->channelExporter->exportChannel($client, $token, $channelMeta, $oldest);
      $messageTotal += (int) ($stats['messages'] ?? 0);
      $channelCount++;

      $channelsIndex[] = [
        'id' => $channelId,
        'name' => $channelName,
        'type' => $channelMeta['type'],
        'file' => "channels/{$channelId}.json",
      ];
    }

    $this->portalLogger->notice(
      'Exported {channels} channels, {messages} top-level messages.',
      ['channels' => $channelCount, 'messages' => $messageTotal],
    );

    // --- Step 5: Count files (files.list pass) ---
    $fileCount = 0;
    // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
    foreach ($this->fetcher->fetchFiles($client, $oldest) as $_file) {
      $fileCount++;
    }

    // --- Step 6: Write manifest ---
    $this->writer->writeManifest([
      'schema_version' => 1,
      'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
      'since_days' => $days,
      'oldest_ts' => (string) $oldest,
      'counts' => [
        'channels' => $channelCount,
        'messages' => $messageTotal,
        'users' => $userCount,
        'files' => $fileCount,
      ],
      'channels' => $channelsIndex,
    ]);

    $this->portalLogger->notice('Slack export complete.');

    return self::EXIT_SUCCESS;
  }

  /**
   * Parses the --since option string to an integer number of days.
   *
   * Accepts:
   * - '90d' → 90
   * - '30' → 30
   * - Any other non-numeric string → default 90.
   *
   * @param string $since
   *   The raw --since option value.
   *
   * @return int
   *   The number of days as a positive integer.
   */
  private function parseDays(string $since): int {
    $normalized = rtrim($since, 'dD');
    if (is_numeric($normalized) && (int) $normalized > 0) {
      return (int) $normalized;
    }
    return 90;
  }

}
