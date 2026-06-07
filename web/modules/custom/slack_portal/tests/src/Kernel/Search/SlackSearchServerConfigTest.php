<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test asserting the Search API DB server config installs correctly.
 */

namespace Drupal\Tests\slack_portal\Kernel\Search;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the M3 Search API DB server config (slack_db) installs correctly.
 */
#[Group('slack_portal')]
class SlackSearchServerConfigTest extends KernelTestBase {

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
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // search_api_task entity + search_api_item table required before config;
    // the index config that also lives in slack_portal/config/install triggers
    // item tracking on postSave, so all node/user/taxonomy_term schemas must
    // be present before installConfig(['slack_portal']).
    $this->installEntitySchema('search_api_task');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installConfig(['search_api']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['slack_portal']);
  }

  /**
   * The slack_db server loads after config install.
   */
  public function testServerLoads(): void {
    $server = Server::load('slack_db');
    $this->assertNotNull($server, 'Server slack_db must exist after config install.');
  }

  /**
   * The backend plugin ID is search_api_db.
   */
  public function testBackendIsSearchApiDb(): void {
    $server = Server::load('slack_db');
    $this->assertNotNull($server);
    $this->assertSame('search_api_db', $server->getBackendId());
  }

  /**
   * The DB backend supports facets.
   */
  public function testBackendSupportsFacets(): void {
    $server = Server::load('slack_db');
    $this->assertNotNull($server);
    $this->assertTrue(
      $server->supportsFeature('search_api_facets'),
      'search_api_db backend must support search_api_facets.',
    );
  }

}
