<?php

declare(strict_types=1);

/**
 * @file
 * Test trait: copies synthetic canonical fixtures into public://.
 */

namespace Drupal\Tests\slack_portal\Traits;

use Drupal\Core\File\FileSystemInterface;

/**
 * Copies the tests/fixtures/latest tree into the public:// archive location.
 *
 * Used by the migrate source and migration Kernel tests so the url/file data
 * fetcher and the SlackCanonicalMessages source can read them via public://.
 */
trait SlackArchiveFixturesTrait {

  /**
   * Copies the synthetic latest/ fixtures into public://slack_archive/latest.
   */
  protected function copyCanonicalFixtures(): void {
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $module_path = \Drupal::service('extension.list.module')->getPath('slack_portal');
    $src = \Drupal::root() . '/' . $module_path . '/tests/fixtures/latest';

    $base = 'public://slack_archive/latest';
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
  }

}
