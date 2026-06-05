<?php

declare(strict_types=1);

/**
 * @file
 * Resolves the Slack user token and related config from Settings / env / State.
 */

namespace Drupal\slack_portal\Service;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;

/**
 * Resolves Slack credentials and export configuration.
 *
 * Resolution order (first non-empty wins):
 *  1. Drupal Settings (settings.local.php or settings.php).
 *  2. Environment variable.
 *  3. Drupal State — ciphertext encrypted via the drupal/encrypt module.
 *
 * SECRETS RULE: The token value must never appear in log messages or exception
 * messages. All thrown exceptions use generic, value-free text.
 */
final class SlackTokenProvider {

  /**
   * Constructs a SlackTokenProvider.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The Drupal site settings service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state service (stores the encrypted token ciphertext).
   * @param \Drupal\encrypt\EncryptServiceInterface $encryption
   *   The encrypt module encryption service.
   * @param \Drupal\encrypt\EncryptionProfileManagerInterface $profileManager
   *   The encrypt module encryption profile manager.
   * @param string $encryptionProfileId
   *   The ID of the encryption profile to use for decryption.
   * @param string $tokenStateKey
   *   The Drupal State key under which the ciphertext is stored.
   */
  public function __construct(
    private readonly Settings $settings,
    private readonly StateInterface $state,
    private readonly EncryptServiceInterface $encryption,
    private readonly EncryptionProfileManagerInterface $profileManager,
    private readonly string $encryptionProfileId = 'slack_portal',
    private readonly string $tokenStateKey = 'slack_portal.token_ciphertext',
  ) {
  }

  /**
   * Returns the Slack user token.
   *
   * Resolves in order:
   *  1. Settings key 'slack_user_token' (settings.local.php).
   *  2. Env var SLACK_USER_TOKEN.
   *  3. Drupal State ciphertext, decrypted via drupal/encrypt.
   *
   * @return string
   *   The trimmed, non-empty Slack user token.
   *
   * @throws \RuntimeException
   *   If no token is configured, or if decryption fails. Messages
   *   intentionally omit token/cipher values to prevent credential leakage.
   */
  public function getToken(): string {
    // Resolver 1: Drupal Settings (highest priority).
    $fromSettings = $this->settings->get('slack_user_token');
    if ($fromSettings !== NULL) {
      $token = trim((string) $fromSettings);
      if ($token !== '') {
        return $token;
      }
    }

    // Resolver 2: environment variable.
    $fromEnv = getenv('SLACK_USER_TOKEN');
    if ($fromEnv !== FALSE) {
      $token = trim($fromEnv);
      if ($token !== '') {
        return $token;
      }
    }

    // Resolver 3: encrypted ciphertext in Drupal State.
    $cipher = $this->state->get($this->tokenStateKey);
    if (is_string($cipher) && $cipher !== '') {
      $profile = $this->profileManager->getEncryptionProfile($this->encryptionProfileId);
      if ($profile !== NULL) {
        try {
          // Trim like the Settings/env resolvers so a pasted trailing newline
          // does not corrupt the Authorization header.
          $decrypted = trim((string) $this->encryption->decrypt($cipher, $profile));
          if ($decrypted !== '') {
            return $decrypted;
          }
        }
        catch (\Throwable) {
          // Do NOT log the cipher or any partial token value.
          throw new \RuntimeException('Slack user token could not be decrypted.');
        }
      }
    }

    // No token found — throw without revealing any value.
    throw new \RuntimeException('Slack user token is not configured.');
  }

  /**
   * Returns the number of days of history to export.
   *
   * Resolves in order:
   *  1. Settings key 'slack_export_since_days'.
   *  2. Env var SLACK_EXPORT_SINCE_DAYS.
   *  3. Default: 90.
   *
   * Values of 0 or less are treated as "not configured" and fall back to 90.
   *
   * @return int
   *   The number of days (positive integer, default 90).
   */
  public function getSinceDays(): int {
    $fromSettings = $this->settings->get('slack_export_since_days');
    if ($fromSettings !== NULL) {
      $value = (int) $fromSettings;
      if ($value > 0) {
        return $value;
      }
    }

    $fromEnv = getenv('SLACK_EXPORT_SINCE_DAYS');
    if ($fromEnv !== FALSE) {
      $value = (int) $fromEnv;
      if ($value > 0) {
        return $value;
      }
    }

    return 90;
  }

  /**
   * Returns the maximum number of 429/503 retry attempts.
   *
   * Resolves in order:
   *  1. Settings key 'slack_rate_limit_max_retries'.
   *  2. Env var SLACK_RATE_LIMIT_MAX_RETRIES.
   *  3. Default: 10.
   *
   * Values of 0 or less are treated as "not configured" and fall back to 10.
   *
   * @return int
   *   The max retries (positive integer, default 10).
   */
  public function getMaxRetries(): int {
    $fromSettings = $this->settings->get('slack_rate_limit_max_retries');
    if ($fromSettings !== NULL) {
      $value = (int) $fromSettings;
      if ($value > 0) {
        return $value;
      }
    }

    $fromEnv = getenv('SLACK_RATE_LIMIT_MAX_RETRIES');
    if ($fromEnv !== FALSE) {
      $value = (int) $fromEnv;
      if ($value > 0) {
        return $value;
      }
    }

    return 10;
  }

}
