# slack_portal（コンポーネント規約）

Slack 取得から Drupal 公開までを担う単一カスタムモジュール。`CLAUDE.md` と `.claude/rules/` が上位。実装は **PHPUnit TDD**（`.claude/rules/test-pyramid.md`）で `docs/plan` の順に積む。

## 役割と配置
- **ingest（取得）**：`src/Service/`（SlackClientFactory・SlackFetcher・CanonicalJsonWriter）、`src/Drush/Commands/`（`slack:export`）、`src/Plugin/QueueWorker/`（再開可能フェッチ）。`jolicode/slack-php-api` ＋ `caseyamcl/guzzle_retry_middleware` ＋ Queue/Batch。
- **portal（公開）**：`migrations/`（migrate_plus YAML）、`config/install/`（content type `slack_message`・taxonomy `slack_channels`・Search API index・facets・JSON:API 設定）、`src/Plugin/migrate/{source,process}/`。

## テスト（PHPUnit のみ。pytest/ruff/mypy は使わない）
- `tests/src/Unit`（small・`UnitTestCase`、Slack は Guzzle `MockHandler`）
- `tests/src/Kernel`（medium・`KernelTestBase`、migrate/Search API/QueueWorker）
- `tests/src/Functional`（large・`BrowserTestBase`/`JsonApiFunctionalTestBase`、JSON:API read-only・検索）
- `tests/src/ExistingSite`（任意・DTT、nightly スモーク）
- 実行：`ddev exec vendor/bin/phpunit -c web/phpunit.xml`。静的解析：`phpstan`(level5)＋`phpcs`(Drupal/DrupalPractice)。

## 注意
- token は Key/env、ログ禁止。canonical アーカイブ（`private://slack_archive/`）は非コミット（`.claude/rules/secrets-and-pii.md`）。
- 設計判断は ADR（`documentor`）。承認済み ADR は不変。
