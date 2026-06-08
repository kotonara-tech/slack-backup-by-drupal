/**
 * Drupal JSON:API のベース URL と read-only クライアント。
 *
 * `getDrupalClient()` は next-drupal の `NextDrupal` を生成して memoize する
 * （ADR-0007）。匿名 read 専用のため auth は設定しない。実データ取得（チャンネル
 * /ユーザ/メッセージ）は `lib/slack-api.ts` がこのクライアントの
 * `getResourceCollection(..., { deserialize: false })` を使い、raw JSON:API
 * （`{ data, included }`）を pure な mapper で整形する。
 */
import { NextDrupal } from "next-drupal";

export const DRUPAL_BASE_URL: string =
  process.env.NEXT_PUBLIC_DRUPAL_BASE_URL ?? "https://slack-backup-by-drupal.ddev.site";

let client: NextDrupal | undefined;

/**
 * 共有の next-drupal クライアントを返す（memoized）。
 *
 * baseUrl には `/jsonapi` サフィックスを付けない（next-drupal が付与する）。
 */
export function getDrupalClient(): NextDrupal {
  if (!client) {
    client = new NextDrupal(DRUPAL_BASE_URL);
  }
  return client;
}
