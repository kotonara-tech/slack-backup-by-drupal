<?php

declare(strict_types=1);

/**
 * @file
 * Builds a Slack API client with Bearer auth and 429/503 retry backoff.
 */

namespace Drupal\slack_portal\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils as GuzzleUtils;
use GuzzleRetry\GuzzleRetryMiddleware;
use JoliCode\Slack\Api\Client as SlackApiClient;
use JoliCode\Slack\ClientFactory;

/**
 * Builds a JoliCode Slack API client with Bearer auth and retry backoff.
 *
 * The factory owns the Guzzle HandlerStack so it can inject retry middleware
 * below the JoliCode php-http plugin layer. This means 429/503 responses are
 * retried at the Guzzle transport level before SlackErrorPlugin ever sees them.
 *
 * In tests, pass a GuzzleHttp\Handler\MockHandler directly; the factory wraps
 * it in a HandlerStack and pushes the retry middleware on top. In production,
 * pass a plain callable or omit the handler to use Guzzle's default.
 */
final class SlackClientFactory {

  /**
   * Builds a Slack API client from a callable Guzzle handler and a token.
   *
   * @param callable $handler
   *   A Guzzle-compatible callable handler (e.g. MockHandler for tests,
   *   GuzzleHttp\Handler\CurlHandler for production). The factory wraps this
   *   in a HandlerStack and pushes GuzzleRetryMiddleware on top.
   * @param string $token
   *   A Slack user token (xoxp-) or bot token (xoxb-).
   *   Must NOT be logged or committed. Keep in Key/settings.local.php.
   * @param array<string,mixed> $retryOptions
   *   Optional overrides for GuzzleRetryMiddleware options (e.g. to inject an
   *   on_retry_callback in tests or tune max_retry_attempts in production).
   *   Merged on top of the factory's defaults.
   *
   * @return \JoliCode\Slack\Api\Client
   *   A configured Slack API client with Authorization: Bearer <token> and
   *   automatic 429/503 retry backoff.
   */
  public function createWithHandler(callable $handler, string $token, array $retryOptions = []): SlackApiClient {
    $stack = HandlerStack::create($handler);
    $stack->push(GuzzleRetryMiddleware::factory($retryOptions + $this->defaultRetryOptions()));
    $guzzle = new GuzzleClient(['handler' => $stack]);
    return ClientFactory::create($token, $guzzle);
  }

  /**
   * Builds a production Slack API client using the default Guzzle handler.
   *
   * This is the production entry-point. It selects the best available Guzzle
   * transport handler (cURL, stream, etc.) via GuzzleUtils::chooseHandler()
   * and delegates to createWithHandler() so the retry middleware is applied
   * consistently.
   *
   * For tests that need to intercept requests, use createWithHandler() with a
   * GuzzleHttp\Handler\MockHandler directly.
   *
   * @param string $token
   *   A Slack user token (xoxp-) or bot token (xoxb-).
   *   Must NOT be logged or committed. Keep in Key/settings.local.php.
   *
   * @return \JoliCode\Slack\Api\Client
   *   A configured Slack API client with Authorization: Bearer <token> and
   *   automatic 429/503 retry backoff.
   */
  public function create(string $token): SlackApiClient {
    return $this->createWithHandler(GuzzleUtils::chooseHandler(), $token);
  }

  /**
   * Returns the default retry options for GuzzleRetryMiddleware.
   *
   * These defaults follow the Slack API's rate-limiting guidance:
   * - Retry up to 10 times on 429 (ratelimited) and 503 (service unavailable).
   * - Use a 1.5× exponential multiplier when no Retry-After header is present.
   * - Cap any single retry delay at 60 seconds regardless of server directive.
   *
   * @return array<string,mixed>
   *   Retry option map suitable for GuzzleRetryMiddleware::factory().
   */
  private function defaultRetryOptions(): array {
    return [
      'max_retry_attempts'      => 10,
      'retry_on_status'         => [429, 503],
      'default_retry_multiplier' => 1.5,
      'max_allowable_timeout_secs' => 60,
    ];
  }

}
