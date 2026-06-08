/**
 * M4 閲覧 UI のドメイン型と、消費する raw JSON:API リソース型。
 *
 * JSON:API は `next-drupal` の `getResourceCollection(type, { deserialize: false })`
 * で raw（`{ data, included }`）のまま受け取り、`lib/slack-api.ts` の pure な mapper で
 * 下記ドメイン型へ整形する。契約は docs/spec/jsonapi-search.md・data-model.md。
 */

/* ── raw JSON:API ── */

/** relationship の単一データ参照（`{ type, id(uuid), meta }`）。 */
export interface JsonApiRelationshipData {
  type: string;
  /** リソースの UUID。 */
  id: string;
  meta?: { drupal_internal__target_id?: number };
}

/** relationship 1 つ（to-one は object、to-many は配列、未設定は null）。 */
export interface JsonApiRelationship {
  data: JsonApiRelationshipData | JsonApiRelationshipData[] | null;
}

/** JSON:API リソースオブジェクト 1 件。 */
export interface JsonApiResource<A = Record<string, unknown>> {
  type: string;
  id: string;
  attributes: A;
  relationships?: Record<string, JsonApiRelationship>;
}

/** コレクション応答（`deserialize: false`）。 */
export interface JsonApiResponse<A = Record<string, unknown>> {
  data: Array<JsonApiResource<A>>;
  included?: JsonApiResource[];
  meta?: Record<string, unknown>;
  links?: Record<string, unknown>;
}

/* ── ドメイン型 ── */

/** チャンネル（taxonomy_term--slack_channels）。匿名は public のみ取得できる。 */
export interface Channel {
  /** term の UUID。 */
  id: string;
  /** drupal_internal__tid（メッセージ絞り込みの filter 値）。 */
  tid: number;
  name: string;
  slackChannelId: string;
  /** public_channel / private_channel / im / mpim。 */
  channelType: string;
}

/** ユーザ（taxonomy_term--slack_users）。author 名引きの lookup マップに使う。 */
export interface User {
  /** term の UUID（message.authorUuid と突き合わせる）。 */
  id: string;
  slackUserId: string;
  realName: string;
  displayName: string;
  isBot: boolean;
  avatar: string;
}

/** リアクション 1 種（`field_reactions` JSON 文字列を parse した要素）。 */
export interface Reaction {
  name: string;
  count: number;
}

/** 添付ファイル（field_attachments → file--file、include で解決）。 */
export interface Attachment {
  /** file の UUID。 */
  id: string;
  filename: string;
  url: string;
}

/** メッセージ（node--slack_message）。include 非依存に attributes＋relationship.data.id のみ使う。 */
export interface SlackMessage {
  /** node の UUID。 */
  id: string;
  nid: number;
  title: string;
  /** field_body.value（format=plain_text のプレーンテキスト）。 */
  body: string;
  slackTs: string;
  /** 親は threadTs === slackTs。スレッド外は null。 */
  threadTs: string | null;
  /** offset 付き ISO 文字列（例 `2026-05-17T15:38:27+09:00`）。 */
  postedAt: string;
  subtype: string | null;
  edited: boolean;
  replyCount: number;
  reactions: Reaction[];
  /** field_slack_user.data.id（bot 等で null）。 */
  authorUuid: string | null;
  /** field_channel.data.id。 */
  channelUuid: string | null;
  /** field_attachments を included の file から解決した添付（public は通常 0 件）。 */
  attachments: Attachment[];
}

/** スレッド（親 1 件＋時系列の返信）。`groupIntoThreads` が再構成する。 */
export interface Thread {
  root: SlackMessage;
  replies: SlackMessage[];
  /** 表示用の返信数（実返信数。field_reply_count とは別に算出）。 */
  replyCount: number;
}
