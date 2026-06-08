<?php

declare(strict_types=1);

/**
 * @file
 * Abstract base class shared by all slack_portal JSON:API Functional tests.
 */

namespace Drupal\Tests\slack_portal\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use Drupal\user\Entity\Role;

/**
 * Shared base for slack_portal JSON:API Functional tests.
 *
 * Provides the common $modules list, $defaultTheme, the
 * JsonApiRequestTestTrait, and two protected helpers used by every subclass:
 *   - grantAnonAccessContent()
 *   - buildSearchIndex()
 */
abstract class SlackJsonApiFunctionalTestBase extends BrowserTestBase {

  use JsonApiRequestTestTrait;

  /**
   * {@inheritdoc}
   *
   * BrowserTestBase resolves info.yml deps but slack_portal.info.yml omits
   * several implicit core field/type deps that its config/install requires
   * (text, datetime, taxonomy, file). We list them here so the full Drupal
   * site install succeeds. 'slack_portal' pulls in jsonapi, migrate_plus, key,
   * encrypt, real_aes, etc. via info.yml resolution.
   *
   * @var string[]
   */
  protected static $modules = [
    'node',
    'text',
    'field',
    'datetime',
    'taxonomy',
    'file',
    'filter',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Grants the anonymous role the "access content" core permission.
   */
  protected function grantAnonAccessContent(): void {
    /** @var \Drupal\user\Entity\Role $anon_role */
    $anon_role = Role::load(AccountInterface::ANONYMOUS_ROLE);
    $anon_role->grantPermission('access content');
    $anon_role->save();
  }

  /**
   * Builds the slack_messages Search API index and rebuilds routes if needed.
   *
   * Tracks all existing items, indexes them, and calls rebuildIfNeeded() so
   * jsonapi_search_api routes become available immediately.
   */
  protected function buildSearchIndex(): void {
    $index = Index::load('slack_messages');
    assert($index instanceof IndexInterface);
    $this->container
      ->get('search_api.index_task_manager')
      ->addItemsAll($index);
    $index->indexItems();
    $this->container->get('router.builder')->rebuildIfNeeded();
  }

}
