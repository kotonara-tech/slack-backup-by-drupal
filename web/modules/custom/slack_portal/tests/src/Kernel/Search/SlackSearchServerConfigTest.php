<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test asserting the Search API DB server config installs correctly.
 */

namespace Drupal\Tests\slack_portal\Kernel\Search;

use Drupal\search_api\Entity\Server;
use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the M3 Search API DB server config (slack_db) installs correctly.
 */
#[Group('slack_portal')]
class SlackSearchServerConfigTest extends SlackMigrateKernelTestBase {

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
