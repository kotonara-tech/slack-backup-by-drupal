<?php

declare(strict_types=1);

/**
 * @file
 * Builds a Slack API client with token-based Bearer authentication.
 */

namespace Drupal\slack_portal\Service;

use JoliCode\Slack\Api\Client as SlackApiClient;
use JoliCode\Slack\ClientFactory;
use Psr\Http\Client\ClientInterface;

/**
 * Builds a JoliCode Slack API client with a given HTTP client and token.
 *
 * Separates transport construction from token wiring so tests can inject a
 * pre-configured Guzzle client (with MockHandler) without touching real
 * credentials.
 */
final class SlackClientFactory {

  /**
   * Builds a Slack API client from a PSR-18 HTTP client and a token.
   *
   * @param \Psr\Http\Client\ClientInterface $httpClient
   *   A PSR-18-compatible HTTP client. For tests, pass a GuzzleHttp\Client
   *   built with a HandlerStack wrapping a MockHandler. For production, pass
   *   a GuzzleHttp\Client built with a HandlerStack that includes retry
   *   middleware.
   * @param string $token
   *   A Slack user token (xoxp-) or bot token (xoxb-).
   *   Must NOT be logged or committed. Keep in Key/settings.local.php.
   *
   * @return \JoliCode\Slack\Api\Client
   *   A configured Slack API client with Authorization: Bearer <token>.
   */
  public function createWithHandler(ClientInterface $httpClient, string $token): SlackApiClient {
    return ClientFactory::create($token, $httpClient);
  }

}
