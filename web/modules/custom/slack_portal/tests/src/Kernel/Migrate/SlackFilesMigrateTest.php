<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: slack_files migration + message field_attachments lookup.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\node\Entity\Node;
use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies files migrate to managed file entities and attach to messages.
 */
#[Group('slack_portal')]
class SlackFilesMigrateTest extends MigrateTestBase {

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
  private function loadBySlackTs(string $ts): Node {
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'slack_message',
      'field_slack_ts' => $ts,
    ]);
    $node = reset($nodes);
    $this->assertNotFalse($node, "No node for slack_ts $ts");
    return $node;
  }

  /**
   * Files become permanent file entities and attach to the right message.
   */
  public function testFilesMigrateAndAttach(): void {
    $this->executeMigrations([
      'slack_channels',
      'slack_users',
      'slack_files',
      'slack_messages',
    ]);

    // One managed file entity created, permanent, referencing the public uri.
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties([
      'uri' => 'public://slack_archive/latest/files/F_LOCAL.png',
    ]);
    $this->assertCount(1, $files);
    $file = reset($files);
    $this->assertTrue($file->isPermanent());
    $this->assertSame('F_LOCAL.png', $file->getFilename());

    // The file-bearing message references it.
    $withFile = $this->loadBySlackTs('1700000020.000020');
    $attached = $withFile->get('field_attachments')->referencedEntities();
    $this->assertCount(1, $attached);
    $this->assertSame($file->id(), $attached[0]->id());

    // A message without files has empty field_attachments.
    $noFile = $this->loadBySlackTs('1700000001.000001');
    $this->assertTrue($noFile->get('field_attachments')->isEmpty());
  }

}
