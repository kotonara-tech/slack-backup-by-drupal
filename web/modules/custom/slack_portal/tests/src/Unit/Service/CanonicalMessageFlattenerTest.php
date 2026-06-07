<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for CanonicalMessageFlattener — pure logic, no I/O.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\slack_portal\Service\CanonicalMessageFlattener;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CanonicalMessageFlattener flattens channel JSON into migrate rows.
 *
 * @covers \Drupal\slack_portal\Service\CanonicalMessageFlattener
 */
#[CoversClass(CanonicalMessageFlattener::class)]
#[Group('slack_portal')]
class CanonicalMessageFlattenerTest extends UnitTestCase {

  /**
   * Tests that a parent message and its nested replies each become one row.
   *
   * Given a channel document with one parent message and two nested replies,
   * When flattenChannel() is called,
   * Then the result contains exactly 3 rows with the expected slack_ts values.
   */
  public function testFlattensParentAndRepliesIntoIndividualRows(): void {
    $channelData = [
      'channel' => [
        'id' => 'C_TEST',
        'type' => 'public_channel',
      ],
      'messages' => [
        [
          'slack_ts' => '1700000001.000001',
          'user_id' => 'U_ALICE',
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'parent',
          'edited' => FALSE,
          'thread_ts' => '1700000001.000001',
          'reply_count' => 2,
          'reactions' => [],
          'files' => [],
          'replies' => [
            [
              'slack_ts' => '1700000002.000002',
              'user_id' => 'U_BOB',
              'type' => 'message',
              'subtype' => NULL,
              'text' => 'reply one',
              'edited' => FALSE,
              'thread_ts' => '1700000001.000001',
              'reply_count' => 0,
              'reactions' => [],
              'files' => [],
            ],
            [
              'slack_ts' => '1700000003.000003',
              'user_id' => 'U_ALICE',
              'type' => 'message',
              'subtype' => NULL,
              'text' => 'reply two',
              'edited' => FALSE,
              'thread_ts' => '1700000001.000001',
              'reply_count' => 0,
              'reactions' => [],
              'files' => [],
            ],
          ],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $this->assertCount(3, $rows);
    $tsList = array_column($rows, 'slack_ts');
    $this->assertContains('1700000001.000001', $tsList);
    $this->assertContains('1700000002.000002', $tsList);
    $this->assertContains('1700000003.000003', $tsList);
  }

}
