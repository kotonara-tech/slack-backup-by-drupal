/**
 * Drupal JSON:API のベース URL。
 * next-drupal クライアントの初期化と JSON:API/jsonapi_search_api アクセスは
 * docs/plan/04・05 で TDD により追加する（このファイルは骨組み）。
 */
export const DRUPAL_BASE_URL: string =
  process.env.NEXT_PUBLIC_DRUPAL_BASE_URL ?? "https://slack-backup-by-drupal.ddev.site";
