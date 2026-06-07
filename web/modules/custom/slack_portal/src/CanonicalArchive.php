<?php

declare(strict_types=1);

/**
 * @file
 * Single source of truth for the canonical Slack archive location.
 */

namespace Drupal\slack_portal;

/**
 * Holds the stable canonical-archive base directory constant.
 *
 * Both the writer (CanonicalJsonWriter) and the migrate source plugin
 * (SlackCanonicalMessages) resolve their base path from here so the
 * `public://slack_archive/latest` literal is defined exactly once.
 */
final class CanonicalArchive {

  /**
   * The stable base directory URI of the canonical archive.
   */
  public const BASE_DIR = 'public://slack_archive/latest';

}
