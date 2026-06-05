<?php

declare(strict_types=1);

/**
 * @file
 * Pure mapper: raw Slack API shapes → canonical user/channel arrays.
 */

namespace Drupal\slack_portal\Service;

/**
 * Converts raw Slack API arrays to canonical user and channel metadata shapes.
 *
 * This is a pure, stateless value transformer with no I/O or dependencies.
 * The mapping logic is the single source of truth shared by SlackExportCommands
 * (inline Drush export) and ExportTrigger (queue-based HTTP API trigger).
 *
 * SECRETS RULE: This mapper never receives or emits Slack tokens. The token
 * is managed solely by SlackTokenProvider and must not appear in any output.
 */
final class SlackWorkspaceMapper {

  /**
   * Maps a raw users.list member array to the canonical user shape.
   *
   * @param array<string,mixed> $raw
   *   A raw user element from users.list members[].
   *
   * @return array<string,mixed>
   *   Canonical user shape with keys: id, name, real_name, display_name,
   *   email, is_bot, deleted, avatar.
   */
  public function toCanonicalUser(array $raw): array {
    /** @var array<string,mixed> $profile */
    $profile = isset($raw['profile']) && is_array($raw['profile']) ? $raw['profile'] : [];

    return [
      'id' => isset($raw['id']) ? (string) $raw['id'] : NULL,
      'name' => isset($raw['name']) ? (string) $raw['name'] : NULL,
      'real_name' => isset($raw['real_name']) ? (string) $raw['real_name'] : NULL,
      'display_name' => isset($profile['display_name']) ? (string) $profile['display_name'] : NULL,
      'email' => isset($profile['email']) ? (string) $profile['email'] : NULL,
      'is_bot' => (bool) ($raw['is_bot'] ?? FALSE),
      'deleted' => (bool) ($raw['deleted'] ?? FALSE),
      'avatar' => isset($profile['image_192']) ? (string) $profile['image_192'] : NULL,
    ];
  }

  /**
   * Builds canonical channel metadata from a raw conversations.list channel.
   *
   * The channel type is derived from the boolean flags is_im / is_mpim /
   * is_private (priority: im > mpim > private > public). For IMs the name
   * falls back to the user field then to the channel id when name is absent.
   *
   * @param array<string,mixed> $raw
   *   A raw channel element from conversations.list channels[].
   *
   * @return array<string,mixed>
   *   Canonical channel metadata with keys: id, name, type, is_private,
   *   is_im, is_mpim, members, topic, purpose.
   */
  public function toChannelMeta(array $raw): array {
    $id = (string) ($raw['id'] ?? '');
    $isIm = (bool) ($raw['is_im'] ?? FALSE);
    $isMpim = (bool) ($raw['is_mpim'] ?? FALSE);
    $isPrivate = (bool) ($raw['is_private'] ?? FALSE);

    // Derive type from channel flags (im wins over mpim wins over private).
    if ($isIm) {
      $type = 'im';
    }
    elseif ($isMpim) {
      $type = 'mpim';
    }
    elseif ($isPrivate) {
      $type = 'private_channel';
    }
    else {
      $type = 'public_channel';
    }

    // For IMs, name falls back to user then id; for others use name field.
    $name = (string) ($raw['name'] ?? $raw['user'] ?? $id);

    /** @var array<string,mixed> $topicArr */
    $topicArr = isset($raw['topic']) && is_array($raw['topic']) ? $raw['topic'] : [];
    /** @var array<string,mixed> $purposeArr */
    $purposeArr = isset($raw['purpose']) && is_array($raw['purpose']) ? $raw['purpose'] : [];

    return [
      'id' => $id,
      'name' => $name,
      'type' => $type,
      'is_private' => $isPrivate,
      'is_im' => $isIm,
      'is_mpim' => $isMpim,
      'members' => [],
      'topic' => isset($topicArr['value']) ? (string) $topicArr['value'] : '',
      'purpose' => isset($purposeArr['value']) ? (string) $purposeArr['value'] : '',
    ];
  }

}
