<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: cross-channel slack_ts collision must yield distinct nodes.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies cross-channel slack_ts collisions do not overwrite each other.
 */
#[Group('slack_portal')]
class SlackMessagesCompositeIdMigrateTest extends SlackMigrateKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add a second public channel reusing an existing slack_ts (collision).
    $extra = [
      'schema_version' => 1,
      'channel' => [
        'id' => 'C_DUP001',
        'name' => 'dup',
        'type' => 'public_channel',
        'is_private' => FALSE,
        'is_im' => FALSE,
        'is_mpim' => FALSE,
        'members' => [],
        'topic' => '',
        'purpose' => '',
      ],
      'messages' => [
        [
          'slack_ts' => '1700000001.000001',
          'channel_id' => 'C_DUP001',
          'user_id' => 'U_BOB',
          'bot_id' => NULL,
          'username' => NULL,
          'type' => 'message',
          'subtype' => NULL,
          'text' => 'same ts different channel',
          'posted_at' => '2023-11-14T22:13:21Z',
          'edited' => FALSE,
          'thread_ts' => NULL,
          'reply_count' => 0,
          'reactions' => [],
          'files' => [],
        ],
      ],
    ];
    file_put_contents(
      'private://slack_archive/latest/channels/C_DUP001.json',
      json_encode($extra),
    );
  }

  /**
   * Two channels sharing a slack_ts produce two distinct message nodes.
   */
  public function testCrossChannelSlackTsAreDistinctNodes(): void {
    $this->executeMigrations([
      'slack_channels', 'slack_users', 'slack_files', 'slack_messages',
    ]);

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'slack_message',
      'field_slack_ts' => '1700000001.000001',
    ]);
    $this->assertCount(2, $nodes);

    // Confirm the two nodes carry genuinely distinct body content.
    $texts = array_map(
      static fn ($n) => $n->get('field_body')->value,
      array_values($nodes),
    );
    sort($texts);
    $this->assertSame(
      ['Hello public channel', 'same ts different channel'],
      $texts,
    );
  }

}
