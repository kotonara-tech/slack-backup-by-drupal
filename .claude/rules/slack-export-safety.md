# Rule: Slack 取得の安全

## ⚠️ 90 日 erosion = 最優先
- 無料 Slack workspace の可視履歴は**直近〜90 日**のみ。**毎日 1 日分ずつ古い履歴が見えなくなる**（>1 年は完全削除）。公式の一括エクスポートは無料プラン不可。
- よって**実エクスポートが最優先**（`docs/plan/01-real-slack-export.md`）。TDD カバレッジ完成前でも、消えゆくデータを確保するため早期に 1 回 `make export` を実行してよい（その後テストで固める）。

## トークン
- **user token（`xoxp-`）** を使う（private/DM/MPDM の読取、かつ 2025-05-29 以降の新 bot に課される **Tier-1=1req/min** 制限の回避のため）。
- 必要スコープ：`channels:history, groups:history, im:history, mpim:history, channels:read, groups:read, im:read, mpim:read, users:read, files:read, reactions:read`。
- 取扱は `.claude/rules/secrets-and-pii.md`（env/Key、ログ禁止）。

## API の作法
- **cursor pagination**：`response_metadata.next_cursor` が空になるまでループ。`limit` は 100–200。
- **429 / Retry-After**：`caseyamcl/guzzle_retry_middleware` で指数バックオフ（`Retry-After` を尊重）。
- **スレッド**：`thread_ts == ts` の親に対し `conversations.replies` を取得。
- **ファイル**：`files.list`/`files.info` → `url_private` を **Bearer ヘッダ＋`stream=true`** でディスクへ（メモリに載せない）。
- **取得対象**：public / private / DM / MPDM / threads / files / users / reactions。

## 冪等・再開
- Drupal **Queue/Batch API** でチャンネル/ページ単位に分割し、再開可能・冪等に。長時間は Drush（Web タイムアウトなし）。
- cursor は概ね 24h で失効しうる。長時間停止後は cursor=null から再取得する。
- canonical 正規化 JSON を正典として書き、`migrate_plus` で Drupal エンティティへ冪等取込（再実行で重複しない）。
