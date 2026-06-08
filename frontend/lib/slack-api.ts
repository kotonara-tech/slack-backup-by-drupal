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
  Channel,
  JsonApiResource,
  JsonApiResponse,
  User,
} from "@/lib/types/slack";

/** チャンネル絞り込み filter のパス（実 API 検証済み。変更はここ 1 箇所）。 */
export const CHANNEL_FILTER_PATH =
  "field_channel.meta.drupal_internal__target_id";

/** チャンネル一覧（匿名→public のみ）。name 昇順。 */
export function buildChannelsParams(): DrupalJsonApiParams {
  return new DrupalJsonApiParams().addSort("name").addPageLimit(200);
}

/** ユーザ一覧（author 名引き lookup マップ用）。1 ページで全件。 */
export function buildUsersParams(): DrupalJsonApiParams {
  return new DrupalJsonApiParams().addPageLimit(200);
}

/** 指定チャンネル（tid）のメッセージ。新しい順・添付 include・最大 100 件。 */
export function buildChannelMessagesParams(tid: number): DrupalJsonApiParams {
  return new DrupalJsonApiParams()
    .addFilter(CHANNEL_FILTER_PATH, String(tid))
    .addInclude(["field_attachments"])
    .addSort("field_posted_at", "DESC")
    .addPageLimit(100);
}

/* ── mappers ── */

function str(value: unknown): string {
  return value == null ? "" : String(value);
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
