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
- **Milestone 2 — canonical JSON → Drupal エンティティ（migrate 取込）**:
  - **データモデル**（`slack_portal` config/install）: `slack_message` content type（`field_body`・`field_slack_ts`(一意)・`field_posted_at`・`field_channel`/`field_slack_user`(taxonomy 参照)・`field_slack_user_id`・`field_reactions`(JSON)＋`field_reaction_total`・`field_attachments`(file 参照)・`field_thread_ts`/`field_subtype`/`field_edited`/`field_reply_count`）＋ `slack_channels`/`slack_users` taxonomy（**email 非取込**）。
  - **公開範囲ゲーティング（Option B）**: `status` を channel 種別から導出（`public_channel`→published＝匿名 read 可、`private_channel`/`im`/`mpim`→unpublished）。実 export 916 node 中 published 141 / unpublished 775。
  - **migrate**: migration_group `slack_portal` ＋ `slack_channels`/`slack_users`/`slack_files`/`slack_messages` migration（依存順 channels→users→files→messages）。カスタム source `slack_canonical_messages`（親＋折込 reply を平坦化・**thread_broadcast を slack_ts dedup**・`channel_type`/`reaction_total`/`file_ids` 付与）・`slack_canonical_files`。process plugin `slack_timestamp_to_datetime`・`slack_message_title`・`slack_reactions_to_json`。`ids=slack_ts`＋`track_changes` で**冪等＋編集再取込**。files は既存 `public://` を managed file として**非コピー**登録。
  - 検証: Unit+Kernel テスト緑（PHPUnit 142 / 723 assert、small≫medium）、PHPStan L5 ＋ PHPCS clean。実 export を `drush migrate:import --group=slack_portal` で取込 → **916 node**・119 channel term・140 user term・65 file、再実行で件数不変。
- ADR-0013（匿名閲覧可否とチャンネルプライバシー、accepted）新規。ADR-0006 を `accepted` に更新。
- `docs/spec/` 追加: `data-model.md`・`migrate.md`。
