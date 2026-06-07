<?php

declare(strict_types=1);

/**
 * @file
 * Migrate process plugin: derive a node title from a Slack message.
 */

namespace Drupal\slack_portal\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Builds a non-empty node title from a Slack message's text and slack_ts.
 *
 * Source must be the array [text, slack_ts]. The title is the text truncated
 * to 255 characters (multibyte-safe); when the text is empty the title falls
 * back to "Message <slack_ts>" so the required node title is never empty.
 *
 * @code
 * title:
 *   plugin: slack_message_title
 *   source:
 *     - text
 *     - slack_ts
 * @endcode
 */
#[MigrateProcess('slack_message_title')]
final class SlackMessageTitle extends ProcessPluginBase {

  /**
   * Maximum node title length.
   */
  private const MAX_LENGTH = 255;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $text = '';
    $ts = '';
    if (is_array($value)) {
      $text = (string) ($value[0] ?? '');
      $ts = (string) ($value[1] ?? '');
    }
    else {
      $text = (string) $value;
    }

    $text = trim($text);
    if ($text !== '') {
      return mb_substr($text, 0, self::MAX_LENGTH);
    }
    return 'Message ' . $ts;
  }

}
