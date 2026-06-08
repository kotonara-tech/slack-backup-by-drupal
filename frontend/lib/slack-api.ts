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
