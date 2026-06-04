<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for CursorIterator.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\CursorIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CursorIterator implements Slack cursor-pagination loop correctly.
 *
 * @covers \Drupal\slack_portal\Service\CursorIterator
 */
#[CoversClass(\Drupal\slack_portal\Service\CursorIterator::class)]
#[Group('slack_portal')]
class CursorIteratorTest extends UnitTestCase {

  /**
   * Tests multi-page pagination fetches pages in order and stops on empty cursor.
   *
   * Given a pageFetcher that returns two pages,
   * When pages() is called and the generator is fully consumed,
   * Then exactly 2 pages are yielded with the correct items,
   * the fetcher receives cursors ['', 'c1'] in order,
   * and the fetcher is NOT called a 3rd time after the empty next_cursor.
   */
  public function testMultiPagePaginationYieldsTwoPagesAndStopsOnEmptyCursor(): void {
    $calledWith = [];
    $pageFetcher = static function (string $cursor) use (&$calledWith): array {
      $calledWith[] = $cursor;
      if ($cursor === '') {
        return ['items' => ['a1', 'a2'], 'next_cursor' => 'c1'];
      }
      // cursor === 'c1'
      return ['items' => ['b1'], 'next_cursor' => ''];
    };

    $iterator = new CursorIterator();
    $pages = [];
    foreach ($iterator->pages($pageFetcher) as $pageItems) {
      $pages[] = $pageItems;
    }

    // Assert: exactly 2 pages yielded.
    $this->assertCount(2, $pages, 'Expected exactly 2 pages to be yielded.');

    // Assert: cursors passed in correct order.
    $this->assertSame(['', 'c1'], $calledWith, 'Fetcher must be called with cursors [\'\', \'c1\'] in order.');

    // Assert: items flattened equal ['a1', 'a2', 'b1'].
    $allItems = array_merge(...$pages);
    $this->assertSame(['a1', 'a2', 'b1'], $allItems, 'All items across pages must equal [\'a1\', \'a2\', \'b1\'].');

    // Assert: fetcher called exactly 2 times (no 3rd call after empty cursor).
    $this->assertCount(2, $calledWith, 'Fetcher must be invoked exactly 2 times (no 3rd call after empty next_cursor).');
  }

  /**
   * Tests single-page pagination when next_cursor is empty string.
   *
   * Given a pageFetcher that returns one page with next_cursor = '',
   * When pages() is called and the generator is fully consumed,
   * Then exactly 1 page is yielded and the fetcher is called exactly once.
   */
  public function testSinglePageWithEmptyNextCursorYieldsOnce(): void {
    $callCount = 0;
    $pageFetcher = static function (string $cursor) use (&$callCount): array {
      $callCount++;
      return ['items' => ['x'], 'next_cursor' => ''];
    };

    $iterator = new CursorIterator();
    $pages = [];
    foreach ($iterator->pages($pageFetcher) as $pageItems) {
      $pages[] = $pageItems;
    }

    $this->assertCount(1, $pages, 'Expected exactly 1 page when next_cursor is empty string.');
    $this->assertSame([['x']], $pages, 'The single page must contain [\'x\'].');
    $this->assertSame(1, $callCount, 'Fetcher must be called exactly once.');
  }

  /**
   * Tests single-page pagination when next_cursor key is absent entirely.
   *
   * Given a pageFetcher that returns a page without a next_cursor key,
   * When pages() is called and the generator is fully consumed,
   * Then exactly 1 page is yielded and the fetcher is called exactly once.
   */
  public function testSinglePageWithAbsentNextCursorKeyYieldsOnce(): void {
    $callCount = 0;
    $pageFetcher = static function (string $cursor) use (&$callCount): array {
      $callCount++;
      // No 'next_cursor' key present at all.
      return ['items' => ['x']];
    };

    $iterator = new CursorIterator();
    $pages = [];
    foreach ($iterator->pages($pageFetcher) as $pageItems) {
      $pages[] = $pageItems;
    }

    $this->assertCount(1, $pages, 'Expected exactly 1 page when next_cursor key is absent.');
    $this->assertSame([['x']], $pages, 'The single page must contain [\'x\'].');
    $this->assertSame(1, $callCount, 'Fetcher must be called exactly once.');
  }

}
