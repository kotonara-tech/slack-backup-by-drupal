<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: Search API index build, published-only filter, full-text query.
 */

namespace Drupal\Tests\slack_portal\Kernel\Search;

use Drupal\search_api\Entity\Index;
use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the slack_messages index only indexes published nodes.
 *
 * Given: slack_portal config is installed (A2/A3) and fixtures are present.
 * When:  all four migrations run and the index is built.
 * Then:  only published (public-channel) nodes appear in results, and
 *        fulltext search on a published body hits; a word appearing only in
 *        an unpublished body returns zero results.
 *
 * @covers \Drupal\slack_portal\Plugin\migrate\source\SlackCanonicalMessages
 */
#[Group('slack_portal')]
class SlackSearchIndexQueryTest extends SlackMigrateKernelTestBase {

  /**
   * Published-only indexing and fulltext search work correctly end-to-end.
   *
   * Given: 10 migrated slack_message nodes (public-channel = published,
   *        private/im/mpim = unpublished).
   * When:  the slack_messages Search API index is built.
   * Then:  only published nodes are indexed; fulltext matches published bodies;
   *        words that appear only in unpublished bodies return zero results.
   */
  public function testIndexesOnlyPublishedAndFulltextWorks(): void {
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    $index = Index::load('slack_messages');
    $this->assertNotNull($index);

    // Re-track all items so migrated nodes are queued, then index them.
    // The index_task_manager tracks all existing entities so indexItems() has
    // something to work with after migrations created nodes outside the normal
    // entity-insert hooks path.
    $task_manager = \Drupal::getContainer()->get('search_api.index_task_manager');
    $task_manager->addItemsAll($index);
    // Reload after tracking changes.
    $index = Index::load('slack_messages');
    $index->indexItems();

    // Bypass content_access grants check (node_access table has no grants in
    // Kernel context) so only the entity_status / published filter applies.
    // Note: setOption() returns the old value, not $this, so we cannot chain.
    $query_all = $index->query();
    $query_all->setOption('search_api_bypass_access', TRUE);
    $all = $query_all->execute();
    $publishedCount = (int) $all->getResultCount();
    $this->assertGreaterThan(0, $publishedCount, 'At least one published message must be indexed.');

    // Exact published count: all public-channel messages are published.
    // Fixture C_PUB001 yields 7 deduped rows (6 top-level + reply 011).
    $this->assertSame(7, $publishedCount, 'Only published (public-channel) messages are indexed.');

    // Fulltext hit on a word in the published body of ts=1700000001.
    $query_hit = $index->query();
    $query_hit->setOption('search_api_bypass_access', TRUE);
    $query_hit->keys('public');
    $hit = $query_hit->execute();
    $this->assertSame(1, (int) $hit->getResultCount(), '"public" should match exactly one published message.');

    // A word appearing ONLY in an unpublished (im) body must not be searchable.
    $query_miss = $index->query();
    $query_miss->setOption('search_api_bypass_access', TRUE);
    $query_miss->keys('direct');
    $miss = $query_miss->execute();
    $this->assertSame(0, (int) $miss->getResultCount(), 'Unpublished bodies must not be indexed.');
  }

}
