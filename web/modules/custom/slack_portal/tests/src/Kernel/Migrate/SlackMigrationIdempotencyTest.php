<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests: migration idempotency, track_changes re-import, privacy.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\node\Entity\Node;
use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies re-running migrations is idempotent and edits re-import in place.
 */
#[Group('slack_portal')]
class SlackMigrationIdempotencyTest extends SlackMigrateKernelTestBase {

  /**
   * Loads slack_message nodes by property, resetting the static cache first.
   *
   * @param array<string, mixed> $props
   *   Property/value pairs to filter on.
   *
   * @return \Drupal\node\Entity\Node[]
   *   The loaded nodes, keyed by entity ID.
   */
  private function loadFreshNodes(array $props): array {
    /** @var \Drupal\node\NodeStorage $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadByProperties($props);
    return $nodes;
  }

  /**
   * Returns the count of taxonomy terms matching the given properties.
   *
   * @param array<string, mixed> $props
   *   Property/value pairs to filter on.
   */
  private function countFreshTerms(array $props): int {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $storage->resetCache();
    return count($storage->loadByProperties($props));
  }

  /**
   * Returns the slack_messages idmap processed (mapped) row count.
   */
  private function messagesIdMapCount(): int {
    $migration = \Drupal::service('plugin.manager.migration')
      ->createInstance('slack_messages');
    return $migration->getIdMap()->processedCount();
  }

  /**
   * Loads the single slack_message node with the given field_slack_ts.
   *
   * Overrides the base to reset the entity cache before loading.
   */
  protected function loadBySlackTs(string $ts): Node {
    $nodes = $this->loadFreshNodes([
      'type' => 'slack_message',
      'field_slack_ts' => $ts,
    ]);
    $node = reset($nodes);
    $this->assertNotFalse($node, "No node for slack_ts $ts");
    return $node;
  }

  /**
   * Re-running the whole migration set changes no counts or ids.
   */
  public function testImportIsIdempotent(): void {
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    $firstNodes = $this->loadFreshNodes(['type' => 'slack_message']);
    $this->assertCount(10, $firstNodes);
    $nidByTs = [];
    foreach ($firstNodes as $node) {
      $nidByTs[$node->get('field_slack_ts')->value] = $node->id();
    }
    $channels1 = $this->countFreshTerms(['vid' => 'slack_channels']);
    $users1 = $this->countFreshTerms(['vid' => 'slack_users']);
    $idmap1 = $this->messagesIdMapCount();

    // Private message is unpublished; public is published.
    $this->assertFalse($this->loadBySlackTs('1700001000.000001')->isPublished());
    $this->assertTrue($this->loadBySlackTs('1700000001.000001')->isPublished());

    // Second run.
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    $secondNodes = $this->loadFreshNodes(['type' => 'slack_message']);
    $this->assertCount(10, $secondNodes, 'Re-import must not create duplicate nodes.');
    foreach ($secondNodes as $node) {
      $ts = $node->get('field_slack_ts')->value;
      $this->assertSame($nidByTs[$ts], $node->id(), "nid changed for $ts");
    }
    $this->assertSame($channels1, $this->countFreshTerms(['vid' => 'slack_channels']));
    $this->assertSame($users1, $this->countFreshTerms(['vid' => 'slack_users']));
    $this->assertSame($idmap1, $this->messagesIdMapCount(), 'idmap row count must be stable.');
  }

  /**
   * Editing a message's text + edited flag re-imports it in place.
   */
  public function testEditedMessageReimportsInPlace(): void {
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    $m1 = $this->loadBySlackTs('1700000001.000001');
    $this->assertSame('Hello public channel', $m1->get('field_body')->value);
    $this->assertFalse((bool) $m1->get('field_edited')->value);
    $originalNid = $m1->id();

    // Mutate the private:// copy of the channel file (NOT the source fixture).
    $uri = 'private://slack_archive/latest/channels/C_PUB001.json';
    $data = json_decode(file_get_contents($uri), TRUE);
    foreach ($data['messages'] as $i => $message) {
      if (($message['slack_ts'] ?? '') === '1700000001.000001') {
        $data['messages'][$i]['text'] = 'Hello EDITED';
        $data['messages'][$i]['edited'] = TRUE;
      }
    }
    file_put_contents($uri, json_encode($data));

    // Re-run (track_changes detects the changed row hash).
    $this->executeMigrations(['slack_channels', 'slack_users', 'slack_files', 'slack_messages']);

    $this->assertCount(10, $this->loadFreshNodes(['type' => 'slack_message']));
    $updated = $this->loadBySlackTs('1700000001.000001');
    $this->assertSame($originalNid, $updated->id(), 'Same node updated in place.');
    $this->assertSame('Hello EDITED', $updated->get('field_body')->value);
    $this->assertTrue((bool) $updated->get('field_edited')->value);
  }

}
