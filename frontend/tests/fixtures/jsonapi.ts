/**
 * M4 閲覧 UI のテスト fixture。
 *
 * 2 形態を提供する:
 *  (a) raw JSON:API（`{ data, included }`）— mapper / fetch / hook の Vitest と
 *      Playwright `page.route` の両方で使う、実 API（docs/spec/jsonapi-search.md）
 *      に忠実な形。relationship は `{ type, id(uuid), meta }`。
 *  (b) deserialize 済みドメイン型 — component テスト用の素直な入力。
 *
 * メッセージは 1 チャンネル（general, tid=1）に、親＋返信2（うち thread_broadcast）
 * ＋standalone＋bot（author 無）＋orphan reply（親不在）＋reactions＋添付 を含む。
 */
import type {
  Attachment,
  Channel,
  JsonApiResource,
  JsonApiResponse,
  SlackMessage,
  User,
} from "@/lib/types/slack";

/* ── (a) raw JSON:API リソース ── */

function rawChannel(
  id: string,
  tid: number,
  name: string,
  slackChannelId: string,
  channelType: string,
): JsonApiResource {
  return {
    type: "taxonomy_term--slack_channels",
    id,
    attributes: {
      drupal_internal__tid: tid,
      name,
      field_slack_channel_id: slackChannelId,
      field_channel_type: channelType,
    },
  };
}

function rawUser(
  id: string,
  slackUserId: string,
  realName: string,
  displayName: string | null,
  isBot: boolean,
  avatar: string,
): JsonApiResource {
  return {
    type: "taxonomy_term--slack_users",
    id,
    attributes: {
      name: slackUserId,
      field_slack_user_id: slackUserId,
      field_real_name: realName,
      field_display_name: displayName,
      field_is_bot: isBot,
      field_avatar: avatar,
    },
  };
}

interface RawMessageOpts {
  id: string;
  nid: number;
  title: string;
  body: string;
  slackTs: string;
  threadTs: string | null;
  postedAt: string;
  subtype?: string | null;
  edited?: boolean;
  replyCount?: number;
  reactions?: string;
  authorUuid?: string | null;
  channelUuid?: string;
  attachmentUuids?: string[];
}

function rawMessage(o: RawMessageOpts): JsonApiResource {
  const author = o.authorUuid ?? null;
  return {
    type: "node--slack_message",
    id: o.id,
    attributes: {
      drupal_internal__nid: o.nid,
      title: o.title,
      status: true,
      field_body: { value: o.body, format: "plain_text", processed: o.body },
      field_slack_ts: o.slackTs,
      field_thread_ts: o.threadTs,
      field_posted_at: o.postedAt,
      field_subtype: o.subtype ?? null,
      field_edited: o.edited ?? false,
      field_reply_count: o.replyCount ?? 0,
      field_reactions: o.reactions ?? "[]",
      field_slack_user_id: author,
    },
    relationships: {
      field_channel: {
        data: {
          type: "taxonomy_term--slack_channels",
          id: o.channelUuid ?? "ch-general",
          meta: { drupal_internal__target_id: 1 },
        },
      },
      field_slack_user: {
        data: author
          ? {
              type: "taxonomy_term--slack_users",
              id: author,
              meta: { drupal_internal__target_id: 256 },
            }
          : null,
      },
      field_attachments: {
        data: (o.attachmentUuids ?? []).map((fid) => ({
          type: "file--file",
          id: fid,
          meta: { drupal_internal__target_id: 9 },
        })),
      },
    },
  };
}

function rawFile(id: string, filename: string, url: string): JsonApiResource {
  return {
    type: "file--file",
    id,
    attributes: { filename, url, filemime: "text/plain" },
  };
}

export const rawChannels: JsonApiResource[] = [
  rawChannel("ch-general", 1, "general", "C100", "public_channel"),
  rawChannel("ch-random", 2, "random", "C200", "public_channel"),
];

export const rawUsers: JsonApiResource[] = [
  rawUser("u-taro", "U1", "Taro Yamada", "taro", false, "https://x/taro.png"),
  // display_name が null のケース（realName へフォールバック）。
  rawUser("u-hanako", "U2", "Hanako Sato", null, false, "https://x/hanako.png"),
  rawUser("u-bot", "U3", "Notifier", "notifier", true, "https://x/bot.png"),
];

/** general(tid=1) のメッセージ。実 API と同じく新しい順（-field_posted_at）。 */
export const rawMessages: JsonApiResource[] = [
  rawMessage({
    id: "m-orphan",
    nid: 6,
    title: "orphan reply",
    body: "親が取得範囲外の返信",
    slackTs: "400.0002",
    threadTs: "400.0001", // 親 400.0001 は集合に存在しない（orphan）
    postedAt: "2026-05-04T07:00:00+09:00",
    authorUuid: "u-hanako",
  }),
  rawMessage({
    id: "m-bot",
    nid: 5,
    title: "deployment finished",
    body: "deployment finished",
    slackTs: "300.0001",
    threadTs: null,
    postedAt: "2026-05-03T08:00:00+09:00",
    subtype: "bot_message",
    edited: true,
    authorUuid: null, // bot はユーザ参照なし
  }),
  rawMessage({
    id: "m-standalone",
    nid: 4,
    title: "standalone message",
    body: "添付つきの単独メッセージ",
    slackTs: "200.0001",
    threadTs: null,
    postedAt: "2026-05-02T09:00:00+09:00",
    reactions: '[{"name":"eyes","count":1,"users":["U1"]}]',
    authorUuid: "u-hanako",
    attachmentUuids: ["f-log"],
  }),
  rawMessage({
    id: "m-reply2",
    nid: 3,
    title: "broadcast reply",
    body: "ブロードキャスト返信",
    slackTs: "100.0003",
    threadTs: "100.0001",
    postedAt: "2026-05-01T10:10:00+09:00",
    subtype: "thread_broadcast",
    authorUuid: "u-taro",
  }),
  rawMessage({
    id: "m-reply1",
    nid: 2,
    title: "first reply",
    body: "最初の返信",
    slackTs: "100.0002",
    threadTs: "100.0001",
    postedAt: "2026-05-01T10:05:00+09:00",
    authorUuid: "u-hanako",
  }),
  rawMessage({
    id: "m-parent",
    nid: 1,
    title: "thread parent",
    body: "スレッドの親メッセージ",
    slackTs: "100.0001",
    threadTs: "100.0001", // 親（thread_ts === slack_ts）
    postedAt: "2026-05-01T10:00:00+09:00",
    replyCount: 2,
    reactions: '[{"name":"thumbsup","count":3,"users":["U1","U2"]}]',
    authorUuid: "u-taro",
  }),
];

export const rawFiles: JsonApiResource[] = [
  rawFile("f-log", "log.txt", "/system/files/slack_archive/latest/files/log.txt"),
];

export const channelsResponse: JsonApiResponse = { data: rawChannels };
export const usersResponse: JsonApiResponse = { data: rawUsers };
export const channelMessagesResponse: JsonApiResponse = {
  data: rawMessages,
  included: rawFiles,
};

/* ── (b) deserialize 済みドメイン fixture（component テスト用） ── */

export const sampleChannels: Channel[] = [
  { id: "ch-general", tid: 1, name: "general", slackChannelId: "C100", channelType: "public_channel" },
  { id: "ch-random", tid: 2, name: "random", slackChannelId: "C200", channelType: "public_channel" },
];

export const sampleUsers: User[] = [
  { id: "u-taro", slackUserId: "U1", realName: "Taro Yamada", displayName: "taro", isBot: false, avatar: "https://x/taro.png" },
  { id: "u-hanako", slackUserId: "U2", realName: "Hanako Sato", displayName: "", isBot: false, avatar: "https://x/hanako.png" },
  { id: "u-bot", slackUserId: "U3", realName: "Notifier", displayName: "notifier", isBot: true, avatar: "https://x/bot.png" },
];

/** uuid→User の lookup マップ（author 名解決に使う）。 */
export const sampleUserMap: Record<string, User> = Object.fromEntries(
  sampleUsers.map((u) => [u.id, u]),
);

export const sampleAttachment: Attachment = {
  id: "f-log",
  filename: "log.txt",
  url: "/system/files/slack_archive/latest/files/log.txt",
};

function domainMessage(over: Partial<SlackMessage> & Pick<SlackMessage, "id" | "slackTs" | "postedAt">): SlackMessage {
  return {
    nid: 0,
    title: "",
    body: "",
    threadTs: null,
    subtype: null,
    edited: false,
    replyCount: 0,
    reactions: [],
    authorUuid: null,
    channelUuid: "ch-general",
    attachments: [],
    ...over,
  };
}

/** ドメイン形のメッセージ（component / threads テスト用、新しい順）。 */
export const sampleMessages: SlackMessage[] = [
  domainMessage({ id: "m-orphan", slackTs: "400.0002", threadTs: "400.0001", postedAt: "2026-05-04T07:00:00+09:00", body: "親が取得範囲外の返信", authorUuid: "u-hanako", title: "orphan reply" }),
  domainMessage({ id: "m-bot", slackTs: "300.0001", postedAt: "2026-05-03T08:00:00+09:00", body: "deployment finished", subtype: "bot_message", edited: true, authorUuid: null, title: "deployment finished" }),
  domainMessage({ id: "m-standalone", slackTs: "200.0001", postedAt: "2026-05-02T09:00:00+09:00", body: "添付つきの単独メッセージ", reactions: [{ name: "eyes", count: 1 }], authorUuid: "u-hanako", attachments: [sampleAttachment], title: "standalone message" }),
  domainMessage({ id: "m-reply2", slackTs: "100.0003", threadTs: "100.0001", postedAt: "2026-05-01T10:10:00+09:00", body: "ブロードキャスト返信", subtype: "thread_broadcast", authorUuid: "u-taro", title: "broadcast reply" }),
  domainMessage({ id: "m-reply1", slackTs: "100.0002", threadTs: "100.0001", postedAt: "2026-05-01T10:05:00+09:00", body: "最初の返信", authorUuid: "u-hanako", title: "first reply" }),
  domainMessage({ id: "m-parent", slackTs: "100.0001", threadTs: "100.0001", postedAt: "2026-05-01T10:00:00+09:00", body: "スレッドの親メッセージ", replyCount: 2, reactions: [{ name: "thumbsup", count: 3 }], authorUuid: "u-taro", title: "thread parent" }),
];
