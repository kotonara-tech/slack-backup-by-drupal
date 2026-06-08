---
title: Drupal データモデル仕様（slack_message / slack_channels / slack_users / file）
status: confirmed
audience: 開発者（M2 migrate 実装者・M3 JSON:API/検索実装者・フロント実装者）
diataxis: reference
related:
  - docs/spec/migrate.md
  - docs/spec/canonical-json.md
  - docs/adr/0006-slack-to-drupal-data-model-and-migrate.md
  - docs/adr/0013-anonymous-readability-and-channel-privacy.md
---

# Drupal データモデル仕様（確定仕様）

本書は canonical 正規化 JSON（[canonical-json.md](./canonical-json.md)）を取り込む先の **Drupal エンティティモデル**の確定仕様（what）である。決定経緯は [ADR-0006](../adr/0006-slack-to-drupal-data-model-and-migrate.md)、公開範囲（status/privacy）と email 非取込は [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)。取込手順（how）は [migrate.md](./migrate.md)。

すべての config は `slack_portal` モジュールの `config/install/` に置かれ、`dependencies.enforced.module: [slack_portal]` を持つ（module uninstall で除去）。

---

## 1. エンティティ一覧

| エンティティ | 種別 | bundle/vid | 由来 |
|---|---|---|---|
| メッセージ | node（content type） | `slack_message` | canonical メッセージ（親＋折込 reply＋orphan、dedup 後） |
| チャンネル | taxonomy term | vocabulary `slack_channels` | `manifest.json` の `channels[]` |
| ユーザ | taxonomy term | vocabulary `slack_users` | `users.json` |
| 添付ファイル | file（managed file） | — | `private://slack_archive/latest/files/<id>.<ext>`（非コピー登録、M3 で public:// から移行・ADR-0014） |

> Slack ユーザは Drupal `user` ではなく **taxonomy term**（サイトアカウントではないため）。添付は Slack の `file` 型ではなく **plain entity_reference → file**（既存 public:// を参照、file usage は登録しない）。

---

## 2. `slack_channels`（taxonomy vocabulary）

term の `name` ＝チャンネル名。

| フィールド | 型 | cardinality | 説明 |
|---|---|---|---|
| `field_slack_channel_id` | string(255) | 1 | Slack channel id（`C…`/`G…`/`D…`/`mpdm-…`）。migrate id |
| `field_channel_type` | string(255) | 1 | `public_channel` / `private_channel` / `im` / `mpim` |

## 3. `slack_users`（taxonomy vocabulary）

term の `name` ＝ユーザの `name`（ハンドル）。**`field_email` は存在しない**（ADR-0013、PII 非取込）。

| フィールド | 型 | cardinality | 説明 |
|---|---|---|---|
| `field_slack_user_id` | string(255) | 1 | Slack user id（`U…`）。migrate id |
| `field_real_name` | string(255) | 1 | 実名（匿名表示を許容＝ADR-0013） |
| `field_display_name` | string(255) | 1 | 表示名 |
| `field_is_bot` | boolean | 1 | bot か |
| `field_avatar` | uri(2048) | 1 | 192px アバター URL |

## 4. `slack_message`（node content type）

`new_revision: true`。`title` は必須（§4.2）。`status`（published/unpublished）で公開範囲を制御（§4.3）。

### 4.1 フィールド

| フィールド | 型 / settings | cardinality | source / 導出 |
|---|---|---|---|
| `title`（base） | string(255) | 1 | text を 255 文字（mb 安全）に切詰、空なら `Message <slack_ts>`（process plugin `slack_message_title`） |
| `status`（base） | boolean | 1 | `channel_type` 由来（§4.3） |
| `field_body` | text_long（format `plain_text`） | 1 | `value`←text、`format`←`plain_text`（固定） |
| `field_slack_ts` | string(255) | 1 | `slack_ts`（チャンネル内一意。migrate 一意キーは **複合 `[channel_id, slack_ts]`**＝[migrate.md](./migrate.md) §2.2） |
| `field_posted_at` | datetime（`datetime_type: datetime`） | 1 | `slack_ts` の整数秒→`Y-m-d\TH:i:s`（UTC、process plugin `slack_timestamp_to_datetime`、`Z` 無し） |
| `field_slack_user_id` | string(255) | 1 | `user_id`（常時保持。bot 等で null の場合あり） |
| `field_channel` | entity_reference→taxonomy_term（target_bundles `slack_channels`, auto_create false） | 1 | `channel_id` を `slack_channels` migration で lookup（no_stub） |
| `field_slack_user` | entity_reference→taxonomy_term（target_bundles `slack_users`, auto_create false） | 1 | `user_id` を `slack_users` migration で lookup（skip_on_empty→no_stub） |
| `field_thread_ts` | string(255) | 1 | `thread_ts`（親＝`thread_ts==slack_ts`） |
| `field_subtype` | string(255) | 1 | `subtype`（例 `thread_broadcast`/`bot_message`） |
| `field_edited` | boolean | 1 | `edited` |
| `field_reply_count` | integer | 1 | `reply_count` |
| `field_reactions` | string_long | 1 | reactions 配列の JSON 文字列（process plugin `slack_reactions_to_json`） |
| `field_reaction_total` | integer | 1 | Σ `reactions[].count`（M3 facet 用、source で集計） |
| `field_attachments` | entity_reference→file（handler `default:file`） | -1 | `file_ids`（local_path 有のみ）を `slack_files` migration で lookup（no_stub） |

### 4.2 `title` 導出
`slack_message_title` process plugin が source `[text, slack_ts]` から導出する。`text` が非空なら `mb_substr(text, 0, 255)`、空なら `'Message ' . slack_ts`。node の必須 title が空にならないことを保証する。

### 4.3 `status`（公開範囲＝privacy gating）
canonical の message 行は privacy フラグを持たないため、source plugin が各行に **`channel_type`** を付与し、migration が `static_map` で `status` を導出する（[ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)）。

| `channel_type` | `status` | 匿名閲覧 |
|---|---|---|
| `public_channel` | 1（published） | 可 |
| `private_channel` / `im` / `mpim` | 0（unpublished） | 不可（将来の認証時のみ） |

実 export（直近 90 日）では **916 node 中 published 141 / unpublished 775**。M3 の Search API は **published のみ索引**する（plan/03 申し送り）。

### 4.4 reactions の保持
`field_reactions` は canonical の `reactions[]`（`[{name,count,users[]}]`）を **JSON 文字列**として保持する（`json_encode`）。集計値 `field_reaction_total`（Σ count）を別途 integer で持ち、M3 の facet/ソートを DB backend で可能にする。

---

## 5. 参照整合・stub

- `field_channel` / `field_slack_user` / `field_attachments` の lookup はすべて **`no_stub: true`**。参照先が未取込/不在でも **stub を作らない**（空参照のまま）。
- `field_slack_user` は `skip_on_empty`（method: process）を前置し、**`user_id` が空（bot メッセージ等）なら参照を立てない**（stub も作らない）。

---

## 6. 関連ドキュメント
- 取込手順（source/process/ids/依存順/dedup/track_changes）: [migrate.md](./migrate.md)
- 入力スキーマ: [canonical-json.md](./canonical-json.md)
- 決定: [ADR-0006](../adr/0006-slack-to-drupal-data-model-and-migrate.md)（モデル）, [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)（privacy/email）, [ADR-0014](../adr/0014-canonical-archive-private-stream.md)（private:// 移行）
