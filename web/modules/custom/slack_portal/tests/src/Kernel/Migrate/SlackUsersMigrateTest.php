<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: the slack_users migration creates taxonomy terms (no email).
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies canonical users migrate into slack_users terms without email.
 */
#[Group('slack_portal')]
class SlackUsersMigrateTest extends MigrateTestBase {

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
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['slack_portal']);
    $this->copyCanonicalFixtures();
  }

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

    $botResults = $storage->loadByProperties([
      'vid' => 'slack_users',
      'field_slack_user_id' => 'U_BOTX',
    ]);
    $bot = reset($botResults);
    $this->assertNotFalse($bot);
    $this->assertTrue((bool) $bot->get('field_is_bot')->value);
  }

}
