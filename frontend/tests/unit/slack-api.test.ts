import { describe, it, expect, vi } from "vitest";
import type { NextDrupal } from "next-drupal";

import {
  CHANNEL_FILTER_PATH,
  buildChannelsParams,
  buildUsersParams,
  buildChannelMessagesParams,
  mapChannel,
  fetchChannels,
} from "@/lib/slack-api";
import { channelsResponse, rawChannels } from "../fixtures/jsonapi";

/** getResourceCollection だけを差し替える fake クライアント。 */
function fakeClient(response: unknown) {
  const getResourceCollection = vi.fn().mockResolvedValue(response);
  return {
    client: { getResourceCollection } as unknown as NextDrupal,
    getResourceCollection,
  };
}

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

  it("buildChannelMessagesParams は tid ごとに filter 値が変わる", () => {
    const q1 = buildChannelMessagesParams(1).getQueryString({ encode: false });
    const q2 = buildChannelMessagesParams(2).getQueryString({ encode: false });
    expect(q1).toContain(`filter[${CHANNEL_FILTER_PATH}]=1`);
    expect(q2).toContain(`filter[${CHANNEL_FILTER_PATH}]=2`);
  });
});

describe("mapChannel / fetchChannels", () => {
  it("mapChannel は raw term をドメイン Channel へ整形する", () => {
    const channel = mapChannel(rawChannels[0]);
    expect(channel).toEqual({
      id: "ch-general",
      tid: 1,
      name: "general",
      slackChannelId: "C100",
      channelType: "public_channel",
    });
  });

  it("fetchChannels は slack_channels を deserialize:false で取得し整形する", async () => {
    const { client, getResourceCollection } = fakeClient(channelsResponse);

    const channels = await fetchChannels(client);

    expect(channels).toHaveLength(2);
    expect(channels[0].name).toBe("general");
    const [type, options] = getResourceCollection.mock.calls[0];
    expect(type).toBe("taxonomy_term--slack_channels");
    expect(options.deserialize).toBe(false);
  });
});
