<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: cross-channel slack_ts collision must yield distinct nodes.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies cross-channel slack_ts collisions do not overwrite each other.
 */
#[Group('slack_portal')]
class SlackMessagesCompositeIdMigrateTest extends MigrateTestBase {

  use SlackArchiveFixturesTrait;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'taxonomy', 'node',
    'datetime', 'file', 'migrate', 'migrate_plus', 'key', 'encrypt',
    'real_aes', 'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['filter']);
    $this->installConfig(['slack_portal']);
    $this->copyCanonicalFixtures();

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
      'public://slack_archive/latest/channels/C_DUP001.json',
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
  }

}
