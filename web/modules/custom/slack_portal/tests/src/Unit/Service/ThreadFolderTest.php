<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for ThreadFolder.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\ThreadFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ThreadFolder folds Slack thread replies onto their parent message.
 *
 * All tests are pure (no I/O, no network, no DB) and run in < 1 s.
 */
#[CoversClass(\Drupal\slack_portal\Service\ThreadFolder::class)]
#[Group('slack_portal')]
class ThreadFolderTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private ThreadFolder $folder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->folder = new ThreadFolder();
  }

  // ---------------------------------------------------------------------------
  // Fixture helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns a minimal canonical message array.
   *
   * @param string $slackTs
   *   The message timestamp (slack_ts).
   * @param string|null $threadTs
   *   The thread timestamp (thread_ts), or NULL for standalones.
   * @param string $text
   *   Optional text to distinguish messages in assertions.
   *
   * @return array<string,mixed>
   */
  private function msg(string $slackTs, ?string $threadTs, string $text = ''): array {
    return [
      'slack_ts' => $slackTs,
      'thread_ts' => $threadTs,
      'text' => $text,
    ];
  }

  // ---------------------------------------------------------------------------
  // Test 1: Basic fold
  // ---------------------------------------------------------------------------

  /**
   * Tests basic folding: parent gets replies, standalone is untouched.
   *
   * Given a thread parent (slack_ts == thread_ts), two replies pointing at it,
   * and one standalone message (thread_ts == null),
   * When fold() is called,
   * Then: top-level count == 2 (parent + standalone),
   *   parent's replies array contains exactly the two reply messages in
   *   ascending slack_ts order,
   *   the reply messages are NOT present at top level,
   *   the standalone message has NO 'replies' key added.
   */
  public function testBasicFoldAttachesRepliesToParentAndRemovesThemFromTopLevel(): void {
    // Arrange.
    $parent = $this->msg('1.0', '1.0', 'parent');
    $reply1 = $this->msg('2.0', '1.0', 'reply1');
    $reply2 = $this->msg('3.0', '1.0', 'reply2');
    $standalone = $this->msg('9.0', null, 'standalone');

    // Act.
    $result = $this->folder->fold([$parent, $reply1, $reply2, $standalone]);

    // Assert: exactly 2 top-level items.
    $this->assertCount(2, $result);

    // Assert: parent is first, carries correct replies.
    $topParent = $result[0];
    $this->assertSame('1.0', $topParent['slack_ts']);
    $this->assertArrayHasKey('replies', $topParent);
    $this->assertCount(2, $topParent['replies']);
    $this->assertSame('2.0', $topParent['replies'][0]['slack_ts']);
    $this->assertSame('3.0', $topParent['replies'][1]['slack_ts']);

    // Assert: standalone is second and has no 'replies' key.
    $topStandalone = $result[1];
    $this->assertSame('9.0', $topStandalone['slack_ts']);
    $this->assertArrayNotHasKey('replies', $topStandalone);

    // Assert: reply slack_ts values do not appear at top level.
    $topLevelTs = array_column($result, 'slack_ts');
    $this->assertNotContains('2.0', $topLevelTs);
    $this->assertNotContains('3.0', $topLevelTs);
  }

  // ---------------------------------------------------------------------------
  // Test 2: Reply order
  // ---------------------------------------------------------------------------

  /**
   * Tests that replies provided out of order come out ascending by slack_ts.
   *
   * Given a thread parent and two replies whose timestamps are reversed
   * in the input array,
   * When fold() is called,
   * Then the replies inside the parent are sorted ascending by slack_ts.
   */
  public function testRepliesAreSortedAscendingBySlackTs(): void {
    // Arrange: replies arrive newest-first.
    $parent = $this->msg('1.0', '1.0', 'parent');
    $replyLater = $this->msg('3.0', '1.0', 'later reply');
    $replyEarlier = $this->msg('2.0', '1.0', 'earlier reply');

    // Act.
    $result = $this->folder->fold([$parent, $replyLater, $replyEarlier]);

    // Assert.
    $this->assertCount(1, $result);
    $replies = $result[0]['replies'];
    $this->assertCount(2, $replies);
    $this->assertSame('2.0', $replies[0]['slack_ts']);
    $this->assertSame('3.0', $replies[1]['slack_ts']);
  }

  // ---------------------------------------------------------------------------
  // Test 3: Orphan reply
  // ---------------------------------------------------------------------------

  /**
   * Tests that an orphan reply (no matching parent) surfaces at top level.
   *
   * Given a reply whose thread_ts has no matching parent message in the input,
   * When fold() is called,
   * Then the orphan reply appears at top level so that no data is silently lost.
   */
  public function testOrphanReplyIsPreservedAtTopLevel(): void {
    // Arrange: no parent with slack_ts == '4.0'.
    $orphan = $this->msg('5.0', '4.0', 'orphan reply');

    // Act.
    $result = $this->folder->fold([$orphan]);

    // Assert: orphan must not be dropped.
    $this->assertCount(1, $result);
    $this->assertSame('5.0', $result[0]['slack_ts']);
  }

  // ---------------------------------------------------------------------------
  // Test 4: No threads — standalones pass through unchanged
  // ---------------------------------------------------------------------------

  /**
   * Tests that all-standalone input passes through with no 'replies' keys added.
   *
   * Given a list of messages where every message has thread_ts == null,
   * When fold() is called,
   * Then the output is identical in count and content,
   * and no 'replies' key is added to any message.
   */
  public function testAllStandaloneMessagesAreReturnedUnchanged(): void {
    // Arrange.
    $messages = [
      $this->msg('1.0', null, 'msg1'),
      $this->msg('2.0', null, 'msg2'),
      $this->msg('3.0', null, 'msg3'),
    ];

    // Act.
    $result = $this->folder->fold($messages);

    // Assert: same count, no 'replies' key on any item.
    $this->assertCount(3, $result);
    foreach ($result as $item) {
      $this->assertArrayNotHasKey('replies', $item);
    }
  }

  // ---------------------------------------------------------------------------
  // Test 5: Input array is not mutated (extra assertion per Green requirement)
  // ---------------------------------------------------------------------------

  /**
   * Tests that the original input array is not mutated by fold().
   *
   * Given a parent with two replies,
   * When fold() is called,
   * Then the original input messages retain their original structure
   * (no 'replies' key added to the original parent element).
   */
  public function testFoldDoesNotMutateInputMessages(): void {
    // Arrange.
    $parent = $this->msg('1.0', '1.0', 'parent');
    $reply = $this->msg('2.0', '1.0', 'reply');
    $input = [$parent, $reply];

    // Act.
    $this->folder->fold($input);

    // Assert: originals untouched.
    $this->assertArrayNotHasKey('replies', $input[0]);
    $this->assertArrayNotHasKey('replies', $input[1]);
  }

}
