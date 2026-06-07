<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for the SlackCanonicalFiles migrate source plugin.
 */

namespace Drupal\Tests\slack_portal\Kernel\Plugin\migrate\source;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the SlackCanonicalFiles source lists local archive files.
 */
#[Group('slack_portal')]
class SlackCanonicalFilesTest extends KernelTestBase {

  use SlackArchiveFixturesTrait;

  /**
   * Modules to enable.
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
    $this->copyCanonicalFixtures();
  }

  /**
   * The source yields one row per archive file with id/uri/filename.
   */
  public function testListsArchiveFiles(): void {
    $manager = \Drupal::service('plugin.manager.migration');
    $migration = $manager->createStubMigration([
      'id' => 'slack_files_source_test',
      'source' => ['plugin' => 'slack_canonical_files'],
      'process' => [],
      'destination' => ['plugin' => 'null'],
    ]);
    $rows = [];
    foreach ($migration->getSourcePlugin() as $row) {
      /** @var \Drupal\migrate\Row $row */
      $rows[] = $row->getSource();
    }
    $this->assertCount(1, $rows);
    $this->assertSame('F_LOCAL', $rows[0]['id']);
    $this->assertSame('F_LOCAL.png', $rows[0]['filename']);
    $this->assertSame(
      'public://slack_archive/latest/files/F_LOCAL.png',
      $rows[0]['uri'],
    );
  }

}
