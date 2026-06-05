<?php

declare(strict_types=1);

/**
 * @file
 * Pure transform of a raw Slack message into the project's canonical shape.
 */

namespace Drupal\slack_portal\Service;

/**
 * Transforms a raw Slack API message into the canonical message shape.
 *
 * This is a stateless, dependency-free value transformer: it never calls the
 * network, never reads from disk, and never touches the database. It is
 * intentionally kept as a pure function so that it can be unit-tested without
 * any Drupal kernel bootstrap.
 *
 * Thread folding (attaching replies to their parent) is NOT done here;
 * that is the responsibility of ThreadFolder (ToDo A5).
 *
 * File downloading is NOT done here; local_path is always set to NULL.
 * The actual file content is written later by SlackFileDownloader (ToDo A7).
 */
final class CanonicalMessageFormatter {

  /**
   * Transforms a raw Slack message array into the canonical message shape.
   *
   * @param array<string,mixed> $rawMessage
   *   A single message object decoded from a Slack conversations.history or
   *   conversations.replies API response (json_decode with assoc=true).
   * @param string $channelId
   *   The Slack channel ID (C…, G…, D…, or mpdm-…) that owns this message.
   *
   * @return array<string,mixed>
   *   Canonical message shape as defined in docs/spec/canonical-json.md.
   *   Keys: slack_ts, channel_id, user_id, bot_id, username, type, subtype,
   *   text, posted_at, edited, thread_ts, reply_count, reactions, files.
   */
  public function format(array $rawMessage, string $channelId): array {
    $ts = (string) ($rawMessage['ts'] ?? '');

    return [
      'slack_ts'    => $ts,
      'channel_id'  => $channelId,
      'user_id'     => isset($rawMessage['user']) ? (string) $rawMessage['user'] : NULL,
      'bot_id'      => isset($rawMessage['bot_id']) ? (string) $rawMessage['bot_id'] : NULL,
      'username'    => isset($rawMessage['username']) ? (string) $rawMessage['username'] : NULL,
      'type'        => (string) ($rawMessage['type'] ?? 'message'),
      'subtype'     => isset($rawMessage['subtype']) ? (string) $rawMessage['subtype'] : NULL,
      'text'        => (string) ($rawMessage['text'] ?? ''),
      'posted_at'   => $this->tsToIso8601($ts),
      'edited'      => !empty($rawMessage['edited']),
      'thread_ts'   => isset($rawMessage['thread_ts']) ? (string) $rawMessage['thread_ts'] : NULL,
      'reply_count' => (int) ($rawMessage['reply_count'] ?? 0),
      'reactions'   => $this->formatReactions($rawMessage),
      'files'       => $this->formatFiles($rawMessage),
    ];
  }

  /**
   * Formats the reactions sub-array from a raw Slack message.
   *
   * @param array<string,mixed> $rawMessage
   *   A raw Slack message array.
   *
   * @return array<int,array<string,mixed>>
   *   Array of canonical reaction shapes: [{name, count (int), users []}].
   *   Returns [] when the raw message has no 'reactions' key.
   */
  public function formatReactions(array $rawMessage): array {
    if (empty($rawMessage['reactions'])) {
      return [];
    }

    $canonical = [];
    foreach ($rawMessage['reactions'] as $reaction) {
      $canonical[] = [
        'name'  => (string) ($reaction['name'] ?? ''),
        'count' => (int) ($reaction['count'] ?? 0),
        'users' => (array) ($reaction['users'] ?? []),
      ];
    }
    return $canonical;
  }

  /**
   * Formats the files sub-array from a raw Slack message.
   *
   * Local_path is always NULL here; it is populated later during file download
   * by SlackFileDownloader (ToDo A7).
   *
   * @param array<string,mixed> $rawMessage
   *   A raw Slack message array.
   *
   * @return array<int,array<string,mixed>>
   *   Array of canonical file ref shapes:
   *   [{id, name, mimetype, url_private, local_path}].
   *   Returns [] when the raw message has no 'files' key.
   */
  private function formatFiles(array $rawMessage): array {
    if (empty($rawMessage['files'])) {
      return [];
    }

    $canonical = [];
    foreach ($rawMessage['files'] as $file) {
      $canonical[] = [
        'id'          => (string) ($file['id'] ?? ''),
        'name'        => (string) ($file['name'] ?? ''),
        'mimetype'    => (string) ($file['mimetype'] ?? ''),
        'url_private' => (string) ($file['url_private'] ?? ''),
        'local_path'  => NULL,
      ];
    }
    return $canonical;
  }

  /**
   * Converts a Slack timestamp string to an ISO-8601 UTC datetime string.
   *
   * Slack timestamps are formatted as "seconds.microseconds" (e.g.
   * "1700000000.000100"). Only the integer-seconds part is used for the
   * datetime derivation; the microseconds sub-field preserves uniqueness
   * within a channel but is not part of the human-readable timestamp.
   *
   * @param string $ts
   *   A Slack timestamp string in "seconds.microseconds" format.
   *
   * @return string
   *   ISO-8601 UTC string, e.g. "2023-11-14T22:13:20Z".
   */
  private function tsToIso8601(string $ts): string {
    return gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
  }

}
