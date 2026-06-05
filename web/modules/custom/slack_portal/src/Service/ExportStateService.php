<?php

declare(strict_types=1);

/**
 * @file
 * Tracks export status (idle/running/done/error) via Drupal State API.
 */

namespace Drupal\slack_portal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Wraps Drupal State for the background Slack export status.
 *
 * A single State key 'slack_portal.export_status' holds the full status array.
 * All mutations are atomic (get → mutate → set). The status field cycles
 * through: idle → running → done | error. A per-channel failure does NOT flip
 * the status to 'error' immediately; instead it is counted (recordFailure) and
 * the terminal status is decided once, at finish(): 'error' if any channel
 * ultimately failed, otherwise 'done'. recordChannel/recordFailure are
 * idempotent by channel id so queue redelivery (crash / lease expiry) cannot
 * double-count. Callers must pre-mask any sensitive values before passing to
 * recordFailure()/fail(); this service never stores tokens.
 *
 * Thread safety: Drupal's State API is not transaction-isolated, but Drupal
 * queue workers run single-threaded by default (one worker at a time), so
 * the lack of locking is acceptable for this use case.
 */
final class ExportStateService {

  /**
   * The Drupal State key used to store the export status.
   */
  private const STATE_KEY = 'slack_portal.export_status';

  /**
   * Constructs an ExportStateService.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The Drupal datetime time service (provides current timestamp).
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Transitions export status to 'running' and initialises all counters.
   *
   * @param int $totalChannels
   *   The total number of channels queued for export.
   * @param int $userCount
   *   The number of users exported in the initial pass.
   */
  public function start(int $totalChannels, int $userCount): void {
    $this->state->set(self::STATE_KEY, [
      'status' => 'running',
      'total' => $totalChannels,
      'processed' => 0,
      'failed' => 0,
      'messages' => 0,
      'users' => $userCount,
      'files' => 0,
      'channels' => [],
      'failed_channels' => [],
      'started_at' => $this->time->getCurrentTime(),
      'finished_at' => NULL,
      'last_error' => NULL,
    ]);
  }

  /**
   * Records a successfully processed channel (idempotent by channel id).
   *
   * Increments the processed/messages/files counters and appends the channel
   * index entry. If the channel id has already been recorded (e.g. the queue
   * item was re-delivered after a crash), this is a no-op so counts never
   * exceed the real total.
   *
   * @param array<string,mixed> $indexEntry
   *   The channel index entry (must include 'id'; also name, type, file).
   * @param int $messageCount
   *   The number of top-level messages exported from this channel.
   * @param int $fileCount
   *   The number of files downloaded from this channel.
   */
  public function recordChannel(array $indexEntry, int $messageCount, int $fileCount = 0): void {
    $status = $this->getStatus();
    $channelId = (string) ($indexEntry['id'] ?? '');

    // Idempotency: skip if this channel id was already recorded.
    foreach ($status['channels'] as $existing) {
      if ((string) ($existing['id'] ?? '') === $channelId) {
        return;
      }
    }

    $status['processed']++;
    $status['messages'] += $messageCount;
    $status['files'] += $fileCount;
    $status['channels'][] = $indexEntry;
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Records a channel that failed after exhausting retries (idempotent by id).
   *
   * Increments the failed counter and stores the pre-masked error, but keeps
   * the status 'running' — the terminal status is decided at finish(). The
   * caller MUST pre-mask the error string (no tokens / PII).
   *
   * @param string $channelId
   *   The Slack channel id that failed.
   * @param string $maskedError
   *   A human-readable, pre-masked error description. MUST NOT contain tokens.
   */
  public function recordFailure(string $channelId, string $maskedError): void {
    $status = $this->getStatus();

    // Idempotency: skip if this channel id was already marked failed.
    if (in_array($channelId, $status['failed_channels'], TRUE)) {
      return;
    }

    $status['failed']++;
    $status['failed_channels'][] = $channelId;
    $status['last_error'] = $maskedError;
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Returns true when every queued channel has succeeded or failed.
   *
   * @return bool
   *   TRUE when total > 0 and processed + failed >= total.
   */
  public function isComplete(): bool {
    $status = $this->getStatus();
    return $status['total'] > 0 && ($status['processed'] + $status['failed']) >= $status['total'];
  }

  /**
   * Transitions to the terminal status and records the finish timestamp.
   *
   * The terminal status is 'error' when at least one channel failed, otherwise
   * 'done'. On a clean 'done' the last_error is cleared.
   */
  public function finish(): void {
    $status = $this->getStatus();
    $status['finished_at'] = $this->time->getCurrentTime();
    if (($status['failed'] ?? 0) > 0) {
      $status['status'] = 'error';
    }
    else {
      $status['status'] = 'done';
      $status['last_error'] = NULL;
    }
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Transitions export status to 'error' and stores a pre-masked error string.
   *
   * Used for a fatal whole-export failure (e.g. credential resolution). For
   * per-channel failures use recordFailure() instead. The caller is
   * responsible for masking any sensitive values before passing $maskedError.
   *
   * @param string $maskedError
   *   A human-readable, pre-masked error description. MUST NOT contain tokens.
   */
  public function fail(string $maskedError): void {
    $status = $this->getStatus();
    $status['status'] = 'error';
    $status['last_error'] = $maskedError;
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Resets the export status to the idle shape (all zeros / nulls).
   */
  public function reset(): void {
    $this->state->set(self::STATE_KEY, $this->idleShape());
  }

  /**
   * Returns the current export status array.
   *
   * Defaults to the full idle shape when no status has been stored yet.
   *
   * @return array<string,mixed>
   *   The current status array with keys: status, total, processed, failed,
   *   messages, users, files, channels, failed_channels, started_at,
   *   finished_at, last_error.
   */
  public function getStatus(): array {
    $stored = $this->state->get(self::STATE_KEY);
    if (!is_array($stored)) {
      return $this->idleShape();
    }
    return $stored;
  }

  /**
   * Returns the canonical idle status shape.
   *
   * @return array<string,mixed>
   *   The idle status with all counters zeroed and timestamps null.
   */
  private function idleShape(): array {
    return [
      'status' => 'idle',
      'total' => 0,
      'processed' => 0,
      'failed' => 0,
      'messages' => 0,
      'users' => 0,
      'files' => 0,
      'channels' => [],
      'failed_channels' => [],
      'started_at' => NULL,
      'finished_at' => NULL,
      'last_error' => NULL,
    ];
  }

}
