<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for the SlackMessageTitle migrate process plugin.
 */

namespace Drupal\Tests\slack_portal\Unit\Plugin\migrate\process;

use Drupal\slack_portal\Plugin\migrate\process\SlackMessageTitle;
use Drupal\Tests\migrate\Unit\process\MigrateProcessTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SlackMessageTitle derives a node title from text and slack_ts.
 *
 * @covers \Drupal\slack_portal\Plugin\migrate\process\SlackMessageTitle
 */
#[CoversClass(SlackMessageTitle::class)]
#[Group('slack_portal')]
class SlackMessageTitleTest extends MigrateProcessTestCase {

  /**
   * Tests that non-empty text is used as-is when short enough.
   */
  public function testTruncatesTextToTitle(): void {
    $plugin = new SlackMessageTitle([], 'slack_message_title', []);

    $result = $plugin->transform(
      ['Hello world', '1700000001.000001'],
      $this->migrateExecutable,
      $this->row,
      'title',
    );

    $this->assertSame('Hello world', $result);
  }

}
