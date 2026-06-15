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
- **Milestone 3 — JSON:API ＋ Search API ＋ プライバシー hardening**:
  - **JSON:API read-only**：`hook_install()` で `jsonapi.settings.read_only = TRUE`。匿名 GET `/jsonapi/node/slack_message` → 200（published のみ）、POST/PATCH/DELETE → 405。`jsonapi_extras` で `default_disabled: true`・ホワイトリスト 4 リソース（`node--slack_message`・`taxonomy_term--slack_channels`・`taxonomy_term--slack_users`・`file--file`）。その他リソース → 404。
  - **Search API DB**：サーバ `slack_db`（`search_api_db` backend）、インデックス `slack_messages`（`entity:node` bundle `slack_message`）。`entity_status` + `content_access` プロセッサで **published のみ索引**。fulltext フィールド: title / body / channel_name / slack_user_name。date フィールド: posted_at。integer フィールド: reaction_total。`index_directly: true`（migrate 同時索引）。
  - **jsonapi_search_api エンドポイント**：`/jsonapi/index/slack_messages` を自動公開。`filter[fulltext]`・facet フィルタ（`filter[channel]` / `filter[slack_user]` / `filter[posted_at]`）・`page[limit/offset]`・`sort` をサポート。
  - **facets**：`jsonapi_search_api_facets` で channel / slack_user / posted_at の 3 facet を設定。レスポンス `meta.facets` に各 facet の terms（value / count / active）を含む。`empty_behavior: none`（0 件は省略）。
  - **canonical アーカイブ private:// 移行（ADR-0014）**：アーカイブ保存先を `public://slack_archive/latest/` から `private://slack_archive/latest/` へ移行し Web 直配信を排除。PII（メッセージ本文・添付）の nginx 直配信を構造的に防止。
  - **ファイルアクセス制御（`hook_file_download`）**：`slack_archive/` ファイルの配信は参照する `slack_message` node がリクエスト者から閲覧可能な場合のみ許可（anonymous → published/public_channel のみ）。それ以外 → 403。
  - **taxonomy_term アクセス制御（`hook_taxonomy_term_access`）**：管理者以外は `field_channel_type != public_channel` の `slack_channels` term を閲覧不可。JSON:API コレクション・include で private / im / mpim チャンネル名を匿名から隠蔽。
  - **CORS**：`web/sites/default/services.yml` の `cors.config` で frontend origin `http://localhost:3000` のみ許可（GET/OPTIONS）。
  - テスト: small（Unit）≫ medium（Kernel）＋ large（Functional）合計約 168 緑。PHPStan L5 ＋ PHPCS clean。
- ADR-0014（canonical アーカイブ private:// 移行、accepted）新規。ADR-0005 / ADR-0008 を `accepted` に更新。
- `docs/spec/` 追加: `jsonapi-search.md`。`docs/how-to/` 追加: `private-files-setup.md`。既存 `docs/spec/` 各ファイルの `public://slack_archive/` 参照を `private://slack_archive/` に更新。
- **Milestone 4 — フロントエンド閲覧（channel / message / thread ブラウズ）**:
  - **データアクセス**（`frontend/lib`）: `getDrupalClient()`（memoized `NextDrupal`）。`next-drupal` の `getResourceCollection(type, { deserialize: false })` で **raw JSON:API（`{ data, included }`）** を取得し pure な mapper（`mapChannel`/`mapUser`/`mapMessage`/`mapAttachment`）で整形。クエリは `drupal-jsonapi-params`（`buildChannelsParams`/`buildUsersParams`/`buildChannelMessagesParams`）。チャンネル絞り込みは `filter[field_channel.meta.drupal_internal__target_id]`。
  - **include 非依存の名前解決**: `useUsers` の全件 1 フェッチ＋`buildUserMap`（uuid→User）で author 名を解決（フォールバック `displayName→realName→slackUserId→Unknown`）。channel 名は選択中チャンネルから解決。
  - **スレッド再構成**: `groupIntoThreads`（純関数）＝親（`thread_ts===slack_ts`）/standalone を root、返信を `thread_ts` で束ね（`thread_broadcast` 含む）、親不在の orphan も単独 root として救済（欠落ゼロ）、root 降順・返信昇順。
  - **時刻表示**: `formatPostedAt`＝offset 付き ISO を固定 Asia/Tokyo・`YYYY/MM/DD HH:mm` に正規化（`formatToParts`、CI の TZ/ロケール非依存）。
  - **UI**（Mantine ＋ TanStack Query）: `ChannelList`（public のみ・# 付き・active）、`MessageCard`（author/時刻/本文 pre-wrap・reactions・編集マーカー・添付。**`dangerouslySetInnerHTML` 不使用**）、`ThreadView`（返信を `aria-expanded`/`aria-controls` 付きトグルで `Collapse` 展開）、`MessageList`（未選択/読込中/エラー/0 件/一覧）、`BrowsePanel`（`AppShell`＝navbar/main/header＋モバイル `Burger`、主見出し `h1`）。`app/page.tsx` を閲覧画面に。
  - **フック**: `useChannels`/`useUsers`/`useChannelMessages`（`enabled: tid != null` の依存クエリ・tid ごとにキー分離）。
  - **プライバシー/セキュリティ**: 匿名で取得できる public チャンネル・published メッセージのみ消費。private 添付（`hook_file_download` 遮断）は `fileMap` 不在で黙って除外。本文・名称はすべて React 標準エスケープで描画。
  - テスト: Vitest **92 件**（builder/mapper/`groupIntoThreads`/`formatPostedAt`/hooks/各コンポーネント。fixture は raw＋ドメインの 2 形態）。Playwright `browse.spec.ts` は local＋CI(ubuntu) で緑（main run 27562781222）。`tsc`/eslint/`next build` 緑。
  - レビュー: 多エージェント adversarial review（21 エージェント・6 観点・各指摘を別エージェントが裏取り）→ 14 件中 10 件確定を全対応（a11y 4・エラー処理・モバイル Burger・テスト堅牢化）。
- ADR-0007（Next.js + next-drupal + React フロントエンド）を `accepted` に更新（M4 で実装確認。版 pin 不要）。
- `docs/spec/` 追加: `frontend-browse.md`（M4 データフロー仕様）。
