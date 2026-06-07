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
   * Tests multibyte truncation keeps first 255 chars and discards the rest.
   *
   * Input: 254×'あ' + 'い' + 50×'う' (total 305 chars).
   * Expected result: first 255 chars, i.e. 254×'あ' followed by 'い'.
   */
  public function testTruncatesMultibyteTextTo255Chars(): void {
    $plugin = new SlackMessageTitle([], 'slack_message_title', []);
    // Build a distinguishable string so we can verify truncation point.
    $longText = str_repeat('あ', 254) . 'い' . str_repeat('う', 50);

    $result = $plugin->transform(
      [$longText, '1700000001.000001'],
      $this->migrateExecutable,
      $this->row,
      'title',
    );

    $resultStr = (string) $result;
    // Length must be exactly 255 multibyte characters.
    $this->assertSame(255, mb_strlen($resultStr));
    // The 255th character (index 254) is 'い', confirming the truncation point.
    $this->assertSame('い', mb_substr($resultStr, 254, 1));
    // The first 254 characters are all 'あ'.
    $this->assertSame(str_repeat('あ', 254), mb_substr($resultStr, 0, 254));
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
