<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: the slack_users migration creates taxonomy terms (no email).
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies canonical users migrate into slack_users terms without email.
 */
#[Group('slack_portal')]
class SlackUsersMigrateTest extends SlackMigrateKernelTestBase {

  /**
   * Users become terms with their fields set and NO email field present.
   */
  public function testUsersMigrateToTerms(): void {
    $this->executeMigration('slack_users');

    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $all = $storage->loadByProperties(['vid' => 'slack_users']);
    $this->assertCount(3, $all);

    $aliceResults = $storage->loadByProperties([
      'vid' => 'slack_users',
      'field_slack_user_id' => 'U_ALICE',
    ]);
    $alice = reset($aliceResults);
    $this->assertNotFalse($alice);
    $this->assertSame('alice', $alice->label());
    $this->assertSame('Alice Anderson', $alice->get('field_real_name')->value);
    $this->assertSame('alice.a', $alice->get('field_display_name')->value);
    $this->assertFalse((bool) $alice->get('field_is_bot')->value);
    // Email must NOT be imported (no such field on slack_users).
    $this->assertFalse($alice->hasField('field_email'));
    // Avatar URL must be imported.
    $this->assertSame(
      'https://avatars.example.test/alice_192.png',
      $alice->get('field_avatar')->value,
    );

    $botResults = $storage->loadByProperties([
      'vid' => 'slack_users',
      'field_slack_user_id' => 'U_BOTX',
    ]);
    $bot = reset($botResults);
    $this->assertNotFalse($bot);
    $this->assertTrue((bool) $bot->get('field_is_bot')->value);
  }

}
