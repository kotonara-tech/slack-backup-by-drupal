<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test asserting the published-only slack_messages index config.
 */

namespace Drupal\Tests\slack_portal\Kernel\Search;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the M3 Search API index (slack_messages) installs correctly.
 *
 * Given: slack_portal config is installed with search_api.server.slack_db
 * When:  Index::load('slack_messages') is called
 * Then:  The index exists, targets entity:node/slack_message bundle, is
 *        attached to slack_db, exposes the required fields, enforces
 *        published-only via entity_status + content_access processors,
 *        and indexes directly.
 */
#[Group('slack_portal')]
class SlackSearchIndexConfigTest extends KernelTestBase {

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
    'search_api',
    'search_api_db',
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'jsonapi_search_api',
    'jsonapi_search_api_facets',
    'facets',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // search_api_task entity + search_api_item table required before config.
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    // search_api.settings (tracking_page_size) must exist before index config
    // is installed because postSave immediately tries to track items.
    $this->installConfig(['search_api']);
    // Index config triggers entity tracking which queries the node table.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['slack_portal']);
  }

  /**
   * The slack_messages index loads after config install.
   */
  public function testIndexLoads(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index, 'Index slack_messages must exist after config install.');
  }

  /**
   * The index uses entity:node as its datasource.
   */
  public function testDatasourceIsEntityNode(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $datasource_ids = $index->getDatasourceIds();
    $this->assertContains('entity:node', $datasource_ids);
  }

  /**
   * The datasource bundle filter selects only slack_message.
   */
  public function testDatasourceBundleFilterIsSlackMessage(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $datasource = $index->getDatasource('entity:node');
    $config = $datasource->getConfiguration();
    // default: false means we include only the selected bundles.
    $this->assertFalse((bool) $config['bundles']['default']);
    $this->assertContains('slack_message', $config['bundles']['selected']);
  }

  /**
   * The index is attached to the slack_db server.
   */
  public function testServerIdIsSlackDb(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $this->assertSame('slack_db', $index->getServerId());
  }

  /**
   * All required fields are present with the expected types.
   */
  public function testRequiredFieldsExist(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $fields = $index->getFields();

    $expected = [
      'title' => 'text',
      'body' => 'text',
      'channel_name' => 'string',
      'slack_user_name' => 'string',
      'posted_at' => 'date',
      'reaction_total' => 'integer',
      'status' => 'boolean',
    ];
    foreach ($expected as $field_id => $type) {
      $this->assertArrayHasKey($field_id, $fields, "Field '$field_id' must exist.");
      $this->assertSame($type, $fields[$field_id]->getType(), "Field '$field_id' type mismatch.");
    }
  }

  /**
   * The entity_status and content_access processors are enabled.
   */
  public function testPublishedOnlyProcessorsEnabled(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $processors = $index->getProcessors();
    $processor_ids = array_keys($processors);
    $this->assertContains('entity_status', $processor_ids, 'entity_status processor must be enabled.');
    $this->assertContains('content_access', $processor_ids, 'content_access processor must be enabled.');
  }

  /**
   * The index_directly option is true.
   */
  public function testIndexDirectlyIsTrue(): void {
    $index = Index::load('slack_messages');
    $this->assertNotNull($index);
    $this->assertTrue((bool) $index->getOption('index_directly'));
  }

}
