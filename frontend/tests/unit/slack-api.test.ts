import { describe, it, expect, vi } from "vitest";
import type { NextDrupal } from "next-drupal";

import {
  CHANNEL_FILTER_PATH,
  buildChannelsParams,
  buildUsersParams,
  buildChannelMessagesParams,
  mapChannel,
  fetchChannels,
  mapUser,
  fetchUsers,
  buildUserMap,
  parseReactions,
  mapAttachment,
  buildFileMap,
  mapMessage,
  fetchChannelMessages,
} from "@/lib/slack-api";
import {
  channelsResponse,
  rawChannels,
  usersResponse,
  rawUsers,
  rawMessages,
  rawFiles,
  channelMessagesResponse,
} from "../fixtures/jsonapi";

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

  it("fetchChannels は data 欠損でも空配列を返す", async () => {
    const { client } = fakeClient({});
    await expect(fetchChannels(client)).resolves.toEqual([]);
  });
});

describe("mapUser / fetchUsers", () => {
  it("mapUser は raw term をドメイン User へ整形する", () => {
    expect(mapUser(rawUsers[0])).toEqual({
      id: "u-taro",
      slackUserId: "U1",
      realName: "Taro Yamada",
      displayName: "taro",
      isBot: false,
      avatar: "https://x/taro.png",
    });
  });

  it("mapUser は display_name が null なら空文字にする", () => {
    expect(mapUser(rawUsers[1]).displayName).toBe("");
  });

  it("fetchUsers は slack_users を取得し全件整形する", async () => {
    const { client, getResourceCollection } = fakeClient(usersResponse);

    const users = await fetchUsers(client);

    expect(users).toHaveLength(3);
    expect(users[2].isBot).toBe(true);
    expect(getResourceCollection.mock.calls[0][0]).toBe(
      "taxonomy_term--slack_users",
    );
  });

  it("buildUserMap は uuid→User の lookup マップを作る", () => {
    const map = buildUserMap([mapUser(rawUsers[0]), mapUser(rawUsers[1])]);
    expect(map["u-taro"].slackUserId).toBe("U1");
    expect(map["u-hanako"].displayName).toBe("");
    expect(map["missing"]).toBeUndefined();
  });
});

describe("parseReactions", () => {
  it("JSON 文字列を Reaction[] へ parse する", () => {
    expect(parseReactions('[{"name":"thumbsup","count":3,"users":["U1"]}]')).toEqual([
      { name: "thumbsup", count: 3 },
    ]);
  });

  it("空配列文字列は [] になる", () => {
    expect(parseReactions("[]")).toEqual([]);
  });

  it("壊れた JSON でも throw せず [] を返す", () => {
    expect(parseReactions("{not json")).toEqual([]);
  });

  it("文字列でない（null 等）入力は [] を返す", () => {
    expect(parseReactions(null)).toEqual([]);
    expect(parseReactions(undefined)).toEqual([]);
  });
});

describe("mapMessage / mapAttachment / fetchChannelMessages", () => {
  const fileMap = buildFileMap(rawFiles);

  it("mapAttachment / buildFileMap は file--file を Attachment へ整形する", () => {
    expect(mapAttachment(rawFiles[0])).toEqual({
      id: "f-log",
      filename: "log.txt",
      url: "/system/files/slack_archive/latest/files/log.txt",
    });
    expect(fileMap["f-log"].filename).toBe("log.txt");
  });

  it("mapMessage は親メッセージを attributes＋relationship.data.id から整形する", () => {
    const parent = mapMessage(rawMessages[5], fileMap); // m-parent
    expect(parent).toMatchObject({
      id: "m-parent",
      nid: 1,
      body: "スレッドの親メッセージ",
      slackTs: "100.0001",
      threadTs: "100.0001",
      postedAt: "2026-05-01T10:00:00+09:00",
      replyCount: 2,
      reactions: [{ name: "thumbsup", count: 3 }],
      authorUuid: "u-taro",
      channelUuid: "ch-general",
      attachments: [],
    });
  });

  it("mapMessage は bot（author 無）でも throw せず authorUuid=null", () => {
    const bot = mapMessage(rawMessages[1], fileMap); // m-bot
    expect(bot.authorUuid).toBeNull();
    expect(bot.subtype).toBe("bot_message");
    expect(bot.edited).toBe(true);
  });

  it("mapMessage は included の file から添付を解決する", () => {
    const standalone = mapMessage(rawMessages[2], fileMap); // m-standalone
    expect(standalone.attachments).toEqual([
      { id: "f-log", filename: "log.txt", url: "/system/files/slack_archive/latest/files/log.txt" },
    ]);
  });

  it("fetchChannelMessages は node を取得し添付を included から解決して整形する", async () => {
    const { client, getResourceCollection } = fakeClient(channelMessagesResponse);

    const messages = await fetchChannelMessages(1, client);

    expect(messages).toHaveLength(6);
    expect(getResourceCollection.mock.calls[0][0]).toBe("node--slack_message");
    const standalone = messages.find((m) => m.id === "m-standalone");
    expect(standalone?.attachments[0].filename).toBe("log.txt");
  });

  it("mapMessage は fileMap に無い添付（private 等）を黙って除外する", () => {
    // included が空＝hook_file_download で遮断された添付は解決されない。
    const standalone = mapMessage(rawMessages[2], {});
    expect(standalone.attachments).toEqual([]);
  });
});
