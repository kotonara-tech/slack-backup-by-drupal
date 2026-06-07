<?php

declare(strict_types=1);

/**
 * @file
 * Functional tests verifying JSON:API is read-only and respects node status.
 */

namespace Drupal\Tests\slack_portal\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies JSON:API read-only lock and published-only anonymous visibility.
 *
 * Given the slack_portal module is installed (hook_install sets read_only=TRUE)
 * and two slack_message nodes exist (one published, one unpublished),
 * When an anonymous user requests the JSON:API collection endpoint,
 * Then only the published node appears in the response (HTTP 200).
 * When an anonymous user attempts a POST/PATCH/DELETE,
 * Then the request is rejected with 405 (or 403).
 *
 * @group slack_portal
 */
#[Group('slack_portal')]
class JsonApiReadOnlyTest extends SlackJsonApiFunctionalTestBase {

  /**
   * UUID of the published slack_message node.
   */
  protected string $publishedUuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->grantAnonAccessContent();

    // Create one PUBLISHED slack_message.
    $published = Node::create([
      'type' => 'slack_message',
      'title' => 'Public message',
      'status' => 1,
    ]);
    $published->save();
    $this->publishedUuid = $published->uuid();

    // Create one UNPUBLISHED slack_message.
    $unpublished = Node::create([
      'type' => 'slack_message',
      'title' => 'Private message',
      'status' => 0,
    ]);
    $unpublished->save();
  }

  /**
   * Anonymous GET returns 200 and only the published node.
   *
   * Given two slack_message nodes (published + unpublished),
   * When anonymous GETs /jsonapi/node/slack_message,
   * Then HTTP 200, data contains exactly 1 item (the published node).
   */
  public function testAnonymousGetReturnsOnlyPublishedNodes(): void {
    $url = Url::fromUri('internal:/jsonapi/node/slack_message');
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(200, $response->getStatusCode(),
      'Anonymous GET /jsonapi/node/slack_message must return 200.');

    $body = Json::decode((string) $response->getBody());
    $this->assertArrayHasKey('data', $body,
      'Response body must contain a "data" key.');
    $this->assertCount(1, $body['data'],
      'Exactly one (published) node must be visible to anonymous.');
    $this->assertSame($this->publishedUuid, $body['data'][0]['id'],
      'The visible node must be the published one.');
  }

  /**
   * Anonymous POST is rejected (read-only).
   *
   * Given JSON:API is locked read-only via hook_install,
   * When anonymous POSTs to /jsonapi/node/slack_message,
   * Then HTTP 405 or 403 is returned.
   */
  public function testAnonymousPostIsRejected(): void {
    $url = Url::fromUri('internal:/jsonapi/node/slack_message');
    $body = Json::encode([
      'data' => [
        'type' => 'node--slack_message',
        'attributes' => ['title' => 'Injected node'],
      ],
    ]);
    $response = $this->request('POST', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      RequestOptions::BODY => $body,
    ]);

    $this->assertSame(405, $response->getStatusCode(),
      'Anonymous POST must be rejected with 405 (read-only).');
  }

  /**
   * Anonymous PATCH on the published node is rejected (read-only).
   *
   * Given JSON:API is locked read-only,
   * When anonymous PATCHes /jsonapi/node/slack_message/{uuid},
   * Then HTTP 405 or 403 is returned.
   */
  public function testAnonymousPatchIsRejected(): void {
    $url = Url::fromUri(
      'internal:/jsonapi/node/slack_message/' . $this->publishedUuid
    );
    $body = Json::encode([
      'data' => [
        'type' => 'node--slack_message',
        'id' => $this->publishedUuid,
        'attributes' => ['title' => 'Patched title'],
      ],
    ]);
    $response = $this->request('PATCH', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      RequestOptions::BODY => $body,
    ]);

    $this->assertSame(405, $response->getStatusCode(),
      'Anonymous PATCH must be rejected with 405 (read-only).');
  }

  /**
   * Anonymous DELETE on the published node is rejected (read-only).
   *
   * Given JSON:API is locked read-only,
   * When anonymous DELETEs /jsonapi/node/slack_message/{uuid},
   * Then HTTP 405 is returned.
   */
  public function testAnonymousDeleteIsRejected(): void {
    $url = Url::fromUri(
      'internal:/jsonapi/node/slack_message/' . $this->publishedUuid
    );
    $response = $this->request('DELETE', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);

    $this->assertSame(405, $response->getStatusCode(),
      'Anonymous DELETE must be rejected with 405 (read-only).');
  }

  /**
   * Read_only overrides write permission for an authenticated user (M3).
   *
   * Given: a user with create/edit/delete slack_message content permissions
   * AND jsonapi.settings:read_only = TRUE (set by hook_install),
   * When: that user POSTs to /jsonapi/node/slack_message,
   * Then: the response is 405, proving the read-only lock—not missing
   * permission—is responsible for blocking writes.
   */
  public function testReadOnlyOverridesWritePermission(): void {
    // Arrange: create a user that holds all content-write permissions.
    $user = $this->drupalCreateUser([
      'access content',
      'create slack_message content',
      'edit any slack_message content',
      'delete any slack_message content',
    ]);
    $this->drupalLogin($user);

    // Act: POST a minimal valid slack_message.
    $url = Url::fromRoute('jsonapi.node--slack_message.collection');
    $response = $this->request('POST', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      RequestOptions::BODY => Json::encode([
        'data' => [
          'type' => 'node--slack_message',
          'attributes' => ['title' => 'x'],
        ],
      ]),
    ]);

    // Assert: 405 because read_only=TRUE overrides the user's write permission.
    $this->assertSame(405, $response->getStatusCode(),
      'POST must be 405 even for a user holding create permission (read_only lock).');
  }

}
