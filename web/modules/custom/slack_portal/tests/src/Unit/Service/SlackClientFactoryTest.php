<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SlackClientFactory.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\SlackClientFactory;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use JoliCode\Slack\Api\Client as SlackApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;

/**
 * Tests SlackClientFactory builds a correctly authenticated Slack API client.
 *
 * @covers \Drupal\slack_portal\Service\SlackClientFactory
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackClientFactory::class)]
#[Group('slack_portal')]
class SlackClientFactoryTest extends UnitTestCase {

  /**
   * Fake Slack user token used only in tests (never a real credential).
   *
   * The value is a test-only placeholder; the pragma on the const line
   * prevents detect-secrets and check_no_archive_committed.sh from flagging
   * it as a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test-token'; // pragma: allowlist secret

  /**
   * Tests that createWithHandler returns a JoliCode Slack API Client instance.
   *
   * Given a MockHandler callable and a token,
   * When createWithHandler() is called,
   * Then the returned value is an instance of JoliCode\Slack\Api\Client.
   */
  public function testCreateWithHandlerReturnsSlackApiClient(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);

    $factory = new SlackClientFactory();
    $client = $factory->createWithHandler($mock, self::TEST_TOKEN);

    $this->assertInstanceOf(SlackApiClient::class, $client);
  }

  /**
   * Tests requests carry the Bearer Authorization header and target slack.com.
   *
   * Given a MockHandler queued with a successful response,
   * When the client built by createWithHandler() invokes conversationsList(),
   * Then the request has Authorization: Bearer <token> and host slack.com.
   */
  public function testRequestHasBearerAuthorizationAndSlackHost(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);

    $factory = new SlackClientFactory();
    $client = $factory->createWithHandler($mock, self::TEST_TOKEN);

    // Invoke an API endpoint to trigger an HTTP request.
    $client->conversationsList();

    $lastRequest = $mock->getLastRequest();
    $this->assertNotNull($lastRequest, 'A request must have been captured by the MockHandler.');
    $this->assertSame(
      'Bearer ' . self::TEST_TOKEN,
      $lastRequest->getHeaderLine('Authorization'),
      'Authorization header must contain the Bearer token.'
    );
    $this->assertSame(
      'slack.com',
      $lastRequest->getUri()->getHost(),
      'Request URI host must be slack.com.'
    );
  }

  /**
   * Tests that a 429 response is retried and the next 200 response succeeds.
   *
   * Given a MockHandler queued with a 429 then a 200,
   * When the client built by createWithHandler() invokes conversationsList(),
   * Then no exception is thrown, a successful result is returned,
   * And both responses are consumed (the 429 was retried).
   */
  public function testRetriesOn429ThenSucceeds(): void {
    $mock = new MockHandler([
      new Response(429, ['Retry-After' => '0'], '{"ok":false,"error":"ratelimited"}'),
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);

    $factory = new SlackClientFactory();
    $client = $factory->createWithHandler($mock, self::TEST_TOKEN);

    // Should not throw — retry middleware must intercept the 429 and re-send.
    $result = $client->conversationsList();

    $this->assertCount(0, $mock, 'Both responses must be consumed, confirming the 429 was retried.');
    $this->assertNotNull($result, 'A successful result must be returned after retry.');
  }

  /**
   * Tests that the retry delay is capped by max_allowable_timeout_secs.
   *
   * Given a MockHandler queued with a 429 (Retry-After: 3600) then a 200,
   * And retryOptions overriding max_allowable_timeout_secs to 0.001 seconds,
   * When the client invokes conversationsList(),
   * Then the on_retry_callback receives a delay clamped to <= 0.001 seconds,
   * So the test completes in milliseconds, not hours.
   */
  public function testRetryDelayIsCappedByMaxAllowableTimeout(): void {
    $mock = new MockHandler([
      new Response(429, ['Retry-After' => '3600'], '{"ok":false,"error":"ratelimited"}'),
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);

    $capturedDelay = NULL;

    // on_retry_callback (vendor): (int $count, float $delay,
    // RequestInterface &$req, array &$opts, ?Response $res, ?Throwable $ex)
    $onRetry = static function (
      int $retryCount,
      float $delayTimeout,
      RequestInterface &$request,
      array &$options,
    ) use (&$capturedDelay): void {
      $capturedDelay = $delayTimeout;
      // Force the actual Guzzle delay to 0 so no real sleep occurs.
      $options['delay'] = 0;
    };

    $factory = new SlackClientFactory();
    $client = $factory->createWithHandler($mock, self::TEST_TOKEN, [
      'max_allowable_timeout_secs' => 0.001,
      'on_retry_callback' => $onRetry,
    ]);

    $client->conversationsList();

    $this->assertNotNull($capturedDelay, 'on_retry_callback must have been invoked.');
    $this->assertLessThanOrEqual(
      0.001,
      $capturedDelay,
      'Retry delay must be clamped to max_allowable_timeout_secs (0.001 s).'
    );
    $this->assertCount(0, $mock, 'Both responses must be consumed.');
  }

}
