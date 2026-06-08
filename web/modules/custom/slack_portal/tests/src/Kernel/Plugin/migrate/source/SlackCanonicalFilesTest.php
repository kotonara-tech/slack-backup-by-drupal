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
   *
   * Creates the private:// directory and sets file_private_path so that
   * CoreServiceProvider registers the private stream wrapper when the kernel
   * boots.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $private_dir = $this->siteDirectory . '/private';
    mkdir($private_dir, 0775, TRUE);
    $this->setSetting('file_private_path', $private_dir);
  }

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
    $rows = $this->sourceRowsFor('slack_canonical_files');
    $this->assertCount(1, $rows);
    $this->assertSame('F_LOCAL', $rows[0]['id']);
    $this->assertSame('F_LOCAL.png', $rows[0]['filename']);
    $this->assertSame(
      'private://slack_archive/latest/files/F_LOCAL.png',
      $rows[0]['uri'],
    );
    $this->assertStringEndsWith('.png', $rows[0]['uri']);
  }

}
