# ロードマップ（docs/plan）

TDD（Red→Green→Refactor）と Google テストピラミッド（small≫medium>large）で、E2E で動く機能を順に積む。各マイルストーンは `- [ ]` ToDo（size タグ付き）。実際の進捗の真実源は `~/.claude/plans/<slug>.md`（リポ外）だが、正本のロードマップはここ。`documentor` が維持。

| # | ファイル | 概要 |
|---|---------|------|
| 00 | [00-bootstrap.md](00-bootstrap.md) | 土台＋骨組み（本フェーズ） |
| 01 | [01-real-slack-export.md](01-real-slack-export.md) | ★ 実 Slack エクスポート（最優先・90日 erosion 対策） |
| 02 | [02-drupal-model-and-migrate.md](02-drupal-model-and-migrate.md) | データモデル＋Migrate 取込 |
| 03 | [03-jsonapi-and-search.md](03-jsonapi-and-search.md) | JSON:API ＋ Search API ＋ jsonapi_search_api |
| 04 | [04-frontend-browse.md](04-frontend-browse.md) | フロント：閲覧 |
| 05 | [05-frontend-search.md](05-frontend-search.md) | フロント：全文検索 |

> ⚠️ 01 が最優先。無料 Slack の可視履歴は毎日消える。土台完成後すぐ着手する。
