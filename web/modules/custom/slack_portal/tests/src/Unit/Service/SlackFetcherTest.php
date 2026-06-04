<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SlackFetcher — cursor-paginated fetch + 429 retry.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use JoliCode\Slack\Api\Client;
use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\CursorIterator;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests SlackFetcher cursor-paginated fetching and transparent 429 retry.
 *
 * All tests are small/Unit: no DB, no filesystem, no real network.
 * The Slack API is driven via GuzzleHttp MockHandler and CursorIterator.
 *
 * @covers \Drupal\slack_portal\Service\SlackFetcher
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackFetcher::class)]
#[Group('slack_portal')]
class SlackFetcherTest extends UnitTestCase {

  /**
   * Test-only placeholder token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test'; // pragma: allowlist secret

  /**
   * Builds a Guzzle client using MockHandler with optional history middleware.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mock
   *   The mock handler to drive.
   * @param array<array{request:\Psr\Http\Message\RequestInterface,response:mixed}> $history
   *   A by-reference array Guzzle populates with request/response pairs.
   *
   * @return \JoliCode\Slack\Api\Client
   *   A configured Slack API client backed by the MockHandler.
   */
  private function buildClient(MockHandler $mock, array &$history = []): Client {
    // Build through SlackClientFactory so retry middleware is included.
    // We cannot add history middleware after the factory builds the stack, so
    // we push it onto a wrapping stack before handing off to the factory.
    $historyMiddleware = Middleware::history($history);
    $stack = HandlerStack::create($mock);
    $stack->push($historyMiddleware, 'history');

    return (new SlackClientFactory())->createWithHandler($stack, self::TEST_TOKEN);
  }

  /**
   * Tests fetchHistory paginates across 2 pages and transparently retries 429.
   *
   * Given a MockHandler queued with:
   *   - page 1: 200 with messages [ts:1.0, ts:2.0], next_cursor=c1
   *   - page 2: 429 ratelimited (Retry-After: 0)
   *   - page 2 (retry): 200 with messages [ts:3.0], next_cursor=''
   * When fetchHistory() is iterated to completion,
   * Then all 3 messages are yielded,
   * And the MockHandler is empty (all 3 responses consumed),
   * And the second successful request's query contains cursor=c1.
   */
  public function testFetchHistoryPaginatesAndRetriesOn429(): void {
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"messages":[{"ts":"1.0","text":"a"},{"ts":"2.0","text":"b"}],"has_more":true,"response_metadata":{"next_cursor":"c1"}}'),
      new Response(429, ['Retry-After' => '0'], '{"ok":false,"error":"ratelimited"}'),
      new Response(200, [], '{"ok":true,"messages":[{"ts":"3.0","text":"c"}],"has_more":false,"response_metadata":{"next_cursor":""}}'),
    ]);

    $client = $this->buildClient($mock, $history);
    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    $messages = [];
    foreach ($fetcher->fetchHistory($client, 'C1', NULL) as $msg) {
      $messages[] = $msg;
    }

    // All 3 messages collected.
    $this->assertCount(3, $messages, 'All 3 messages from 2 pages must be yielded.');
    $this->assertSame('1.0', $messages[0]['ts']);
    $this->assertSame('2.0', $messages[1]['ts']);
    $this->assertSame('3.0', $messages[2]['ts']);

    // MockHandler exhausted — the 429 was retried transparently.
    $this->assertCount(0, $mock, 'All 3 MockHandler responses must be consumed (429 was retried).');

    // The second successful request carried cursor=c1.
    // History contains: [page1-request, 429-request, retry-request].
    // The last request is the page-2 retry; it must include cursor=c1.
    $this->assertGreaterThanOrEqual(2, count($history), 'At least 2 requests must have been recorded.');
    $lastRequest = end($history)['request'];
    $this->assertNotNull($lastRequest);
    parse_str($lastRequest->getUri()->getQuery(), $queryParams);
    $this->assertSame('c1', $queryParams['cursor'] ?? NULL, "Last page request must carry cursor=c1 in query string. Got: {$lastRequest->getUri()->getQuery()}");
  }

  /**
   * Tests fetchChannels terminates on empty cursor and returns all channels.
   *
   * Given a MockHandler queued with:
   *   - page 1: 200 with channels [id:C1, id:C2], next_cursor=cx
   *   - page 2: 200 with channels [id:C3], next_cursor=''
   * When fetchChannels() is iterated to completion,
   * Then all 3 channels are yielded and the MockHandler is empty.
   */
  public function testFetchChannelsReturnsBothPages(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"channels":[{"id":"C1","name":"general"},{"id":"C2","name":"random"}],"response_metadata":{"next_cursor":"cx"}}'),
      new Response(200, [], '{"ok":true,"channels":[{"id":"C3","name":"private"}],"response_metadata":{"next_cursor":""}}'),
    ]);

    $client = $this->buildClient($mock);
    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    $channels = [];
    foreach ($fetcher->fetchChannels($client, 'public_channel,private_channel,mpim,im') as $ch) {
      $channels[] = $ch;
    }

    $this->assertCount(3, $channels, 'All 3 channels across 2 pages must be yielded.');
    $this->assertSame('C1', $channels[0]['id']);
    $this->assertSame('C2', $channels[1]['id']);
    $this->assertSame('C3', $channels[2]['id']);
    $this->assertCount(0, $mock, 'Both responses must be consumed.');
  }

  /**
   * Tests fetchUsers returns all users from a single page with no next_cursor.
   *
   * Given a MockHandler queued with one 200 page of users and no next_cursor,
   * When fetchUsers() is iterated,
   * Then both users are returned and the MockHandler is empty.
   */
  public function testFetchUsersSinglePageReturnsAllUsers(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"members":[{"id":"U1","name":"alice"},{"id":"U2","name":"bob"}],"response_metadata":{"next_cursor":""}}'),
    ]);

    $client = $this->buildClient($mock);
    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    $users = [];
    foreach ($fetcher->fetchUsers($client) as $user) {
      $users[] = $user;
    }

    $this->assertCount(2, $users, 'Both users must be yielded from a single page.');
    $this->assertSame('U1', $users[0]['id']);
    $this->assertSame('U2', $users[1]['id']);
    $this->assertCount(0, $mock, 'The single response must be consumed.');
  }

  /**
   * Tests fetchHistory passes the oldest parameter in the query string.
   *
   * Given a MockHandler with one page and an oldest timestamp,
   * When fetchHistory() is called with a non-null $oldest,
   * Then the outgoing request query contains oldest=<value>.
   */
  public function testFetchHistoryPassesOldestParam(): void {
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"messages":[{"ts":"9.0"}],"response_metadata":{"next_cursor":""}}'),
    ]);

    $client = $this->buildClient($mock, $history);
    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    iterator_to_array($fetcher->fetchHistory($client, 'C9', 1700000000));

    $this->assertCount(1, $history, 'One request must have been made.');
    parse_str($history[0]['request']->getUri()->getQuery(), $queryParams);
    $this->assertSame('1700000000', $queryParams['oldest'] ?? NULL, 'oldest query param must be set.');
    $this->assertSame('C9', $queryParams['channel'] ?? NULL, 'channel query param must be set.');
  }

  /**
   * Tests fetchFiles paginates across 2 pages using page-number pagination.
   *
   * Given a MockHandler queued with:
   *   - page 1: 200 with files [id:F1, id:F2], paging{page:1, pages:2}
   *   - page 2: 200 with files [id:F3], paging{page:2, pages:2}
   * When fetchFiles() is iterated to completion,
   * Then all 3 file ids are returned,
   * And the MockHandler is empty (both pages fetched),
   * And the 2nd request carries page=2 in the query string.
   */
  public function testFetchFilesPaginatesByPageNumber(): void {
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"files":[{"id":"F1"},{"id":"F2"}],"paging":{"count":2,"total":3,"page":1,"pages":2}}'),
      new Response(200, [], '{"ok":true,"files":[{"id":"F3"}],"paging":{"count":2,"total":3,"page":2,"pages":2}}'),
    ]);

    $historyMiddleware = Middleware::history($history);
    $stack = HandlerStack::create($mock);
    $stack->push($historyMiddleware, 'history');
    // phpcs:ignore Drupal.Commenting.InlineComment.InvalidEndChar
    $client = (new SlackClientFactory())->createWithHandler($stack, self::TEST_TOKEN); // pragma: allowlist secret

    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    $files = [];
    foreach ($fetcher->fetchFiles($client, NULL) as $file) {
      $files[] = $file;
    }

    // All 3 file ids returned.
    $this->assertCount(3, $files, 'All 3 files from 2 pages must be yielded.');
    $this->assertSame('F1', $files[0]['id']);
    $this->assertSame('F2', $files[1]['id']);
    $this->assertSame('F3', $files[2]['id']);

    // Both pages fetched — MockHandler exhausted.
    $this->assertCount(0, $mock, 'Both responses must be consumed.');

    // The 2nd request must carry page=2 in the query string.
    $this->assertCount(2, $history, 'Exactly 2 requests must have been recorded.');
    parse_str($history[1]['request']->getUri()->getQuery(), $queryParams);
    $this->assertSame('2', $queryParams['page'] ?? NULL, 'Second request must carry page=2.');
  }

  /**
   * Tests fetchFiles with a single page makes exactly one request.
   *
   * Given a MockHandler with one page where paging.pages=1,
   * When fetchFiles() is iterated,
   * Then files from that single page are returned and the MockHandler is empty.
   */
  public function testFetchFilesSinglePageYieldsOnce(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"files":[{"id":"F10"},{"id":"F11"}],"paging":{"count":2,"total":2,"page":1,"pages":1}}'),
    ]);

    $client = $this->buildClient($mock);
    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());

    $files = [];
    foreach ($fetcher->fetchFiles($client, 1700000000) as $file) {
      $files[] = $file;
    }

    $this->assertCount(2, $files, 'Both files from single page must be yielded.');
    $this->assertSame('F10', $files[0]['id']);
    $this->assertSame('F11', $files[1]['id']);
    $this->assertCount(0, $mock, 'The single response must be consumed.');
  }

}
