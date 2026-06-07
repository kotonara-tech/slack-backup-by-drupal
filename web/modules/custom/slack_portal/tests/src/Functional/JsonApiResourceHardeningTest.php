<?php

declare(strict_types=1);

/**
 * @file
 * Functional tests verifying JSON:API resource whitelist hardening.
 */

namespace Drupal\Tests\slack_portal\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies jsonapi_extras whitelist hardening reduces attack surface.
 *
 * Given: jsonapi_extras.settings has default_disabled = TRUE, plus explicit
 *        resource_config entities enabling only the portal resources.
 * When:  anonymous requests various JSON:API endpoints.
 * Then:
 *   - /jsonapi/user/user returns 404 (resource disabled).
 *   - /jsonapi/node/slack_message returns 200 (resource enabled).
 *   - /jsonapi/index/slack_messages returns 200 (search still works).
 *   - ?include=field_channel,field_slack_user resolves taxonomy terms (200
 *     with included data) proving taxonomy_term resources are enabled.
 *
 * @group slack_portal
 */
#[Group('slack_portal')]
class JsonApiResourceHardeningTest extends SlackJsonApiFunctionalTestBase {

  /**
   * UUID of the published slack_message node.
   */
  protected string $publishedUuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Grant anonymous "access content" so node resources are visible.
    $this->grantAnonAccessContent();

    // Create a channel term for includes and indexing.
    $channel = Term::create([
      'vid' => 'slack_channels',
      'name' => 'general',
    ]);
    $channel->save();

    // Create a slack_user term for includes.
    $user = Term::create([
      'vid' => 'slack_users',
      'name' => 'alice',
    ]);
    $user->save();

    // Create one published slack_message referencing channel + user.
    $node = Node::create([
      'type' => 'slack_message',
      'title' => 'Hardening test message',
      'status' => 1,
      'field_channel' => ['target_id' => $channel->id()],
      'field_slack_user' => ['target_id' => $user->id()],
    ]);
    $node->save();
    $this->publishedUuid = $node->uuid();

    // Index items so the search endpoint has data.
    $this->buildSearchIndex();
  }

  /**
   * Anonymous GET /jsonapi/user/user returns 404 (resource disabled).
   *
   * Given: jsonapi_extras default_disabled = TRUE with no user--user config,
   * When:  anonymous GETs /jsonapi/user/user,
   * Then:  HTTP 404 (route does not exist because resource is disabled).
   */
  public function testUserResourceIsDisabled(): void {
    $url = Url::fromUri('internal:/jsonapi/user/user');
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(
      404,
      $response->getStatusCode(),
      'Anonymous GET /jsonapi/user/user must return 404 when resource is '
      . 'disabled by whitelist. Got: '
      . (string) $response->getBody(),
    );
  }

  /**
   * Anonymous GET /jsonapi/node/slack_message returns 200 (resource enabled).
   *
   * Given: jsonapi_resource_config for node--slack_message has disabled=false,
   * When:  anonymous GETs /jsonapi/node/slack_message,
   * Then:  HTTP 200 and data contains the published node.
   */
  public function testSlackMessageResourceIsEnabled(): void {
    $url = Url::fromUri('internal:/jsonapi/node/slack_message');
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(
      200,
      $response->getStatusCode(),
      'Anonymous GET /jsonapi/node/slack_message must return 200 after '
      . 'whitelist hardening.',
    );

    $body = Json::decode((string) $response->getBody());
    $this->assertArrayHasKey('data', $body);
    $this->assertCount(
      1,
      $body['data'],
      'Exactly one published node must be visible.'
    );
  }

  /**
   * Search endpoint /jsonapi/index/slack_messages returns 200 after hardening.
   *
   * Given: jsonapi_extras whitelist is active,
   * When:  anonymous GETs /jsonapi/index/slack_messages,
   * Then:  HTTP 200 (search endpoint is unaffected by resource whitelist).
   */
  public function testSearchEndpointStillReturns200(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages');
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(
      200,
      $response->getStatusCode(),
      'GET /jsonapi/index/slack_messages must return 200 after whitelist '
      . 'hardening. Got: '
      . (string) $response->getBody(),
    );
  }

  /**
   * Include of field_channel / field_slack_user resolves taxonomy terms.
   *
   * Given: taxonomy_term--slack_channels and taxonomy_term--slack_users are
   *        enabled in the whitelist,
   * When:  anonymous GETs /jsonapi/node/slack_message?include=…,
   * Then:  HTTP 200 and "included" contains at least one taxonomy_term.
   */
  public function testTaxonomyTermIncludesResolveWhenEnabled(): void {
    $url = Url::fromUri(
      'internal:/jsonapi/node/slack_message',
      ['query' => ['include' => 'field_channel,field_slack_user']],
    );
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(
      200,
      $response->getStatusCode(),
      'GET with ?include=field_channel,field_slack_user must return 200. '
      . 'Got: '
      . (string) $response->getBody(),
    );

    $body = Json::decode((string) $response->getBody());
    $this->assertArrayHasKey(
      'included',
      $body,
      '"included" key must be present when include param resolves terms.'
    );
    $this->assertNotEmpty(
      $body['included'],
      '"included" must not be empty — taxonomy terms must resolve.'
    );

    // Verify the included items contain taxonomy_term types.
    $included_types = array_unique(
      array_column($body['included'], 'type'),
    );
    $taxonomy_types = array_filter(
      $included_types,
      static fn(string $t) => str_starts_with($t, 'taxonomy_term--'),
    );
    $this->assertNotEmpty(
      $taxonomy_types,
      'Included resources must contain taxonomy_term--* types.'
    );
  }

}
