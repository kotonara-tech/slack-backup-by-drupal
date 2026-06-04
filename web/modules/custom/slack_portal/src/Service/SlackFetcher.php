<?php

declare(strict_types=1);

/**
 * @file
 * Cursor-paginated fetcher for Slack conversations, channels, and users.
 */

namespace Drupal\slack_portal\Service;

use JoliCode\Slack\Api\Client as SlackApiClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches Slack data using cursor-pagination via CursorIterator.
 *
 * Each fetch method receives an already-built SlackApiClient (the caller
 * constructs it once via SlackClientFactory) and returns a Generator that
 * yields individual item arrays (messages, channels, or users). 429 retries
 * are handled transparently by the GuzzleRetryMiddleware inside the client.
 *
 * FETCH_RESPONSE mode is used to obtain the raw PSR-7 response. Because
 * SlackErrorPlugin (a php-http plugin) calls getContents() on the body stream
 * before returning the response, the stream cursor is at EOF. Casting the body
 * to string with (string) rewinds the seekable Guzzle stream via __toString,
 * allowing the JSON to be decoded correctly.
 *
 * Methods NOT implemented here (belong to later ToDos):
 *   - fetchReplies: single conversations.replies call (A7)
 *   - fetchFiles: files.list paginates by page NUMBER, not cursor (A10)
 */
final class SlackFetcher {

  /**
   * Constructs a SlackFetcher.
   *
   * @param \Drupal\slack_portal\Service\CursorIterator $cursor
   *   The cursor-pagination iterator.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (e.g. logger.channel.slack_portal).
   */
  public function __construct(
    private readonly CursorIterator $cursor,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Fetches all messages from a channel via conversations.history (paginated).
   *
   * @param \JoliCode\Slack\Api\Client $client
   *   A configured Slack API client (already built by SlackClientFactory).
   * @param string $channelId
   *   The Slack channel ID to fetch history for.
   * @param int|null $oldest
   *   Optional Unix timestamp; only messages after this time are returned.
   *   Pass NULL to retrieve all available history.
   *
   * @return \Generator<int, array<string, mixed>>
   *   Yields each message array from the response.
   */
  public function fetchHistory(
    SlackApiClient $client,
    string $channelId,
    ?int $oldest,
  ): \Generator {
    $this->logger->debug(
      'Fetching history for channel {channel}',
      ['channel' => $channelId],
    );

    $pageFetcher = function (string $cursor) use ($client, $channelId, $oldest): array {
      $params = ['channel' => $channelId, 'limit' => 200];
      if ($cursor !== '') {
        $params['cursor'] = $cursor;
      }
      if ($oldest !== NULL) {
        $params['oldest'] = (string) $oldest;
      }
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $client->conversationsHistory($params, SlackApiClient::FETCH_RESPONSE);
      return $this->decodePage($response, 'messages');
    };

    yield from $this->yieldPages($pageFetcher);
  }

  /**
   * Fetches all conversations via cursor-paginated conversations.list.
   *
   * @param \JoliCode\Slack\Api\Client $client
   *   A configured Slack API client.
   * @param string $types
   *   Comma-separated channel types, e.g.
   *   'public_channel,private_channel,mpim,im'.
   *
   * @return \Generator<int, array<string, mixed>>
   *   Yields each channel array from the response.
   */
  public function fetchChannels(SlackApiClient $client, string $types): \Generator {
    $this->logger->debug(
      'Fetching channels of types: {types}',
      ['types' => $types],
    );

    $pageFetcher = function (string $cursor) use ($client, $types): array {
      $params = ['types' => $types, 'limit' => 200];
      if ($cursor !== '') {
        $params['cursor'] = $cursor;
      }
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $client->conversationsList($params, SlackApiClient::FETCH_RESPONSE);
      return $this->decodePage($response, 'channels');
    };

    yield from $this->yieldPages($pageFetcher);
  }

  /**
   * Fetches all users via cursor-paginated users.list.
   *
   * @param \JoliCode\Slack\Api\Client $client
   *   A configured Slack API client.
   *
   * @return \Generator<int, array<string, mixed>>
   *   Yields each user/member array from the response.
   */
  public function fetchUsers(SlackApiClient $client): \Generator {
    $this->logger->debug('Fetching users.');

    $pageFetcher = function (string $cursor) use ($client): array {
      $params = ['limit' => 200];
      if ($cursor !== '') {
        $params['cursor'] = $cursor;
      }
      /** @var \Psr\Http\Message\ResponseInterface $response */
      $response = $client->usersList($params, SlackApiClient::FETCH_RESPONSE);
      return $this->decodePage($response, 'members');
    };

    yield from $this->yieldPages($pageFetcher);
  }

  /**
   * Decodes a FETCH_RESPONSE body and extracts items + next_cursor.
   *
   * SlackErrorPlugin reads the PSR-7 stream with getContents(), leaving the
   * pointer at EOF. Casting to string via (string) calls Stream::__toString,
   * which rewinds the seekable stream before reading, so the JSON is intact.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   A raw PSR-7 response obtained via FETCH_RESPONSE mode.
   * @param string $itemsKey
   *   The top-level JSON key that holds the items array
   *   (e.g. 'messages', 'channels', 'members').
   *
   * @return array{items: array<int, array<string, mixed>>, next_cursor: string}
   *   Associative array with 'items' and 'next_cursor' keys, as expected by
   *   CursorIterator::pages().
   */
  private function decodePage(ResponseInterface $response, string $itemsKey): array {
    /** @var array<string, mixed> $data */
    $data = json_decode((string) $response->getBody(), TRUE) ?? [];
    return [
      'items' => $data[$itemsKey] ?? [],
      'next_cursor' => $data['response_metadata']['next_cursor'] ?? '',
    ];
  }

  /**
   * Iterates cursor pages and yields each item individually.
   *
   * @param callable(string): array{items: array<int,mixed>, next_cursor?: string} $pageFetcher
   *   A closure matching the CursorIterator::pages() contract.
   *
   * @return \Generator<int, array<string, mixed>>
   *   Yields each item from every page.
   */
  private function yieldPages(callable $pageFetcher): \Generator {
    foreach ($this->cursor->pages($pageFetcher) as $page) {
      foreach ($page as $item) {
        yield $item;
      }
    }
  }

}
