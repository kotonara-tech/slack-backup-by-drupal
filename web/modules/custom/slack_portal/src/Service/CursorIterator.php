<?php

declare(strict_types=1);

/**
 * @file
 * Pure Slack cursor-pagination iterator — no I/O, no network, no sleep.
 */

namespace Drupal\slack_portal\Service;

/**
 * Iterates over Slack cursor-paginated API responses.
 *
 * Calls a caller-supplied $pageFetcher closure for each page, passing the
 * cursor from the previous page's next_cursor field. Stops as soon as
 * next_cursor is absent, empty-string, or null — which mirrors Slack's
 * response_metadata.next_cursor loop.
 *
 * @see https://api.slack.com/docs/pagination
 */
final class CursorIterator {

  /**
   * Iterates pages by repeatedly calling $pageFetcher until no cursor remains.
   *
   * @param callable(string): array{items: array<int,mixed>, next_cursor?: string} $pageFetcher
   *   A closure that receives the current cursor ('' for the first call) and
   *   returns an array with at minimum an 'items' key (array) and an optional
   *   'next_cursor' key (string). When next_cursor is absent, empty, or null
   *   the iteration stops after yielding the current page's items.
   *
   * @return \Generator<int, array<int, mixed>>
   *   Yields each page's items array (the value of the 'items' key).
   */
  public function pages(callable $pageFetcher): \Generator {
    $cursor = '';

    do {
      /** @var array{items: array<int,mixed>, next_cursor?: string|null} $page */
      $page = $pageFetcher($cursor);

      yield $page['items'];

      $cursor = $page['next_cursor'] ?? '';
    } while ($cursor !== '' && $cursor !== NULL);
  }

}
