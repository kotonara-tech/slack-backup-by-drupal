/**
 * JSON:API read-only データアクセス（M4 閲覧）。
 *
 * 構成:
 *  - クエリビルダ（drupal-jsonapi-params）: channels / users / channel-messages。
 *  - fetch 関数: next-drupal クライアントの `getResourceCollection(..., { deserialize: false })`
 *    で raw JSON:API を取得し、pure な mapper でドメイン型へ整形する。
 *
 * include 非依存の堅牢設計（docs/plan/04・woolly-honking-moore）:
 *  - author 名は `fetchUsers()` の lookup マップ（uuid→User）で解決する。
 *  - channel 名は選択中チャンネルから解決する。
 *  - 添付のみ `include=field_attachments` を使い、`included` の file から解決する。
 */
import { DrupalJsonApiParams } from "drupal-jsonapi-params";
import type { NextDrupal } from "next-drupal";

import { getDrupalClient } from "@/lib/drupal";
import type {
  Attachment,
  Channel,
  JsonApiRelationship,
  JsonApiResource,
  JsonApiResponse,
  Reaction,
  SlackMessage,
  User,
} from "@/lib/types/slack";

/** チャンネル絞り込み filter のパス（実 API 検証済み。変更はここ 1 箇所）。 */
export const CHANNEL_FILTER_PATH =
  "field_channel.meta.drupal_internal__target_id";

/** チャンネルメッセージの 1 ページあたり件数（追加読み込みの offset 計算にも使う）。 */
export const CHANNEL_MESSAGES_PAGE_LIMIT = 100;

/** チャンネル一覧（匿名→public のみ）。name 昇順。 */
export function buildChannelsParams(): DrupalJsonApiParams {
  return new DrupalJsonApiParams().addSort("name").addPageLimit(200);
}

/** ユーザ一覧（author 名引き lookup マップ用）。1 ページで全件。 */
export function buildUsersParams(): DrupalJsonApiParams {
  return new DrupalJsonApiParams().addPageLimit(200);
}

/** 指定チャンネル（tid）のメッセージ。新しい順・添付 include・最大 100 件・offset 指定可。 */
export function buildChannelMessagesParams(
  tid: number,
  offset = 0,
): DrupalJsonApiParams {
  return new DrupalJsonApiParams()
    .addFilter(CHANNEL_FILTER_PATH, String(tid))
    .addInclude(["field_attachments"])
    .addSort("field_posted_at", "DESC")
    .addPageLimit(CHANNEL_MESSAGES_PAGE_LIMIT)
    .addPageOffset(offset);
}

/* ── mappers ── */

function str(value: unknown): string {
  return value == null ? "" : String(value);
}

/** to-one relationship の参照 UUID（未設定/配列なら null）。 */
function singleRefId(rel: JsonApiRelationship | undefined): string | null {
  const data = rel?.data;
  if (!data || Array.isArray(data)) return null;
  return data.id;
}

/** to-many relationship の参照 UUID 配列。 */
function manyRefIds(rel: JsonApiRelationship | undefined): string[] {
  const data = rel?.data;
  return Array.isArray(data) ? data.map((d) => d.id) : [];
}

/** `field_reactions`（JSON 文字列）を Reaction[] へ。壊れた入力でも throw しない。 */
export function parseReactions(value: unknown): Reaction[] {
  if (typeof value !== "string" || value === "") return [];
  try {
    const parsed: unknown = JSON.parse(value);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter(
        (x): x is { name: unknown; count?: unknown } =>
          !!x && typeof x === "object",
      )
      .filter((x) => typeof x.name === "string")
      .map((x) => ({ name: String(x.name), count: Number(x.count ?? 0) }));
  } catch {
    return [];
  }
}

/** raw term → Channel。 */
export function mapChannel(raw: JsonApiResource): Channel {
  const a = raw.attributes;
  return {
    id: raw.id,
    tid: Number(a.drupal_internal__tid ?? 0),
    name: str(a.name),
    slackChannelId: str(a.field_slack_channel_id),
    channelType: str(a.field_channel_type),
  };
}

/** raw term → User。 */
export function mapUser(raw: JsonApiResource): User {
  const a = raw.attributes;
  return {
    id: raw.id,
    slackUserId: str(a.field_slack_user_id),
    realName: str(a.field_real_name),
    displayName: str(a.field_display_name),
    isBot: Boolean(a.field_is_bot),
    avatar: str(a.field_avatar),
  };
}

/** raw file--file → Attachment。 */
export function mapAttachment(raw: JsonApiResource): Attachment {
  const a = raw.attributes;
  return { id: raw.id, filename: str(a.filename), url: str(a.url) };
}

/** `included` の file--file を uuid→Attachment マップへ。 */
export function buildFileMap(
  included: JsonApiResource[],
): Record<string, Attachment> {
  return Object.fromEntries(
    included
      .filter((r) => r.type === "file--file")
      .map((f) => [f.id, mapAttachment(f)]),
  );
}

/**
 * raw node → SlackMessage（include 非依存）。
 *
 * author / channel は relationship の `data.id`（UUID）だけを取り、名前は呼び出し側の
 * lookup マップで解決する。添付は `fileMap`（included 由来）で解決し、private 等で
 * fileMap に無いものは黙って除外する（hook_file_download の遮断と整合）。
 */
export function mapMessage(
  raw: JsonApiResource,
  fileMap: Record<string, Attachment> = {},
): SlackMessage {
  const a = raw.attributes;
  const rels = raw.relationships ?? {};
  const body = a.field_body as { value?: unknown } | undefined;
  const threadTs = a.field_thread_ts;
  const subtype = a.field_subtype;
  return {
    id: raw.id,
    nid: Number(a.drupal_internal__nid ?? 0),
    title: str(a.title),
    body: str(body?.value),
    slackTs: str(a.field_slack_ts),
    threadTs: threadTs == null ? null : String(threadTs),
    postedAt: str(a.field_posted_at),
    subtype: subtype == null ? null : String(subtype),
    edited: Boolean(a.field_edited),
    replyCount: Number(a.field_reply_count ?? 0),
    reactions: parseReactions(a.field_reactions),
    authorUuid: singleRefId(rels.field_slack_user),
    channelUuid: singleRefId(rels.field_channel),
    attachments: manyRefIds(rels.field_attachments)
      .map((id) => fileMap[id])
      .filter((x): x is Attachment => Boolean(x)),
  };
}

/* ── fetchers ── */

/** raw コレクションを deserialize:false で取得する共通ヘルパ。 */
async function getCollection(
  client: NextDrupal,
  type: string,
  params: DrupalJsonApiParams,
): Promise<JsonApiResponse> {
  return client.getResourceCollection<JsonApiResponse>(type, {
    deserialize: false,
    params: params.getQueryObject(),
  });
}

/** public チャンネル一覧（匿名）。 */
export async function fetchChannels(
  client: NextDrupal = getDrupalClient(),
): Promise<Channel[]> {
  const res = await getCollection(
    client,
    "taxonomy_term--slack_channels",
    buildChannelsParams(),
  );
  return (res.data ?? []).map(mapChannel);
}

/** 全ユーザ一覧（author 名引き lookup マップの元）。 */
export async function fetchUsers(
  client: NextDrupal = getDrupalClient(),
): Promise<User[]> {
  const res = await getCollection(
    client,
    "taxonomy_term--slack_users",
    buildUsersParams(),
  );
  return (res.data ?? []).map(mapUser);
}

/** User[] を uuid→User の lookup マップへ（author 名解決に使う）。 */
export function buildUserMap(users: User[]): Record<string, User> {
  return Object.fromEntries(users.map((u) => [u.id, u]));
}

/** 1 ページ分のチャンネルメッセージ（追加読み込み用に残ページの有無を持つ）。 */
export interface ChannelMessagesPage {
  messages: SlackMessage[];
  /** 残ページの有無。`links.next` があれば true、無ければ件数が limit と同じかで判定。 */
  hasNext: boolean;
}

/** 指定チャンネル（tid）のメッセージ。添付は included から解決する。offset で追加読み込み。 */
export async function fetchChannelMessages(
  tid: number,
  offset = 0,
  client: NextDrupal = getDrupalClient(),
): Promise<ChannelMessagesPage> {
  const res = await getCollection(
    client,
    "node--slack_message",
    buildChannelMessagesParams(tid, offset),
  );
  const fileMap = buildFileMap(res.included ?? []);
  const messages = (res.data ?? []).map((m) => mapMessage(m, fileMap));
  const hasNext =
    res.links?.next != null || messages.length === CHANNEL_MESSAGES_PAGE_LIMIT;
  return { messages, hasNext };
}
