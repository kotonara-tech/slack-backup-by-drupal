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

}
