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
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\jsonapi\Functional\JsonApiRequestTestTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
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
class JsonApiReadOnlyTest extends BrowserTestBase {

  use JsonApiRequestTestTrait;

  /**
   * {@inheritdoc}
   *
   * BrowserTestBase resolves info.yml deps but slack_portal.info.yml omits
   * several implicit core field/type deps that its config/install requires
   * (text, datetime, taxonomy, file). We list them here so the full Drupal
   * site install succeeds. 'slack_portal' pulls in jsonapi, migrate_plus, key,
   * encrypt, real_aes, etc. via info.yml resolution.
   *
   * @var string[]
   */
  protected static $modules = [
    'node',
    'text',
    'field',
    'datetime',
    'taxonomy',
    'file',
    'filter',
    'slack_portal',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * UUID of the published slack_message node.
   */
  protected string $publishedUuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure anonymous has core "access content" (standard permission).
    /** @var \Drupal\user\Entity\Role $anon_role */
    $anon_role = Role::load(AccountInterface::ANONYMOUS_ROLE);
    $anon_role->grantPermission('access content');
    $anon_role->save();

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

    $status = $response->getStatusCode();
    $this->assertContains($status, [403, 405],
      "Anonymous POST must be rejected with 405 or 403, got $status.");
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

    $status = $response->getStatusCode();
    $this->assertContains($status, [403, 405],
      "Anonymous PATCH must be rejected with 405 or 403, got $status.");
  }

  /**
   * Anonymous DELETE on the published node is rejected (read-only).
   *
   * Given JSON:API is locked read-only,
   * When anonymous DELETEs /jsonapi/node/slack_message/{uuid},
   * Then HTTP 405 or 403 is returned.
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

    $status = $response->getStatusCode();
    $this->assertContains($status, [403, 405],
      "Anonymous DELETE must be rejected with 405 or 403, got $status.");
  }

}
