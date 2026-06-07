<?php

declare(strict_types=1);

/**
 * @file
 * Shared base for slack_portal migration Kernel tests.
 */

namespace Drupal\Tests\slack_portal\Kernel;

use Drupal\node\Entity\Node;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;

/**
 * Base class: common module list, schema/config install, fixture copy.
 */
abstract class SlackMigrateKernelTestBase extends MigrateTestBase {

  use SlackArchiveFixturesTrait;

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
    'search_api',
    'search_api_db',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   *
   * Creates the private:// directory and sets file_private_path so that
   * CoreServiceProvider registers the private stream wrapper when the kernel
   * boots (CoreServiceProvider checks this setting during container build).
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
    // search_api_task entity + search_api_item table must be present before
    // installConfig(['slack_portal']) because the index config fires item
    // tracking (queries node table) on postSave.
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['filter']);
    $this->installConfig(['slack_portal']);
    $this->copyCanonicalFixtures();
  }

  /**
   * Loads the single slack_message node with the given field_slack_ts.
   */
  protected function loadBySlackTs(string $ts): Node {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'slack_message',
      'field_slack_ts' => $ts,
    ]);
    $node = reset($nodes);
    $this->assertNotFalse($node, "No node for slack_ts $ts");
    return $node;
  }

}
