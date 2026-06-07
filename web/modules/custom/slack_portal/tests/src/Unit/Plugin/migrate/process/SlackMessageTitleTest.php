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

  /**
   * Tests that text longer than 255 multibyte chars is truncated to 255.
   */
  public function testTruncatesMultibyteTextTo255Chars(): void {
    $plugin = new SlackMessageTitle([], 'slack_message_title', []);
    $longText = str_repeat('あ', 300);

    $result = $plugin->transform(
      [$longText, '1700000001.000001'],
      $this->migrateExecutable,
      $this->row,
      'title',
    );

    $this->assertSame(255, mb_strlen((string) $result));
  }

  /**
   * Tests that empty text falls back to "Message <slack_ts>".
   */
  public function testEmptyTextFallsBackToMessageTimestamp(): void {
    $plugin = new SlackMessageTitle([], 'slack_message_title', []);

    $result = $plugin->transform(
      ['', '1700000040.000040'],
      $this->migrateExecutable,
      $this->row,
      'title',
    );

    $this->assertSame('Message 1700000040.000040', $result);
  }

}
