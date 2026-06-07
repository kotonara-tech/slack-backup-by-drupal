<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: the slack_messages migration creates nodes (scalar fields).
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies canonical messages migrate into slack_message nodes (scalars).
 */
#[Group('slack_portal')]
class SlackMessagesScalarMigrateTest extends SlackMigrateKernelTestBase {

  /**
   * Scalars, status (privacy), title and posted_at migrate correctly.
   */
  public function testScalarsAndStatusMigrate(): void {
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

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
    // Bot message subtype field.
    $this->assertSame('bot_message', $bot->get('field_subtype')->value);

    // IM node -> unpublished (DM privacy).
    $this->assertFalse($this->loadBySlackTs('1700002000.000001')->isPublished());

    // MPIM node -> unpublished (group DM privacy).
    $this->assertFalse($this->loadBySlackTs('1700003000.000001')->isPublished());

    // Standalone message: no thread context, zero reply count.
    $standalone = $this->loadBySlackTs('1700000001.000001');
    $this->assertTrue($standalone->get('field_thread_ts')->isEmpty());
    $this->assertSame(0, (int) $standalone->get('field_reply_count')->value);

    // Thread parent: reply_count=2, thread_ts equals its own ts.
    $parent = $this->loadBySlackTs('1700000010.000010');
    $this->assertSame(2, (int) $parent->get('field_reply_count')->value);
    $this->assertSame('1700000010.000010', $parent->get('field_thread_ts')->value);
  }

}
