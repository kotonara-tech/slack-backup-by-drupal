<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests asserting the slack_channels / slack_users taxonomy config.
 */

namespace Drupal\Tests\slack_portal\Kernel\Config;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the M2 taxonomy vocabularies and their fields install correctly.
 */
#[Group('slack_portal')]
class SlackTaxonomyConfigTest extends KernelTestBase {

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
    $this->installConfig(['slack_portal']);
  }

  /**
   * The slack_channels vocabulary and its fields install with correct types.
   */
  public function testSlackChannelsVocabularyAndFields(): void {
    $this->assertNotNull(Vocabulary::load('slack_channels'));

    foreach (['field_slack_channel_id', 'field_channel_type'] as $name) {
      $storage = FieldStorageConfig::loadByName('taxonomy_term', $name);
      $this->assertNotNull($storage, "Missing storage: $name");
      $this->assertSame('string', $storage->getType());
      $this->assertSame(1, $storage->getCardinality());
      $this->assertSame('taxonomy_term', $storage->getTargetEntityTypeId());

      $instance = FieldConfig::loadByName('taxonomy_term', 'slack_channels', $name);
      $this->assertNotNull($instance, "Missing instance: $name");
    }
  }

  /**
   * The slack_users vocabulary installs with correct fields and NO email.
   */
  public function testSlackUsersVocabularyAndFields(): void {
    $this->assertNotNull(Vocabulary::load('slack_users'));

    $expected = [
      'field_slack_user_id' => 'string',
      'field_real_name' => 'string',
      'field_display_name' => 'string',
      'field_is_bot' => 'boolean',
      'field_avatar' => 'uri',
    ];
    foreach ($expected as $name => $type) {
      $storage = FieldStorageConfig::loadByName('taxonomy_term', $name);
      $this->assertNotNull($storage, "Missing storage: $name");
      $this->assertSame($type, $storage->getType(), "Wrong type for $name");
      $this->assertSame(1, $storage->getCardinality());

      $instance = FieldConfig::loadByName('taxonomy_term', 'slack_users', $name);
      $this->assertNotNull($instance, "Missing instance: $name");
    }

    // Email is intentionally NOT imported (ADR-0013).
    $this->assertNull(
      FieldStorageConfig::loadByName('taxonomy_term', 'field_email'),
      'field_email must not exist on slack_users.',
    );
  }

}
