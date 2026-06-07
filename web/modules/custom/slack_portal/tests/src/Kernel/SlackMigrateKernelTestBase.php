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
    'slack_portal',
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
