<?php

declare(strict_types=1);

/**
 * @file
 * Triggers a queued Slack workspace export via the HTTP API.
 */

namespace Drupal\slack_portal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Enumerates the Slack workspace and dispatches per-channel queue items.
 *
 * Called by SlackPortalApiController on POST /api/slack-portal/export.
 * Execution order:
 *  1. Resolve token and build client.
 *  2. Fetch and write users (canonical JSON).
 *  3. Fetch channels, derive oldest timestamp.
 *  4. Transition ExportStateService to 'running'.
 *  5. Enqueue one item per channel for the background QueueWorker.
 *  6. Return a summary array.
 *
 * SECRETS RULE: The Slack token must never appear in log messages, exception
 * messages, return values, or queue item data. All log lines are token-free.
 *
 * Non-final to allow createMock() in SlackPortalApiControllerTest.
 */
class ExportTrigger {

  /**
   * Constructs an ExportTrigger.
   *
   * @param \Drupal\slack_portal\Service\SlackTokenProvider $tokenProvider
   *   Resolves the Slack user token from Settings / env / encrypted State.
   * @param \Drupal\slack_portal\Service\SlackClientFactory $clientFactory
   *   Builds the authenticated JoliCode Slack API client.
   * @param \Drupal\slack_portal\Service\SlackFetcher $fetcher
   *   Cursor-paginated Slack data fetcher.
   * @param \Drupal\slack_portal\Service\SlackWorkspaceMapper $mapper
   *   Pure mapper for raw Slack user/channel arrays.
   * @param \Drupal\slack_portal\Service\CanonicalJsonWriter $writer
   *   Writes canonical JSON to the archive tree.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   Drupal queue factory for the 'slack_portal_fetch' queue.
   * @param \Drupal\slack_portal\Service\ExportStateService $stateService
   *   Tracks and updates the background export status.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Provides the current Unix timestamp.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (logger.channel.slack_portal).
   */
  public function __construct(
    private readonly SlackTokenProvider $tokenProvider,
    private readonly SlackClientFactory $clientFactory,
    private readonly SlackFetcher $fetcher,
    private readonly SlackWorkspaceMapper $mapper,
    private readonly CanonicalJsonWriter $writer,
    private readonly QueueFactory $queueFactory,
    private readonly ExportStateService $stateService,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Triggers a queued Slack workspace export.
   *
   * Enumerates users and channels, writes users.json, transitions state to
   * 'running', and enqueues one item per channel. No token value appears in
   * any log line or in the returned array.
   *
   * @return array<string,int>
   *   Summary with keys 'queued' (channel count) and 'users' (user count).
   *
   * @throws \Throwable
   *   Re-throws any exception from token resolution or Slack API calls so
   *   that the controller can catch it and return an appropriate HTTP error.
   *   The caller (SlackPortalApiController) is responsible for masking any
   *   sensitive text before exposing it to the client.
   */
  public function trigger(): array {
    // Guard against a duplicate run: a second POST (another tab, a retry, a
    // direct call) must not reset counters mid-run or double-enqueue channels.
    if ($this->stateService->getStatus()['status'] === 'running') {
      throw new \RuntimeException('A Slack export is already running.');
    }

    $token = $this->tokenProvider->getToken();
    $client = $this->clientFactory->create($token);
    $days = $this->tokenProvider->getSinceDays();
    $oldest = $this->time->getCurrentTime() - $days * 86400;

    // --- Step 1: Fetch and write users ---
    $users = [];
    foreach ($this->fetcher->fetchUsers($client) as $rawUser) {
      $users[] = $this->mapper->toCanonicalUser($rawUser);
    }
    $this->writer->writeUsers($users);
    $userCount = count($users);

    $this->logger->notice(
      'ExportTrigger: fetched {count} users.',
      ['count' => $userCount],
    );

    // --- Step 2: Fetch channels ---
    $channels = [];
    foreach ($this->fetcher->fetchChannels($client, 'public_channel,private_channel,mpim,im') as $rawChannel) {
      $channels[] = $this->mapper->toChannelMeta($rawChannel);
    }

    // --- Step 3: Start state ---
    $channelCount = count($channels);
    $this->stateService->start($channelCount, $userCount);

    // --- Step 4: Enqueue one item per channel (or finalise if empty) ---
    if ($channels === []) {
      // No exportable channels: there will be no queue worker run to write the
      // manifest, so finalise inline instead of leaving status on 'running'.
      $this->writer->writeManifest([
        'schema_version' => 1,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getCurrentTime()),
        'counts' => [
          'channels' => 0,
          'failed' => 0,
          'messages' => 0,
          'users' => $userCount,
          'files' => 0,
        ],
        'channels' => [],
      ]);
      $this->stateService->finish();
      $this->logger->notice('ExportTrigger: no channels to export; finished.');
    }
    else {
      $queue = $this->queueFactory->get('slack_portal_fetch');
      foreach ($channels as $channelMeta) {
        $queue->createItem([
          'channel_meta' => $channelMeta,
          'oldest' => $oldest,
          'attempt' => 0,
        ]);
      }
      $this->logger->notice(
        'ExportTrigger: queued {channels} channels for {days}d export.',
        ['channels' => $channelCount, 'days' => $days],
      );
    }

    return [
      'queued' => $channelCount,
      'users' => $userCount,
    ];
  }

}
