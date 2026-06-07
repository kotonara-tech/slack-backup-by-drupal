<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: the slack_messages migration creates nodes (scalar fields).
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\node\Entity\Node;

/**
 * Verifies canonical messages migrate into slack_message nodes (scalars).
 */
#[Group('slack_portal')]
class SlackMessagesScalarMigrateTest extends MigrateTestBase {

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
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter']);
    $this->installConfig(['slack_portal']);
    $this->copyCanonicalFixtures();
  }

  /**
   * Loads the single slack_message node having the given field_slack_ts.
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
   * Scalars, status (privacy), title and posted_at migrate correctly.
   */
  public function testScalarsAndStatusMigrate(): void {
    $this->executeMigration('slack_messages');

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'slack_message',
    ]);
    $this->assertCount(10, $nodes);

    // Public standalone message -> published, body, title, posted_at.
    $pub = $this->loadBySlackTs('1700000001.000001');
    $this->assertTrue($pub->isPublished());
    $this->assertSame('Hello public channel', $pub->get('field_body')->value);
    /** @var \Drupal\Core\Field\FieldItemInterface $bodyItem */
    $bodyItem = $pub->get('field_body')->first();
    $this->assertSame('plain_text', $bodyItem->get('format')->getValue());
    $this->assertSame('Hello public channel', $pub->label());
    $this->assertSame('2023-11-14T22:13:21', $pub->get('field_posted_at')->value);

    // Private channel message -> unpublished.
    $prv = $this->loadBySlackTs('1700001000.000001');
    $this->assertFalse($prv->isPublished());

    // Edited message with reactions.
    $edited = $this->loadBySlackTs('1700000030.000030');
    $this->assertTrue((bool) $edited->get('field_edited')->value);
    $this->assertSame(3, (int) $edited->get('field_reaction_total')->value);
    $decoded = json_decode($edited->get('field_reactions')->value, TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame('thumbsup', $decoded[0]['name']);

    // Bot message (null user) -> published (public channel), fallback title.
    $bot = $this->loadBySlackTs('1700000040.000040');
    $this->assertTrue($bot->isPublished());
    $this->assertSame('automated post from a bot', $bot->label());
  }

}
