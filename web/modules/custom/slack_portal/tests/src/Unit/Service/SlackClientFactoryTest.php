<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SlackClientFactory.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\SlackClientFactory;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use JoliCode\Slack\Api\Client as SlackApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SlackClientFactory builds a correctly authenticated Slack API client.
 *
 * @covers \Drupal\slack_portal\Service\SlackClientFactory
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackClientFactory::class)]
#[Group('slack_portal')]
class SlackClientFactoryTest extends UnitTestCase {

  /**
   * Tests that createWithHandler returns a JoliCode Slack API Client instance.
   *
   * Given a MockHandler and a token,
   * When createWithHandler() is called,
   * Then the returned value is an instance of JoliCode\Slack\Api\Client.
   */
  public function testCreateWithHandlerReturnsSlackApiClient(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    $factory = new SlackClientFactory();
    $token = 'xoxp-test-token'; // pragma: allowlist secret
    $client = $factory->createWithHandler($guzzle, $token);

    $this->assertInstanceOf(SlackApiClient::class, $client);
  }

  /**
   * Tests that requests carry the Bearer Authorization header and target slack.com.
   *
   * Given a MockHandler queued with a successful response,
   * When the client built by createWithHandler() invokes conversationsList(),
   * Then the captured request has Authorization: Bearer <token> and host slack.com.
   */
  public function testRequestHasBearerAuthorizationAndSlackHost(): void {
    $mock = new MockHandler([
      new Response(200, [], '{"ok":true,"channels":[]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $guzzle = new GuzzleClient(['handler' => $stack]);

    $factory = new SlackClientFactory();
    $token = 'xoxp-test-token'; // pragma: allowlist secret
    $client = $factory->createWithHandler($guzzle, $token);

    // Invoke an API endpoint to trigger an HTTP request.
    $client->conversationsList();

    $lastRequest = $mock->getLastRequest();
    $this->assertNotNull($lastRequest, 'A request must have been captured by the MockHandler.');
    $this->assertSame(
      'Bearer xoxp-test-token', // pragma: allowlist secret
      $lastRequest->getHeaderLine('Authorization'),
      'Authorization header must contain the Bearer token.'
    );
    $this->assertSame(
      'slack.com',
      $lastRequest->getUri()->getHost(),
      'Request URI host must be slack.com.'
    );
  }

}
