<?php

declare(strict_types=1);

/**
 * @file
 * Migrate process plugin: Slack ts ("seconds.micros") → Drupal datetime.
 */

namespace Drupal\slack_portal\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Converts a Slack timestamp to a Drupal datetime storage string.
 *
 * Slack timestamps are "seconds.microseconds" (e.g. "1700000000.000100").
 * Drupal datetime fields store 'Y-m-d\TH:i:s' in UTC; only the integer-seconds
 * part is used for the human datetime (the micro part only guarantees the ts's
 * uniqueness within a channel).
 *
 * Usage in a migration process pipeline:
 * @code
 * field_posted_at:
 *   plugin: slack_timestamp_to_datetime
 *   source: slack_ts
 * @endcode
 */
#[MigrateProcess('slack_timestamp_to_datetime')]
final class SlackTimestampToDatetime extends ProcessPluginBase {

  /**
   * The Drupal datetime storage format (UTC, no timezone suffix).
   */
  private const DATETIME_STORAGE_FORMAT = 'Y-m-d\TH:i:s';

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $ts = trim((string) $value);
    if ($ts === '') {
      return NULL;
    }
    // Use only the integer-seconds part; gmdate formats in UTC.
    return gmdate(self::DATETIME_STORAGE_FORMAT, (int) $ts);
  }

}
