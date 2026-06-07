<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for the SlackReactionsToJson migrate process plugin.
 */

namespace Drupal\Tests\slack_portal\Unit\Plugin\migrate\process;

use Drupal\slack_portal\Plugin\migrate\process\SlackReactionsToJson;
use Drupal\Tests\migrate\Unit\process\MigrateProcessTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SlackReactionsToJson encodes a reactions array as JSON.
 *
 * @covers \Drupal\slack_portal\Plugin\migrate\process\SlackReactionsToJson
 */
#[CoversClass(SlackReactionsToJson::class)]
#[Group('slack_portal')]
class SlackReactionsToJsonTest extends MigrateProcessTestCase {

  /**
   * Tests that a non-empty reactions array is JSON-encoded correctly.
   */
  public function testEncodesReactionsArrayToJson(): void {
    $plugin = new SlackReactionsToJson([], 'slack_reactions_to_json', []);
    $reactions = [
      ['name' => 'thumbsup', 'count' => 2, 'users' => ['U_ALICE', 'U_BOB']],
      ['name' => 'tada', 'count' => 1, 'users' => ['U_ALICE']],
    ];

    $result = $plugin->transform(
      $reactions,
      $this->migrateExecutable,
      $this->row,
      'field_reactions',
    );

    $decoded = json_decode((string) $result, TRUE);
    $this->assertIsArray($decoded);
    $this->assertSame('thumbsup', $decoded[0]['name']);
    $this->assertSame(3, $decoded[0]['count'] + $decoded[1]['count']);
  }

  /**
   * Tests that an empty reactions array returns an empty JSON array.
   */
  public function testEmptyReactionsReturnsEmptyJsonArray(): void {
    $plugin = new SlackReactionsToJson([], 'slack_reactions_to_json', []);

    $result = $plugin->transform(
      [],
      $this->migrateExecutable,
      $this->row,
      'field_reactions',
    );

    $this->assertSame('[]', $result);
  }

  /**
   * Tests that a non-array value returns an empty JSON array.
   */
  public function testNonArrayValueReturnsEmptyJsonArray(): void {
    $plugin = new SlackReactionsToJson([], 'slack_reactions_to_json', []);

    $result = $plugin->transform(
      NULL,
      $this->migrateExecutable,
      $this->row,
      'field_reactions',
    );

    $this->assertSame('[]', $result);
  }

}
