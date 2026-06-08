<?php

declare(strict_types=1);

/**
 * @file
 * Test trait: copies synthetic canonical fixtures into private://.
 */

namespace Drupal\Tests\slack_portal\Traits;

use Drupal\Core\File\FileSystemInterface;
use Drupal\slack_portal\CanonicalArchive;

/**
 * Copies the tests/fixtures/latest tree into the private:// archive location.
 *
 * Used by the migrate source and migration Kernel tests so the url/file data
 * fetcher and the SlackCanonicalMessages source can read them via private://.
 */
trait SlackArchiveFixturesTrait {

  /**
   * Copies the synthetic latest/ fixtures into private://slack_archive/latest.
   */
  protected function copyCanonicalFixtures(): void {
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $module_path = \Drupal::service('extension.list.module')->getPath('slack_portal');
    $src = \Drupal::root() . '/' . $module_path . '/tests/fixtures/latest';

    $base = CanonicalArchive::BASE_DIR;
    $channels = $base . '/channels';
    $fs->prepareDirectory($base, FileSystemInterface::CREATE_DIRECTORY);
    $fs->prepareDirectory($channels, FileSystemInterface::CREATE_DIRECTORY);

    file_put_contents($base . '/users.json', file_get_contents($src . '/users.json'));
    file_put_contents($base . '/manifest.json', file_get_contents($src . '/manifest.json'));
    foreach (scandir($src . '/channels') as $name) {
      if (str_ends_with($name, '.json')) {
        file_put_contents(
          $channels . '/' . $name,
          file_get_contents($src . '/channels/' . $name),
        );
      }
    }

    $filesDir = $base . '/files';
    $fs->prepareDirectory($filesDir, FileSystemInterface::CREATE_DIRECTORY);
    $srcFiles = $src . '/files';
    if (is_dir($srcFiles)) {
      foreach (scandir($srcFiles) as $name) {
        if ($name !== '.' && $name !== '..') {
          file_put_contents($filesDir . '/' . $name, file_get_contents($srcFiles . '/' . $name));
        }
      }
    }
  }

  /**
   * Iterates a migrate source plugin via a null-destination stub migration.
   *
   * @param string $sourcePluginId
   *   The source plugin id (e.g. slack_canonical_messages).
   *
   * @return array<int,array<string,mixed>>
   *   The raw source row arrays.
   */
  protected function sourceRowsFor(string $sourcePluginId): array {
    $migration = \Drupal::service('plugin.manager.migration')->createStubMigration([
      'id' => $sourcePluginId . '_source_test',
      'source' => ['plugin' => $sourcePluginId],
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

}
