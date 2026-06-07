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

  /**
   * Tests thread_broadcast appearing both nested and top-level is deduped.
   *
   * Given a channel with a parent that has a thread_broadcast reply nested,
   * and the same thread_broadcast also appears as a top-level message,
   * When flattenChannel() is called,
   * Then the broadcast slack_ts appears exactly once in the results.
   */
  public function testDeduplicatesThreadBroadcastSlackTs(): void {
    $broadcastTs = '1700000012.000012';
    $channelData = [
      'channel' => ['id' => 'C_TEST', 'type' => 'public_channel'],
      'messages' => [
        [
          'slack_ts' => '1700000010.000010',
          'user_id' => 'U_ALICE',
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'parent',
          'edited' => FALSE,
          'thread_ts' => '1700000010.000010',
          'reply_count' => 1,
          'reactions' => [],
          'files' => [],
          'replies' => [
            [
              'slack_ts' => $broadcastTs,
              'user_id' => 'U_ALICE',
              'type' => 'message',
              'subtype' => 'thread_broadcast',
              'text' => 'broadcast reply',
              'edited' => FALSE,
              'thread_ts' => '1700000010.000010',
              'reply_count' => 0,
              'reactions' => [],
              'files' => [],
            ],
          ],
        ],
        // Same broadcast at top level — must be deduped.
        [
          'slack_ts' => $broadcastTs,
          'user_id' => 'U_ALICE',
          'type' => 'message',
          'subtype' => 'thread_broadcast',
          'text' => 'broadcast reply',
          'edited' => FALSE,
          'thread_ts' => '1700000010.000010',
          'reply_count' => 0,
          'reactions' => [],
          'files' => [],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $tsList = array_column($rows, 'slack_ts');
    // Parent + broadcast = 2 rows; broadcast must appear exactly once.
    $this->assertCount(2, $rows);
    $this->assertSame(1, array_count_values($tsList)[$broadcastTs]);
  }

  /**
   * Tests that each row is tagged with the channel type from the channel doc.
   *
   * Given a channel document with type = 'im',
   * When flattenChannel() is called,
   * Then each row's channel_type equals 'im'.
   */
  public function testTagsRowsWithChannelType(): void {
    $channelData = [
      'channel' => ['id' => 'D_IM001', 'type' => 'im'],
      'messages' => [
        [
          'slack_ts' => '1700000001.000001',
          'user_id' => 'U_ALICE',
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'hi',
          'edited' => FALSE,
          'thread_ts' => NULL,
          'reply_count' => 0,
          'reactions' => [],
          'files' => [],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $this->assertCount(1, $rows);
    $this->assertSame('im', $rows[0]['channel_type']);
  }

  /**
   * Tests that file_ids only includes files with a non-null local_path.
   *
   * Given a message with two files, one local and one remote (local_path=null),
   * When flattenChannel() is called,
   * Then file_ids contains only the local file id.
   */
  public function testFileIdsExcludesFilesWithNullLocalPath(): void {
    $channelData = [
      'channel' => ['id' => 'C_TEST', 'type' => 'public_channel'],
      'messages' => [
        [
          'slack_ts' => '1700000020.000020',
          'user_id' => 'U_ALICE',
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'see file',
          'edited' => FALSE,
          'thread_ts' => NULL,
          'reply_count' => 0,
          'reactions' => [],
          'files' => [
            [
              'id' => 'F_LOCAL',
              'name' => 'diagram.png',
              'mimetype' => 'image/png',
              'url_private' => 'REDACTED',
              'local_path' => 'files/F_LOCAL.png',
            ],
            [
              'id' => 'F_REMOTE',
              'name' => 'ext.png',
              'mimetype' => 'image/png',
              'url_private' => 'https://external.example.test/ext.png',
              'local_path' => NULL,
            ],
          ],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $this->assertCount(1, $rows);
    $this->assertSame(['F_LOCAL'], $rows[0]['file_ids']);
  }

  /**
   * Tests that reaction_total sums all reaction counts correctly.
   *
   * Given a message with two reaction entries with counts 2 and 1,
   * When flattenChannel() is called,
   * Then reaction_total equals 3.
   */
  public function testReactionTotalSumsAllReactionCounts(): void {
    $channelData = [
      'channel' => ['id' => 'C_TEST', 'type' => 'public_channel'],
      'messages' => [
        [
          'slack_ts' => '1700000030.000030',
          'user_id' => 'U_BOB',
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'original text',
          'edited' => TRUE,
          'thread_ts' => NULL,
          'reply_count' => 0,
          'reactions' => [
            ['name' => 'thumbsup', 'count' => 2, 'users' => ['U_ALICE', 'U_BOB']],
            ['name' => 'tada', 'count' => 1, 'users' => ['U_ALICE']],
          ],
          'files' => [],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $this->assertCount(1, $rows);
    $this->assertSame(3, $rows[0]['reaction_total']);
  }

  /**
   * Tests that a bot message with user_id=null passes through as NULL.
   *
   * Given a bot message where user_id is null,
   * When flattenChannel() is called,
   * Then the row's user_id is NULL.
   */
  public function testNullUserIdPassesThroughAsNull(): void {
    $channelData = [
      'channel' => ['id' => 'C_TEST', 'type' => 'public_channel'],
      'messages' => [
        [
          'slack_ts' => '1700000040.000040',
          'user_id' => NULL,
          'bot_id' => 'B_BOT001',
          'username' => 'incoming-webhook',
          'type' => 'message',
          'subtype' => 'bot_message',
          'text' => 'automated post',
          'edited' => FALSE,
          'thread_ts' => NULL,
          'reply_count' => 0,
          'reactions' => [],
          'files' => [],
        ],
      ],
    ];

    $flattener = new CanonicalMessageFlattener();
    $rows = $flattener->flattenChannel($channelData);

    $this->assertCount(1, $rows);
    $this->assertNull($rows[0]['user_id']);
  }

}
