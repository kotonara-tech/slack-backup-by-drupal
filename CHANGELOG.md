# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).
本ファイルは `documentor` エージェントが管理する。

## [Unreleased]

### Added
- エージェント自走開発の土台＋骨組み：`CLAUDE.md`、`.claude/{agents,rules,skills,settings}`、`tools/` ガードスクリプト、`docs/{adr,plan,tutorials,how-to,reference,explanation}`、`.ddev/config.yaml`、`composer.json`、`phpstan.neon`、`phpcs.xml`、`web/modules/custom/slack_portal` モジュール骨格、`frontend/`（Next.js）骨格、`Makefile`、`docker-compose.yml`、`.pre-commit-config.yaml`、CI ワークフロー。
- 初期 ADR 0001–0011（Status: proposed）。
- **Milestone 1 — 実 Slack エクスポート＋ポータル管理**:
  - **Ingest エンジン**（`slack_portal` モジュール）: `SlackClientFactory`（`xoxp-` Bearer ＋ 429/503 retry backoff）、`CursorIterator`、`SlackFetcher`（conversations.list/history/replies・users.list・files.list）、`CanonicalMessageFormatter`、`ThreadFolder`（スレッド畳込）、`SlackFileDownloader`（Bearer+stream・冪等 skip）、`CanonicalJsonWriter`（`public://slack_archive/latest/` に byte 同一の決定的書込）、`ChannelExporter`、`drush slack:export --since=90d`。
  - **Credential 管理**: `SlackTokenProvider`（Settings→env→暗号化 State の解決順）、`drupal/key`＋`drupal/encrypt`(real_aes) による token の at-rest 暗号化（暗号鍵は env `SLACK_ENCRYPTION_KEY`、cloud は外部 provider に差替可）、管理フォーム `/admin/config/services/slack-portal`、`administer slack_portal` 権限。
  - **トリガ/状態 API ＋ background 実行**: `POST /api/slack-portal/export`・`GET /api/slack-portal/status`（権限＋CSRF＋CORS）、`ExportTrigger`、`SlackFetchQueueWorker`（cron）、`ExportStateService`（idle/running/done/error）。
  - **フロント trigger/status UI**: `lib/slack-portal.ts`（API クライアント）、`useExportStatus`/`useTriggerExport`（TanStack Query、running 中ポーリング）、`SlackExportPanel`（Mantine: トリガ Button＋状態 Badge＋Progress＋counts＋エラー Alert）、`/admin/export` 画面。
  - テスト: small（Unit=PHPUnit＋Vitest）＋ medium（Kernel）緑、large（Functional 相当＋モック Playwright）追加。PHPStan L5 ＋ PHPCS clean、frontend tsc/eslint/build 緑。
- ADR-0012（ポータル管理 credential ＋ トリガ background ingest、accepted）。ADR-0003 / ADR-0004 を `accepted` に更新。
- `docs/spec/`（確定仕様）新設: `canonical-json.md`・`ingest-pipeline.md`・`credentials.md`・`portal-api.md`。
