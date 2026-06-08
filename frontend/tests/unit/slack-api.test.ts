import { describe, it, expect } from "vitest";

import {
  CHANNEL_FILTER_PATH,
  buildChannelsParams,
  buildUsersParams,
  buildChannelMessagesParams,
} from "@/lib/slack-api";

/**
 * small (Vitest): JSON:API クエリビルダ（drupal-jsonapi-params）。
 * 契約は docs/spec/jsonapi-search.md。チャンネル絞り込みは
 * `field_channel.meta.drupal_internal__target_id` を 1 箇所に固定する。
 */
describe("query builders", () => {
  it("CHANNEL_FILTER_PATH は internal target id のパス", () => {
    expect(CHANNEL_FILTER_PATH).toBe(
      "field_channel.meta.drupal_internal__target_id",
    );
  });

  it("buildChannelsParams は name 昇順で取得する", () => {
    const qs = buildChannelsParams().getQueryString({ encode: false });
    expect(qs).toContain("sort=name");
    expect(qs).toContain("page[limit]=");
  });

  it("buildUsersParams は全ユーザを 1 ページで取得する（lookup マップ用）", () => {
    const qs = buildUsersParams().getQueryString({ encode: false });
    // 140 件規模を 1 フェッチで賄うだけの limit を要求する。
    const limit = Number(/page\[limit\]=(\d+)/.exec(qs)?.[1] ?? "0");
    expect(limit).toBeGreaterThanOrEqual(200);
  });

  it("buildChannelMessagesParams は tid で絞り、新しい順・添付 include・100 件", () => {
    const qs = buildChannelMessagesParams(7).getQueryString({ encode: false });
    expect(qs).toContain(`filter[${CHANNEL_FILTER_PATH}]=7`);
    expect(qs).toContain("sort=-field_posted_at");
    expect(qs).toContain("include=field_attachments");
    expect(qs).toContain("page[limit]=100");
  });
});
