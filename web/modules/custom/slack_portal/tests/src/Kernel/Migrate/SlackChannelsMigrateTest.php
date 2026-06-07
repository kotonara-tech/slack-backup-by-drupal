<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: the slack_channels migration creates taxonomy terms.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\migrate\Kernel\MigrateTestBase;
use Drupal\Tests\slack_portal\Traits\SlackArchiveFixturesTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies canonical channels migrate into slack_channels taxonomy terms.
 */
#[Group('slack_portal')]
class SlackChannelsMigrateTest extends MigrateTestBase {

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
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installConfig(['slack_portal']);
    $this->copyCanonicalFixtures();
  }

  /**
   * The migration creates one term per channel with id + type fields set.
   */
  public function testChannelsMigrateToTerms(): void {
    $this->executeMigration('slack_channels');

    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $all = $storage->loadByProperties(['vid' => 'slack_channels']);
    $this->assertCount(4, $all);

    $pub = $storage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'C_PUB001',
    ]);
    $term = reset($pub);
    $this->assertNotFalse($term);
    $this->assertSame('general', $term->label());
    $this->assertSame('public_channel', $term->get('field_channel_type')->value);

    $prv = $storage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'C_PRV001',
    ]);
    $prvTerm = reset($prv);
    $this->assertNotFalse($prvTerm);
    $this->assertSame('private_channel', $prvTerm->get('field_channel_type')->value);
  }

}
