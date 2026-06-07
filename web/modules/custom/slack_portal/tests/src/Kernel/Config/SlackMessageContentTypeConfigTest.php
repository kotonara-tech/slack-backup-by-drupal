<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests asserting the slack_message content type and its fields.
 */

namespace Drupal\Tests\slack_portal\Kernel\Config;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the M2 slack_message content type config installs correctly.
 */
#[Group('slack_portal')]
class SlackMessageContentTypeConfigTest extends KernelTestBase {

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
    'migrate',
    'migrate_plus',
    'search_api',
    'search_api_db',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // search_api_task and search_api_item are required because
    // search_api.index.slack_messages (in slack_portal config/install) fires
    // item tracking on postSave.
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['slack_portal']);
  }

  /**
   * The slack_message content type exists.
   */
  public function testContentTypeExists(): void {
    $this->assertNotNull(NodeType::load('slack_message'));
  }

  /**
   * All scalar fields exist with the expected storage type.
   */
  public function testAllScalarFieldsExist(): void {
    $expected = [
      'field_body' => 'text_long',
      'field_slack_ts' => 'string',
      'field_slack_user_id' => 'string',
      'field_posted_at' => 'datetime',
      'field_thread_ts' => 'string',
      'field_subtype' => 'string',
      'field_edited' => 'boolean',
      'field_reply_count' => 'integer',
      'field_reactions' => 'string_long',
      'field_reaction_total' => 'integer',
    ];
    foreach ($expected as $name => $type) {
      $storage = FieldStorageConfig::loadByName('node', $name);
      $this->assertNotNull($storage, "Missing storage: $name");
      $this->assertSame($type, $storage->getType(), "Wrong type: $name");
      $instance = FieldConfig::loadByName('node', 'slack_message', $name);
      $this->assertNotNull($instance, "Missing instance: $name");
    }
  }

  /**
   * The field_posted_at uses datetime_type=datetime (not date-only).
   */
  public function testPostedAtIsDatetimeType(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_posted_at');
    $this->assertNotNull($storage);
    $this->assertSame('datetime', $storage->getSetting('datetime_type'));
  }

  /**
   * The field_channel references taxonomy_term restricted to slack_channels.
   */
  public function testChannelReferenceTargetsSlackChannels(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_channel');
    $this->assertNotNull($storage);
    $this->assertSame('entity_reference', $storage->getType());
    $this->assertSame('taxonomy_term', $storage->getSetting('target_type'));

    $instance = FieldConfig::loadByName('node', 'slack_message', 'field_channel');
    $this->assertNotNull($instance);
    $handler = $instance->getSetting('handler_settings');
    $this->assertArrayHasKey('slack_channels', $handler['target_bundles']);
    $this->assertFalse((bool) $handler['auto_create']);
  }

  /**
   * The field_slack_user references taxonomy_term restricted to slack_users.
   */
  public function testSlackUserReferenceTargetsSlackUsers(): void {
    $instance = FieldConfig::loadByName('node', 'slack_message', 'field_slack_user');
    $this->assertNotNull($instance);
    $handler = $instance->getSetting('handler_settings');
    $this->assertArrayHasKey('slack_users', $handler['target_bundles']);
    $this->assertFalse((bool) $handler['auto_create']);
  }

  /**
   * The field_attachments is a multivalue reference to file entities.
   */
  public function testAttachmentsReferencesFileMultivalue(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_attachments');
    $this->assertNotNull($storage);
    $this->assertSame('entity_reference', $storage->getType());
    $this->assertSame('file', $storage->getSetting('target_type'));
    $this->assertSame(-1, $storage->getCardinality());

    $instance = FieldConfig::loadByName('node', 'slack_message', 'field_attachments');
    $this->assertNotNull($instance);
  }

}
