<?php

declare(strict_types=1);

/**
 * @file
 * Masks Slack token-like substrings from arbitrary text.
 */

namespace Drupal\slack_portal\Service;

/**
 * Redacts Slack token-like substrings (xox?-…) from text.
 *
 * Centralises the redaction applied before any exception message is logged or
 * returned to a client, so a misconfigured exception path cannot leak a token.
 * Shared by SlackPortalApiController and SlackFetchQueueWorker.
 */
final class SecretMasker {

  /**
   * Replaces Slack token-like substrings with [REDACTED].
   *
   * Matches the xox[a-z]- prefix followed by non-whitespace, non-quote
   * characters (covers xoxp-/xoxb-/xoxa-/xoxs- user, bot and app tokens).
   *
   * @param string $text
   *   Arbitrary text that may contain a Slack token.
   *
   * @return string
   *   The text with any Slack token-like substring replaced by [REDACTED].
   */
  public static function mask(string $text): string {
    return preg_replace('/xox[a-z]-[^\s"\']+/i', '[REDACTED]', $text) ?? $text;
  }

}
