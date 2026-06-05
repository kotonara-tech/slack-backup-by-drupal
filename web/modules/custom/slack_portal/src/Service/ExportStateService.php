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
 * through: idle → running → done (or error). Callers must pre-mask any
 * sensitive values before passing to fail(); this service never stores tokens.
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
      'messages' => 0,
      'users' => $userCount,
      'channels' => [],
      'started_at' => $this->time->getCurrentTime(),
      'finished_at' => NULL,
      'last_error' => NULL,
    ]);
  }

  /**
   * Records a successfully processed channel.
   *
   * Increments the processed counter, accumulates message count, and appends
   * the channel index entry to the channels list.
   *
   * @param array<string,mixed> $indexEntry
   *   The channel index entry (e.g. id, name, type, file).
   * @param int $messageCount
   *   The number of top-level messages exported from this channel.
   */
  public function recordChannel(array $indexEntry, int $messageCount): void {
    $status = $this->getStatus();
    $status['processed']++;
    $status['messages'] += $messageCount;
    $status['channels'][] = $indexEntry;
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Returns true when all queued channels have been processed.
   *
   * @return bool
   *   TRUE when total > 0 and processed >= total.
   */
  public function isComplete(): bool {
    $status = $this->getStatus();
    return $status['total'] > 0 && $status['processed'] >= $status['total'];
  }

  /**
   * Transitions export status to 'done' and records the finish timestamp.
   */
  public function finish(): void {
    $status = $this->getStatus();
    $status['status'] = 'done';
    $status['finished_at'] = $this->time->getCurrentTime();
    $this->state->set(self::STATE_KEY, $status);
  }

  /**
   * Transitions export status to 'error' and stores a pre-masked error string.
   *
   * The caller is responsible for masking any sensitive values (e.g. tokens,
   * PII) before passing $maskedError. This service never masks automatically.
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
   *   The current status array with keys: status, total, processed, messages,
   *   users, channels, started_at, finished_at, last_error.
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
      'messages' => 0,
      'users' => 0,
      'channels' => [],
      'started_at' => NULL,
      'finished_at' => NULL,
      'last_error' => NULL,
    ];
  }

}
