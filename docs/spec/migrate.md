---
title: Migrate 取込仕様（canonical JSON → Drupal エンティティ）
status: confirmed
audience: 開発者（M2 migrate 実装者・運用者）
diataxis: reference
related:
  - docs/spec/data-model.md
  - docs/spec/canonical-json.md
  - docs/spec/ingest-pipeline.md
  - docs/adr/0006-slack-to-drupal-data-model-and-migrate.md
  - docs/adr/0013-anonymous-readability-and-channel-privacy.md
---

# Migrate 取込仕様（確定仕様）

canonical 正規化 JSON（`public://slack_archive/latest/`、[canonical-json.md](./canonical-json.md)）を Drupal エンティティ（[data-model.md](./data-model.md)）へ **migrate_plus で冪等取込**する手順の確定仕様。決定経緯は [ADR-0006](../adr/0006-slack-to-drupal-data-model-and-migrate.md) / [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)。

## 1. migration 群（migration_group `slack_portal`）

migration は **migrate_plus の config entity**（`config/install/`、`migration_group: slack_portal`）。`--group slack_portal` で一括実行。

| id | source plugin | source 入力 | destination | 依存 |
|---|---|---|---|---|
| `slack_channels` | `url`(file/json) | `manifest.json` の `channels` | `entity:taxonomy_term`（slack_channels） | — |
| `slack_users` | `url`(file/json) | `users.json`（ルート配列） | `entity:taxonomy_term`（slack_users） | — |
| `slack_files` | `slack_canonical_files`（custom） | `files/` 配下を走査 | `entity:file`（非コピー） | — |
| `slack_messages` | `slack_canonical_messages`（custom） | `channels/*.json` | `entity:node`（slack_message） | slack_channels, slack_users, slack_files |

**依存順（`migration_dependencies.required`）**: channels / users / files → **messages**。`drush migrate:import --group=slack_portal` はこの順で実行する。

## 2. source plugin

### 2.1 JSON source（channels / users）
`plugin: url`, `data_fetcher_plugin: file`, `data_parser_plugin: json`, `urls: ['public://slack_archive/latest/<file>']`（stream URI 可）。`item_selector` は **users.json はルート配列なので `''`（クオート必須）**、channels は manifest の `channels`。`fields[]`（name/label/selector）で抽出キーを宣言、`ids` に migrate id（id）。

### 2.2 `slack_canonical_messages`（custom）
`SourcePluginBase` ＋ `#[MigrateSource('slack_canonical_messages')]`。`base_dir`（既定 `CanonicalArchive::BASE_DIR` = `public://slack_archive/latest`）配下の `channels/*.json` を **stream wrapper で走査**（`FileSystemInterface::scanDirectory`、realpath/native glob は使わない）。各チャンネルファイルを `CanonicalMessageFlattener::flattenChannel()` で **1 メッセージ＝1 行**に平坦化する:

- 親メッセージ・ネストされた `replies[]`・orphan reply をすべて行化。
- **チャンネル内 `slack_ts` で dedup**（Slack の `thread_broadcast` は top-level と親の `replies[]` に二重に出現するため。実 export で約 25 件/全 916）。
- 各行に所属チャンネルの `channel_id` と **`channel_type`** を付与（status 導出用）。
- `reaction_total` = Σ `reactions[].count` を集計。
- `file_ids` = `files[]` のうち **`local_path` が非 null のものの `id`** のみ。
- `posted_at` は **yield しない**（`slack_ts` から process plugin で導出。canonical の `posted_at` は `Z` 付きで datetime 格納形式と異なるため使わない）。

`getIds()` = `{slack_ts: string}`。`track_changes: true`（編集された行の再取込）。

行のフィールド: `slack_ts, channel_id, channel_type, user_id, bot_id, username, type, subtype, text, edited, thread_ts, reply_count, reactions(array), reaction_total(int), file_ids(array)`。

### 2.3 `slack_canonical_files`（custom）
`base_dir/files/` を走査し、ファイルごとに `{id: pathinfo(filename, FILENAME), uri: 'public://…/files/<name>', filename}` を yield。`id` は拡張子なしのファイル名（例 `F_LOCAL`）で、messages の `file_ids` と一致する。`getIds()` = `{id: string}`。

## 3. process（slack_messages）

| destination | plugin / source |
|---|---|
| `title` | `slack_message_title`（source `[text, slack_ts]`） |
| `field_body/value` | `text` |
| `field_body/format` | `default_value: plain_text` |
| `field_slack_ts` | `slack_ts` |
| `field_slack_user_id` | `user_id` |
| `field_posted_at` | `slack_timestamp_to_datetime`（source `slack_ts`） |
| `field_thread_ts` / `field_subtype` / `field_edited` / `field_reply_count` / `field_reaction_total` | 同名 source 直マップ |
| `field_reactions` | `slack_reactions_to_json`（source `reactions`） |
| `status` | `static_map`（source `channel_type`, `public_channel: 1`, `default_value: 0`） |
| `field_channel` | `migration_lookup`（migration `slack_channels`, source `channel_id`, `no_stub: true`） |
| `field_slack_user` | `skip_on_empty`(process, source `user_id`) → `migration_lookup`（`slack_users`, `no_stub: true`） |
| `field_attachments` | `migration_lookup`（migration `slack_files`, source `file_ids`(配列), `no_stub: true`） |

### 3.1 専用 process plugin（`src/Plugin/migrate/process/`）
- **`slack_timestamp_to_datetime`**: Slack `ts`（`秒.マイクロ秒`）→ `gmdate('Y-m-d\TH:i:s', (int)$ts)`（UTC）。空→NULL。
- **`slack_message_title`**: `[text, slack_ts]` → 非空なら `mb_substr(text,0,255)`、空なら `Message <slack_ts>`。
- **`slack_reactions_to_json`**: reactions 配列を JSON 文字列化。**`handle_multiples: TRUE`** を宣言する（配列 source に対し core `callback`+`json_encode` はパイプラインが要素ごとに反復してしまい不可、という事実への対処）。非配列は `[]`。

> reactions / title はいずれも core の汎用 process（callback / substr / default_value）では正しく実装できず、専用 plugin が必要だった（callback は配列 source を要素反復、substr は mb 非対応・動的 fallback 不可）。

## 4. 冪等性・再取込・privacy

- **冪等**: `ids: slack_ts`（messages）/ id（channels・users・files）。再 import で同一 id は同一エンティティへ update（重複生成なし）。実 export で 2 回目 import = **0 created / 0 updated / 0 failed**。
- **編集再取込**: `track_changes: true`。source 行のハッシュ変化（text/edited 変更等）を検出して該当行のみ再取込（新規 node を作らない）。
- **privacy**: `status` を `channel_type` から導出（§3、[data-model.md](./data-model.md) §4.3）。
- **files 非コピー**: `entity:file` は既存 `public://…/files/<name>` を指す managed file entity を作るのみ（移動/コピーしない）。`status: 1`（permanent）。

## 5. 実行（運用）

```bash
ddev drush migrate:status --group=slack_portal     # 件数確認
ddev drush migrate:import --group=slack_portal      # 取込（依存順に channels→users→files→messages）
ddev drush migrate:rollback --group=slack_portal    # 取消（必要時）
```

config 変更を既存サイトへ反映するときは `drush cim`（or 該当 config を再インストール）＋`drush cr`。

### 5.1 実 export の取込結果（直近 90 日、検証値）
- source 行: channels 119 / users 140 / files 65 / messages **916**（top-level 497＋折込 reply−thread_broadcast 重複 25）。
- 生成: slack_channels term 119 / slack_users term 140 / file 65 / **slack_message node 916**（published 141・unpublished 775）。49 node に添付。
- 2 回目 import で件数不変（冪等）。

> PII/secrets: 実 export は実名・DM 本文・user id 等の PII を含む。**DB ダンプを共有しない・PR/ログに PII を貼らない**（[ADR-0009](../adr/0009-secrets-and-pii-handling.md)）。`public://slack_archive/` と DB は gitignore/非コミット。

## 6. 関連
- データモデル: [data-model.md](./data-model.md) ／ 入力スキーマ: [canonical-json.md](./canonical-json.md) ／ 生成: [ingest-pipeline.md](./ingest-pipeline.md)
- 決定: [ADR-0006](../adr/0006-slack-to-drupal-data-model-and-migrate.md), [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)
