<?php

declare(strict_types=1);

/**
 * @file
 * Pure transform: one channel's folded messages -> flat migrate-ready rows.
 */

namespace Drupal\slack_portal\Service;

/**
 * Flattens a canonical channel's messages into deduped migrate source rows.
 *
 * Each top-level message and each nested reply becomes one row, deduplicated
 * by slack_ts within the channel (Slack thread_broadcast replies appear both
 * at top level and inside the parent's replies[]). Rows carry the channel's
 * id and type, a reaction_total, and file_ids for locally-stored files only.
 * posted_at is intentionally not emitted; the migration derives it from
 * slack_ts via the slack_timestamp_to_datetime process plugin.
 *
 * Pure and dependency-free: no I/O, no network, no database. Unit-testable.
 */
final class CanonicalMessageFlattener {

  /**
   * Flattens one decoded channel JSON document into source rows.
   *
   * @param array<string,mixed> $channelData
   *   Decoded channel file: ['channel' => [...], 'messages' => [...]].
   *
   * @return array<int,array<string,mixed>>
   *   Deduped migrate source rows (see class docblock for the row shape).
   */
  public function flattenChannel(array $channelData): array {
    $channel = is_array($channelData['channel'] ?? NULL) ? $channelData['channel'] : [];
    $channelId = (string) ($channel['id'] ?? '');
    $channelType = (string) ($channel['type'] ?? '');
    $messages = is_array($channelData['messages'] ?? NULL) ? $channelData['messages'] : [];

    $rows = [];
    $seen = [];
    foreach ($messages as $message) {
      if (!is_array($message)) {
        continue;
      }
      $this->emit($message, $channelId, $channelType, $rows, $seen);
      $replies = is_array($message['replies'] ?? NULL) ? $message['replies'] : [];
      foreach ($replies as $reply) {
        if (is_array($reply)) {
          $this->emit($reply, $channelId, $channelType, $rows, $seen);
        }
      }
    }
    return $rows;
  }

  /**
   * Appends one row for $message unless its slack_ts was already seen.
   *
   * @param array<string,mixed> $message
   *   A canonical message (or reply) object.
   * @param string $channelId
   *   The owning channel id.
   * @param string $channelType
   *   The owning channel type.
   * @param array<int,array<string,mixed>> $rows
   *   Accumulator of emitted rows (by reference).
   * @param array<string,true> $seen
   *   Set of slack_ts already emitted within this channel (by reference).
   */
  private function emit(array $message, string $channelId, string $channelType, array &$rows, array &$seen): void {
    $slackTs = (string) ($message['slack_ts'] ?? '');
    if ($slackTs === '' || isset($seen[$slackTs])) {
      return;
    }
    $seen[$slackTs] = TRUE;
    $rows[] = $this->toRow($message, $slackTs, $channelId, $channelType);
  }

  /**
   * Builds a single migrate source row from a canonical message.
   *
   * @param array<string,mixed> $message
   *   A canonical message (or reply) object.
   * @param string $slackTs
   *   The message slack_ts (already cast).
   * @param string $channelId
   *   The owning channel id.
   * @param string $channelType
   *   The owning channel type.
   *
   * @return array<string,mixed>
   *   The migrate source row.
   */
  private function toRow(array $message, string $slackTs, string $channelId, string $channelType): array {
    $reactions = is_array($message['reactions'] ?? NULL) ? $message['reactions'] : [];
    $reactionTotal = 0;
    foreach ($reactions as $reaction) {
      if (is_array($reaction)) {
        $reactionTotal += (int) ($reaction['count'] ?? 0);
      }
    }

    $fileIds = [];
    $files = is_array($message['files'] ?? NULL) ? $message['files'] : [];
    foreach ($files as $file) {
      if (!is_array($file)) {
        continue;
      }
      $localPath = $file['local_path'] ?? NULL;
      if ($localPath !== NULL && $localPath !== '') {
        $fileIds[] = (string) ($file['id'] ?? '');
      }
    }

    return [
      'slack_ts' => $slackTs,
      'channel_id' => $channelId,
      'channel_type' => $channelType,
      'user_id' => isset($message['user_id']) ? (string) $message['user_id'] : NULL,
      'bot_id' => isset($message['bot_id']) ? (string) $message['bot_id'] : NULL,
      'username' => isset($message['username']) ? (string) $message['username'] : NULL,
      'type' => (string) ($message['type'] ?? 'message'),
      'subtype' => isset($message['subtype']) ? (string) $message['subtype'] : NULL,
      'text' => (string) ($message['text'] ?? ''),
      'edited' => (bool) ($message['edited'] ?? FALSE),
      'thread_ts' => isset($message['thread_ts']) ? (string) $message['thread_ts'] : NULL,
      'reply_count' => (int) ($message['reply_count'] ?? 0),
      'reactions' => $reactions,
      'reaction_total' => $reactionTotal,
      'file_ids' => $fileIds,
    ];
  }

}
