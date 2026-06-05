<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for the SlackTimestampToDatetime migrate process plugin.
 */

namespace Drupal\Tests\slack_portal\Unit\Plugin\migrate\process;

use Drupal\slack_portal\Plugin\migrate\process\SlackTimestampToDatetime;
use Drupal\Tests\migrate\Unit\process\MigrateProcessTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SlackTimestampToDatetime converts a Slack ts to a Drupal datetime.
 *
 * @covers \Drupal\slack_portal\Plugin\migrate\process\SlackTimestampToDatetime
 */
#[CoversClass(SlackTimestampToDatetime::class)]
#[Group('slack_portal')]
class SlackTimestampToDatetimeTest extends MigrateProcessTestCase {

  /**
   * Tests a "seconds.micros" Slack ts maps to a UTC ISO-8601 datetime string.
   *
   * Drupal datetime fields store 'Y-m-d\TH:i:s' in UTC; the micro part of the
   * Slack ts is dropped for the human datetime (it only ensures ts uniqueness).
   */
  public function testConvertsSlackTsToUtcDatetime(): void {
    $plugin = new SlackTimestampToDatetime([], 'slack_timestamp_to_datetime', []);

    $result = $plugin->transform(
      '1700000000.000100',
      $this->migrateExecutable,
      $this->row,
      'field_posted_at',
    );

    $this->assertSame('2023-11-14T22:13:20', $result);
  }

}
