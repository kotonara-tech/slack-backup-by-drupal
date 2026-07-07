import { test, expect } from "@playwright/test";

/**
 * large (Playwright): 全文検索のハッピーパス（M5）。
 *
 * backend は browse.spec.ts と同様に page.route で全モック（Drupal/DDEV 不要）。
 * `/jsonapi/index/slack_messages` は `filter[channel]` の有無で応答を分岐し、
 * facet クリックによる絞り込みが実際にクエリへ反映されることを検証する。
 */
const JSONAPI = "application/vnd.api+json";

const channelsResponse = {
  data: [
    {
      type: "taxonomy_term--slack_channels",
      id: "ch-general",
      attributes: {
        drupal_internal__tid: 1,
        name: "general",
        field_slack_channel_id: "C100",
        field_channel_type: "public_channel",
      },
    },
    {
      type: "taxonomy_term--slack_channels",
      id: "ch-random",
      attributes: {
        drupal_internal__tid: 2,
        name: "random",
        field_slack_channel_id: "C200",
        field_channel_type: "public_channel",
      },
    },
  ],
};

const usersResponse = {
  data: [
    {
      type: "taxonomy_term--slack_users",
      id: "u-taro",
      attributes: {
        name: "U1",
        field_slack_user_id: "U1",
        field_real_name: "Taro Yamada",
        field_display_name: "taro",
        field_is_bot: false,
        field_avatar: "https://x/taro.png",
      },
    },
  ],
};

function searchMessage(
  id: string,
  channelUuid: string,
  body: string,
  slackTs: string,
) {
  return {
    type: "node--slack_message",
    id,
    attributes: {
      drupal_internal__nid: Number(slackTs.split(".")[0]),
      title: body.slice(0, 20),
      status: true,
      field_body: { value: body, format: "plain_text", processed: body },
      field_slack_ts: slackTs,
      field_thread_ts: null,
      field_posted_at: "2026-05-01T10:00:00+09:00",
      field_subtype: null,
      field_edited: false,
      field_reply_count: 0,
      field_reactions: "[]",
      field_slack_user_id: "U1",
    },
    relationships: {
      field_channel: {
        data: { type: "taxonomy_term--slack_channels", id: channelUuid, meta: { drupal_internal__target_id: channelUuid === "ch-general" ? 1 : 2 } },
      },
      field_slack_user: {
        data: { type: "taxonomy_term--slack_users", id: "u-taro" },
      },
      field_attachments: { data: [] },
    },
  };
}

const allResultsResponse = {
  data: [
    searchMessage("m-s1", "ch-general", "deploy 完了しました", "100.0001"),
    searchMessage("m-s2", "ch-general", "another deploy note", "100.0002"),
    searchMessage("m-s3", "ch-random", "deploy on random channel", "100.0003"),
  ],
  meta: {
    facets: [
      {
        id: "channel",
        label: "Channel",
        terms: [
          { values: { value: "general", count: 2, active: false } },
          { values: { value: "random", count: 1, active: false } },
        ],
      },
    ],
  },
};

const generalOnlyResponse = {
  data: [
    searchMessage("m-s1", "ch-general", "deploy 完了しました", "100.0001"),
    searchMessage("m-s2", "ch-general", "another deploy note", "100.0002"),
  ],
  meta: {
    facets: [
      {
        id: "channel",
        label: "Channel",
        terms: [
          { values: { value: "general", count: 2, active: true } },
          { values: { value: "random", count: 1, active: false } },
        ],
      },
    ],
  },
};

test("検索して facet で絞り込める", async ({ page }) => {
  await page.route("**/jsonapi/taxonomy_term/slack_channels*", (route) =>
    route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(channelsResponse) }),
  );
  await page.route("**/jsonapi/taxonomy_term/slack_users*", (route) =>
    route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(usersResponse) }),
  );
  await page.route("**/jsonapi/index/slack_messages*", (route) => {
    const url = new URL(route.request().url());
    const channel = url.searchParams.get("filter[channel]");
    const body = channel === "general" ? generalOnlyResponse : allResultsResponse;
    return route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(body) });
  });

  await page.goto("/");

  await expect(page.getByRole("searchbox", { name: "メッセージを検索" })).toBeVisible();
  await page.screenshot({ path: "test-results/search-before.png", fullPage: true });

  await page.getByRole("searchbox", { name: "メッセージを検索" }).fill("deploy");
  await page.getByRole("searchbox", { name: "メッセージを検索" }).press("Enter");

  // 検索結果 3 件・本文ハイライトが見える。
  await expect(page.getByTestId("search-result-list")).toBeVisible();
  await expect(page.locator('[data-testid="search-result-list"] mark').first()).toBeVisible();
  await expect(page.getByText("deploy 完了しました")).toBeVisible();
  await expect(page.getByText("deploy on random channel")).toBeVisible();

  // facet「general (2)」で絞り込むと general の 2 件のみになり active 表示される。
  await page.getByText("general (2)").click();
  await expect(page.getByText("deploy on random channel")).toHaveCount(0);
  await expect(page.getByText("another deploy note")).toBeVisible();
  await expect(page.getByTestId("facet-term-channel-general")).toHaveAttribute(
    "aria-current",
    "true",
  );

  await page.screenshot({ path: "test-results/search-results.png", fullPage: true });
});
