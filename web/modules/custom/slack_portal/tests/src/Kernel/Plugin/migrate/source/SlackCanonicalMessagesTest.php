<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for the SlackCanonicalMessages migrate source plugin.
 */

namespace Drupal\Tests\slack_portal\Kernel\Plugin\migrate\source;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the source flattens, dedups and tags canonical channel messages.
 */
#[Group('slack_portal')]
class SlackCanonicalMessagesTest extends KernelTestBase {

  /**
   * Modules to enable (KernelTestBase does NOT resolve info.yml deps).
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'taxonomy',
    'node',
    'datetime',
    'file',
    'migrate',
    'migrate_plus',
    'key',
    'encrypt',
    'real_aes',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->copyFixturesToPublic();
  }

  /**
   * Copies the synthetic latest/ fixtures into the public:// archive tree.
   */
  private function copyFixturesToPublic(): void {
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $module_path = \Drupal::service('extension.list.module')->getPath('slack_portal');
    $src = \Drupal::root() . '/' . $module_path . '/tests/fixtures/latest';

    $base = 'public://slack_archive/latest';
    $channelsDir = $base . '/channels';
    $fs->prepareDirectory($base, FileSystemInterface::CREATE_DIRECTORY);
    $fs->prepareDirectory($channelsDir, FileSystemInterface::CREATE_DIRECTORY);

    file_put_contents($base . '/users.json', file_get_contents($src . '/users.json'));
    file_put_contents($base . '/manifest.json', file_get_contents($src . '/manifest.json'));
    foreach (scandir($src . '/channels') as $name) {
      if (str_ends_with($name, '.json')) {
        file_put_contents(
          $base . '/channels/' . $name,
          file_get_contents($src . '/channels/' . $name),
        );
      }
    }
  }

  /**
   * Iterates the source plugin and returns the raw source row arrays.
   *
   * @return array<int,array<string,mixed>>
   *   The source rows.
   */
  private function getSourceRows(): array {
    /** @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface $manager */
    $manager = \Drupal::service('plugin.manager.migration');
    $migration = $manager->createStubMigration([
      'id' => 'slack_messages_source_test',
      'source' => ['plugin' => 'slack_canonical_messages'],
      'process' => [],
      'destination' => ['plugin' => 'null'],
    ]);
    $rows = [];
    foreach ($migration->getSourcePlugin() as $row) {
      /** @var \Drupal\migrate\Row $row */
      $rows[] = $row->getSource();
    }
    return $rows;
  }

  /**
   * The source yields one deduped row per message across all channels.
   */
  public function testYieldsAllDedupedRows(): void {
    $rows = $this->getSourceRows();
    $this->assertCount(10, $rows);
  }

  /**
   * The thread_broadcast slack_ts appears exactly once across all rows.
   *
   * Given C_PUB001 has a thread_broadcast both nested and at top level,
   * When the source plugin iterates the fixture,
   * Then slack_ts 1700000012.000012 appears in exactly one row.
   */
  public function testThreadBroadcastSlackTsAppearsExactlyOnce(): void {
    $rows = $this->getSourceRows();
    $tsList = array_column($rows, 'slack_ts');
    $counts = array_count_values($tsList);
    $this->assertArrayHasKey('1700000012.000012', $counts);
    $this->assertSame(1, $counts['1700000012.000012']);
  }

  /**
   * Each channel's rows are tagged with the correct channel_type.
   *
   * Given four channels of types public_channel, private_channel, im, mpim,
   * When the source plugin iterates the fixtures,
   * Then each channel type appears at least once in the rows.
   */
  public function testChannelTypesAreTaggedPerChannel(): void {
    $rows = $this->getSourceRows();
    $types = array_unique(array_column($rows, 'channel_type'));
    $this->assertContains('public_channel', $types);
    $this->assertContains('private_channel', $types);
    $this->assertContains('im', $types);
    $this->assertContains('mpim', $types);
  }

  /**
   * The row for slack_ts 1700000020.000020 has file_ids == ['F_LOCAL'].
   *
   * Given C_PUB001 has a message with one local file and one remote file,
   * When the source plugin emits the row for that message,
   * Then file_ids contains only the local file id F_LOCAL.
   */
  public function testFileIdsContainsOnlyLocalFiles(): void {
    $rows = $this->getSourceRows();
    $indexed = array_combine(array_column($rows, 'slack_ts'), $rows);
    $this->assertArrayHasKey('1700000020.000020', $indexed);
    $this->assertSame(['F_LOCAL'], $indexed['1700000020.000020']['file_ids']);
  }

  /**
   * The row for slack_ts 1700000030.000030 has reaction_total == 3.
   *
   * Given C_PUB001 has a message with reactions totalling 3,
   * When the source plugin emits the row for that message,
   * Then reaction_total equals 3.
   */
  public function testReactionTotalIsSummedCorrectly(): void {
    $rows = $this->getSourceRows();
    $indexed = array_combine(array_column($rows, 'slack_ts'), $rows);
    $this->assertArrayHasKey('1700000030.000030', $indexed);
    $this->assertSame(3, $indexed['1700000030.000030']['reaction_total']);
  }

  /**
   * The row for slack_ts 1700000040.000040 has user_id === NULL (bot message).
   *
   * Given C_PUB001 has a bot message with user_id null,
   * When the source plugin emits the row for that message,
   * Then user_id is NULL.
   */
  public function testBotMessageHasNullUserId(): void {
    $rows = $this->getSourceRows();
    $indexed = array_combine(array_column($rows, 'slack_ts'), $rows);
    $this->assertArrayHasKey('1700000040.000040', $indexed);
    $this->assertNull($indexed['1700000040.000040']['user_id']);
  }

}
