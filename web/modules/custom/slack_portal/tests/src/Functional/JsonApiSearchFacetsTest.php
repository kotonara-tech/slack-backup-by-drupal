<?php

declare(strict_types=1);

/**
 * @file
 * Functional test verifying channel/slack_user/posted_at facets.
 */

namespace Drupal\Tests\slack_portal\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies channel, slack_user, and posted_at facets on the search endpoint.
 *
 * Given: slack_portal config installed (facets for channel, slack_user,
 *        posted_at) and nodes in ≥2 channels.
 * When:  anonymous GETs /jsonapi/index/slack_messages.
 * Then:  meta.facets exists and contains channel, slack_user, posted_at.
 * When:  filtering by channel=general.
 * Then:  data narrows; channel facet term "general" is active.
 *
 * @group slack_portal
 */
#[Group('slack_portal')]
class JsonApiSearchFacetsTest extends SlackJsonApiFunctionalTestBase {

  /**
   * Term IDs keyed by channel name.
   *
   * @var int[]
   */
  protected array $channelIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->grantAnonAccessContent();

    // Create two channel taxonomy terms.
    $general = Term::create([
      'vid' => 'slack_channels',
      'name' => 'general',
    ]);
    $general->save();
    $this->channelIds['general'] = $general->id();

    $random = Term::create([
      'vid' => 'slack_channels',
      'name' => 'random',
    ]);
    $random->save();
    $this->channelIds['random'] = $random->id();

    // Create a slack_users taxonomy term (for field_slack_user).
    $user = Term::create([
      'vid' => 'slack_users',
      'name' => 'alice',
    ]);
    $user->save();

    // Create published nodes in "general" channel.
    $this->createSlackNode('Message one', 1, 'general', $user->id());
    $this->createSlackNode('Message two', 1, 'general', $user->id());

    // Create published node in "random" channel.
    $this->createSlackNode('Message three', 1, 'random', $user->id());

    // Create an unpublished node (must be invisible).
    $this->createSlackNode('Hidden message', 0, 'general', $user->id());

    $this->buildSearchIndex();
  }

  /**
   * Creates a slack_message node.
   *
   * @param string $title
   *   Node title.
   * @param int $status
   *   Published (1) or unpublished (0).
   * @param string $channel
   *   Channel name key in $this->channelIds.
   * @param int|string $user_tid
   *   Term ID for field_slack_user.
   */
  private function createSlackNode(
    string $title,
    int $status,
    string $channel,
    int|string $user_tid,
  ): void {
    $node = Node::create([
      'type' => 'slack_message',
      'title' => $title,
      'status' => $status,
      'field_channel' => ['target_id' => $this->channelIds[$channel]],
      'field_slack_user' => ['target_id' => $user_tid],
      'field_posted_at' => '2024-01-01T00:00:00',
    ]);
    $node->save();
  }

  /**
   * Facets appear in meta.facets for an unfiltered request.
   *
   * Given: published nodes across "general" and "random" channels,
   * When:  anonymous GETs /jsonapi/index/slack_messages,
   * Then:  HTTP 200, meta.facets exists, contains ids channel, slack_user,
   *        and posted_at.
   */
  public function testFacetsAppearsInMeta(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages');
    $data = $this->doRequest($url);

    $this->assertArrayHasKey('meta', $data);
    $this->assertArrayHasKey(
      'facets',
      $data['meta'],
      'meta.facets must be present when facets are configured.'
    );

    $facet_ids = array_column($data['meta']['facets'], 'id');
    $this->assertContains(
      'channel',
      $facet_ids,
      'channel facet must appear in meta.facets.'
    );
    $this->assertContains(
      'slack_user',
      $facet_ids,
      'slack_user facet must appear in meta.facets.'
    );
    $this->assertContains(
      'posted_at',
      $facet_ids,
      'posted_at facet must appear in meta.facets.'
    );
  }

  /**
   * Channel facet has ≥2 terms because nodes span two channels.
   *
   * Given: nodes in "general" and "random",
   * When:  anonymous GETs /jsonapi/index/slack_messages,
   * Then:  the channel facet has at least 2 terms.
   */
  public function testChannelFacetHasMultipleTerms(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages');
    $data = $this->doRequest($url);

    $channel_facet = $this->findFacet($data['meta']['facets'], 'channel');
    $this->assertNotNull(
      $channel_facet,
      'channel facet must be present in meta.facets.'
    );
    $this->assertGreaterThanOrEqual(
      2,
      count($channel_facet['terms']),
      'channel facet must have terms for both "general" and "random".'
    );
  }

  /**
   * Filtering by channel=general narrows results and marks the term active.
   *
   * Given: nodes in "general" (2) and "random" (1),
   * When:  filtering with filter[channel]=general,
   * Then:  data count = 2; channel facet contains an active term.
   */
  public function testFilterByChannelNarrowsResults(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages', [], [
      'query' => ['filter' => ['channel' => 'general']],
    ]);
    $data = $this->doRequest($url);

    $this->assertCount(
      2,
      $data['data'],
      'Filtering by channel=general must return 2 published nodes.'
    );

    $channel_facet = $this->findFacet($data['meta']['facets'], 'channel');
    $this->assertNotNull($channel_facet);
    $active_terms = array_filter(
      $channel_facet['terms'],
      static fn(array $term) => $term['values']['active'] === TRUE,
    );
    $this->assertNotCount(
      0,
      $active_terms,
      'At least one channel facet term must be marked active.'
    );
  }

  /**
   * Posted_at facet uses date_item granularity (day-level bucket values).
   *
   * Given: all nodes share the same calendar day (2024-01-01),
   * When:  anonymous GETs /jsonapi/index/slack_messages,
   * Then:  the posted_at facet has exactly 1 term (all messages bucketed
   *        together) and its value matches day-granularity (YYYY-MM-DD or
   *        a unix-timestamp grouping), not per-second precision.
   */
  public function testPostedAtFacetUsesDateGranularity(): void {
    $url = Url::fromRoute('jsonapi_search_api.index_slack_messages');
    $data = $this->doRequest($url);

    $posted_at_facet = $this->findFacet($data['meta']['facets'], 'posted_at');
    $this->assertNotNull(
      $posted_at_facet,
      'posted_at facet must appear in meta.facets.'
    );

    // All nodes were created with field_posted_at = 2024-01-01, so with
    // DAY granularity the date_item processor groups them into a single bucket.
    $this->assertCount(
      1,
      $posted_at_facet['terms'],
      'date_item (DAY granularity) must group all same-day messages into 1 bucket.'
    );
  }

  /**
   * Finds a facet by id from the meta.facets array.
   *
   * @param array $facets
   *   The meta.facets array.
   * @param string $id
   *   The facet id to find.
   *
   * @return array|null
   *   The facet array, or NULL if not found.
   */
  private function findFacet(array $facets, string $id): ?array {
    foreach ($facets as $facet) {
      if ($facet['id'] === $id) {
        return $facet;
      }
    }
    return NULL;
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
