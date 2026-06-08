<?php

declare(strict_types=1);

/**
 * @file
 * Functional test verifying the jsonapi_search_api endpoint for slack_messages.
 */

namespace Drupal\Tests\slack_portal\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the jsonapi_search_api endpoint for the slack_messages index.
 *
 * Given: slack_portal is installed (search index + server config present)
 *        and several published/unpublished slack_message nodes exist.
 * When:  anonymous GETs /jsonapi/index/slack_messages (with/without filter).
 * Then:  HTTP 200; unpublished nodes absent; fulltext filter narrows data.
 *
 * @group slack_portal
 */
#[Group('slack_portal')]
class JsonApiSearchEndpointTest extends SlackJsonApiFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->grantAnonAccessContent();

    // Create a "general" channel taxonomy term for field_channel.
    $channel = Term::create([
      'vid' => 'slack_channels',
      'name' => 'general',
    ]);
    $channel->save();

    // Create a published node whose body contains "public".
    $published = Node::create([
      'type' => 'slack_message',
      'title' => 'Public message',
      'status' => 1,
      'field_body' => 'This is a public announcement',
      'field_channel' => ['target_id' => $channel->id()],
    ]);
    $published->save();

    // Create another published node whose body does NOT contain "public".
    $other = Node::create([
      'type' => 'slack_message',
      'title' => 'Another message',
      'status' => 1,
      'field_body' => 'Hello team, welcome here',
      'field_channel' => ['target_id' => $channel->id()],
    ]);
    $other->save();

    // Create an unpublished node.
    $unpublished = Node::create([
      'type' => 'slack_message',
      'title' => 'Secret message',
      'status' => 0,
      'field_body' => 'private confidential content',
      'field_channel' => ['target_id' => $channel->id()],
    ]);
    $unpublished->save();

    $this->buildSearchIndex();
  }

  /**
   * GET with fulltext filter returns only the matching published node.
   *
   * Given two published and one unpublished slack_message,
   * When  anonymous GETs /jsonapi/index/slack_messages?filter[fulltext]=public,
   * Then  HTTP 200 and data contains exactly 1 item (the matching node).
   */
  public function testFulltextFilterReturnsMatchingPublishedNode(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages', [], [
      'query' => ['filter' => ['fulltext' => 'public']],
    ]);
    $data = $this->doRequest($url);
    $this->assertArrayHasKey('data', $data);
    $this->assertCount(
      1,
      $data['data'],
      'Fulltext filter "public" must match exactly 1 published node.'
    );
    $this->assertSame(
      'node--slack_message',
      $data['data'][0]['type'],
      'The result must be of type node--slack_message.'
    );
  }

  /**
   * GET without filter returns only published nodes.
   *
   * Given two published and one unpublished slack_message,
   * When  anonymous GETs /jsonapi/index/slack_messages (no filter),
   * Then  HTTP 200, data contains exactly 2 items (both published), and
   *       the unpublished node is absent.
   */
  public function testUnpublishedNodeIsAbsentFromIndex(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages');
    $data = $this->doRequest($url);
    $this->assertArrayHasKey('data', $data);
    $this->assertCount(
      2,
      $data['data'],
      'Without filter, only the 2 published nodes must appear.'
    );
    foreach ($data['data'] as $item) {
      $this->assertSame(
        'node--slack_message',
        $item['type'],
        'Every result must be of type node--slack_message.'
      );
    }
  }

  /**
   * Performs a GET request and asserts HTTP 200.
   *
   * @param \Drupal\Core\Url $url
   *   The URL to request.
   *
   * @return array
   *   The decoded JSON:API response body.
   */
  private function doRequest(Url $url): array {
    $response = $this->request('GET', $url, [
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
      ],
    ]);
    $this->assertSame(
      200,
      $response->getStatusCode(),
      var_export(Json::decode((string) $response->getBody()), TRUE)
    );
    return Json::decode((string) $response->getBody());
  }

}
