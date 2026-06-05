<?php

declare(strict_types=1);

/**
 * @file
 * Pure logic for folding Slack thread replies onto their parent messages.
 */

namespace Drupal\slack_portal\Service;

/**
 * Folds Slack thread replies onto their parent (thread-root) messages.
 *
 * This service is stateless and has no external dependencies: it never calls
 * the network, never reads from disk, and never touches the database. It is
 * intentionally a pure function so that it can be unit-tested without any
 * Drupal kernel bootstrap.
 *
 * Classification of a canonical message (from CanonicalMessageFormatter):
 *   - Thread root / parent: thread_ts === slack_ts
 *   - Reply:               thread_ts !== null && thread_ts !== slack_ts
 *   - Standalone:          thread_ts === null
 *
 * After fold():
 *   - Parents and standalones remain at top level (original relative order).
 *   - Replies are removed from top level and attached to their parent under
 *     the 'replies' key, sorted ascending by slack_ts.
 *   - Only thread-root parents receive a 'replies' array; standalone messages
 *     are returned without any modification.
 *   - Orphan replies (parent absent from the input) are surfaced at top level
 *     so that no data is silently lost.
 *   - The original input messages are not mutated.
 */
final class ThreadFolder {

  /**
   * Folds thread replies onto their parent messages.
   *
   * @param array<int,array<string,mixed>> $messages
   *   Flat list of canonical messages (output of CanonicalMessageFormatter).
   *
   * @return array<int,array<string,mixed>>
   *   Top-level list: parents (with 'replies' populated) + standalones +
   *   orphan replies.  Replies that were folded are absent from this list.
   */
  public function fold(array $messages): array {
    // --- Pass 1: index thread-root parents by slack_ts. ---
    /** @var array<string,array<string,mixed>> $parents */
    $parents = [];
    foreach ($messages as $message) {
      $slackTs = (string) $message['slack_ts'];
      $threadTs = isset($message['thread_ts']) ? (string) $message['thread_ts'] : NULL;

      if ($threadTs !== NULL && $threadTs === $slackTs) {
        // Thread root: initialise the replies bucket.
        $copy = $message;
        $copy['replies'] = [];
        $parents[$slackTs] = $copy;
      }
    }

    // --- Pass 2: collect replies per parent and detect orphans. ---
    /** @var array<string,list<array<string,mixed>>> $replyBuckets */
    $replyBuckets = [];
    /** @var list<array<string,mixed>> $orphans */
    $orphans = [];

    foreach ($messages as $message) {
      $slackTs = (string) $message['slack_ts'];
      $threadTs = isset($message['thread_ts']) ? (string) $message['thread_ts'] : NULL;

      if ($threadTs === NULL || $threadTs === $slackTs) {
        // Standalone or parent — handled separately.
        continue;
      }

      // It is a reply: attach to parent or treat as orphan.
      if (isset($parents[$threadTs])) {
        $replyBuckets[$threadTs][] = $message;
      }
      else {
        $orphans[] = $message;
      }
    }

    // --- Sort replies within each bucket ascending by slack_ts. ---
    foreach ($replyBuckets as $parentTs => $replies) {
      usort($replies, static function (array $a, array $b): int {
        return strcmp((string) $a['slack_ts'], (string) $b['slack_ts']);
      });
      $parents[$parentTs]['replies'] = array_values($replies);
    }

    // --- Pass 3: build top-level output in original message order. ---
    /** @var list<array<string,mixed>> $topLevel */
    $topLevel = [];
    foreach ($messages as $message) {
      $slackTs = (string) $message['slack_ts'];
      $threadTs = isset($message['thread_ts']) ? (string) $message['thread_ts'] : NULL;

      if ($threadTs !== NULL && $threadTs !== $slackTs) {
        // Pure reply — skip; already folded into parent.
        continue;
      }

      if ($threadTs !== NULL && $threadTs === $slackTs) {
        // Thread root: emit the enriched copy (with replies).
        $topLevel[] = $parents[$slackTs];
      }
      else {
        // Standalone: emit as-is (no 'replies' key added).
        $topLevel[] = $message;
      }
    }

    // Append orphans at the end so data is never silently lost.
    foreach ($orphans as $orphan) {
      $topLevel[] = $orphan;
    }

    return array_values($topLevel);
  }

}
