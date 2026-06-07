<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: slack_messages entity_reference fields resolve via lookup.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies channel/user references resolve and null users create no stub.
 */
#[Group('slack_portal')]
class SlackMessagesReferenceMigrateTest extends SlackMigrateKernelTestBase {

  /**
   * References resolve; the null-user bot message gets no user and no stub.
   */
  public function testReferencesResolveAndNullUserSkipped(): void {
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    // A public message references its channel term and its user term.
    $m1 = $this->loadBySlackTs('1700000001.000001');
    $channels = $m1->get('field_channel')->referencedEntities();
    $this->assertCount(1, $channels);
    $this->assertSame('C_PUB001', $channels[0]->get('field_slack_channel_id')->value);
    $users = $m1->get('field_slack_user')->referencedEntities();
    $this->assertCount(1, $users);
    $this->assertSame('U_ALICE', $users[0]->get('field_slack_user_id')->value);

    // Bot message: null user -> empty field_slack_user, channel still resolves.
    $bot = $this->loadBySlackTs('1700000040.000040');
    $this->assertTrue($bot->get('field_slack_user')->isEmpty());
    $botChannels = $bot->get('field_channel')->referencedEntities();
    $this->assertCount(1, $botChannels);
    $this->assertSame('C_PUB001', $botChannels[0]->get('field_slack_channel_id')->value);

    // No stub term created in slack_users (still exactly the 3 migrated users).
    $allUsers = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'slack_users']);
    $this->assertCount(3, $allUsers);
  }

}
