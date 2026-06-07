---
title: canonical JSON スキーマ／アーカイブ仕様
status: confirmed
audience: 開発者（M2 migrate 実装者・フロント実装者）
diataxis: reference
related:
  - docs/spec/ingest-pipeline.md
  - docs/adr/0004-storage-canonical-json-and-mariadb.md
  - docs/adr/0006-slack-to-drupal-data-model-and-migrate.md
---

# canonical JSON スキーマ／アーカイブ仕様（確定仕様）

本書は `slack_portal` モジュールが Slack ワークスペースから生成する **canonical 正規化 JSON** の確定スキーマと、アーカイブのディレクトリ構成を定義する **reference**（Diátaxis）である。ここでの記述は実装ソースに基づく「何が出力されるか（what）」の正典であり、コードおよび M2 の migrate ステップ（ADR-0006）から参照される。

- 「どのように生成されるか（how）」は [docs/spec/ingest-pipeline.md](./ingest-pipeline.md) を参照。
- 保存形式の決定経緯は [ADR-0004](../adr/0004-storage-canonical-json-and-mariadb.md)。
- Drupal エンティティへの取込（migrate）の決定は [ADR-0006](../adr/0006-slack-to-drupal-data-model-and-migrate.md)。

> 注意（PII / secrets）: canonical アーカイブは PII（実名・email・DM 本文・ユーザ ID）を含むため、`public://slack_archive/` は gitignore 済みで **commit 禁止**。`url_private` は後述のとおりダウンロード後に `REDACTED` へ置換され、Slack token は一切アーカイブに残らない。

---

## 1. アーカイブのディレクトリ構成

すべてのファイルは安定・決定的なツリー `public://slack_archive/latest/` 配下に書かれる（基準ディレクトリは `CanonicalJsonWriter::baseDir()` が返す固定値 `public://slack_archive/latest`）。`public://` は Drupal のパブリックファイルシステム（通常 `web/sites/<site>/files/`）に解決される。

```
public://slack_archive/latest/
├── manifest.json              # エクスポート全体のメタ情報・インデックス
├── users.json                 # ユーザ一覧（トップレベル JSON 配列）
├── channels/
│   ├── <channel_id>.json      # チャンネル 1 件ぶんのメタ＋メッセージ（例: C123456.json）
│   └── …
└── files/
    ├── <file_id>.<ext>        # 添付ファイル実体（例: F0123.png）
    └── …
```

- `channels/<channel_id>.json` のファイル名はチャンネル ID（`C…` / `G…` / `D…` / `mpdm-…`）。
- `files/<file_id>.<ext>` の拡張子は添付ファイル名から導出した小文字拡張子。拡張子が無い場合は `bin`（`ChannelExporter::fileExtension()`）。
- これらのファイル実体（`files/` 配下）はメッセージオブジェクトの `files[].local_path` から相対パス `files/<file_id>.<ext>` で参照される。

---

## 2. 冪等性とエンコーディング（byte 同一保証）

`CanonicalJsonWriter` は同一入力に対し **byte レベルで同一**の出力を生成する。保証の根拠は以下のとおり。

- **安定・決定的なパス**: 出力は常に `latest/` 配下の固定パスへ書かれ、再実行時は同名ファイルを**上書き**する（`<ts>/` のようなタイムスタンプ別ディレクトリは作らない）。
- **決定的な JSON エンコード**: すべての JSON は次のフラグでエンコードされる。

  ```php
  json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
  );
  ```

  - `JSON_PRETTY_PRINT`: 整形（インデント）して可読・差分しやすく固定化。
  - `JSON_UNESCAPED_UNICODE`: 日本語等を `\uXXXX` にエスケープせずそのまま出力。
  - `JSON_UNESCAPED_SLASHES`: `/` を `\/` にエスケープしない。
- **rename 回避**: Drupal の `saveData()` は競合時に `_0` 等のサフィックスを付与してファイル名を変えてしまう。これを避けるため、`prepareDirectory()`（ディレクトリ作成・権限調整）＋ ディレクトリの realpath ＋ `basename()` で解決した実パスに対し `file_put_contents()` で直接書き込む。結果として指定 URI に**確実に上書き**される。

> 帰結: 同一の Slack API 応答に対して `make export`（Drush）や queue worker を複数回流しても、各 JSON は byte 同一になる。migrate（ADR-0006）側は `field_slack_ts` 一意キーで冪等取込するため、再実行で件数が増えない。

---

## 3. `manifest.json`

エクスポート全体のメタ情報とチャンネルインデックス。**生成経路によりフィールドが異なる**（後述 §3.3）。

### 3.1 Drush 経路（`drush slack:export`）

`SlackExportCommands::export()` が書く形。`since_days` と `oldest_ts` を持ち、`counts.files` は実値（`files.list` パスでカウント）。

```json
{
  "schema_version": 1,
  "generated_at": "2026-06-05T01:23:45Z",
  "since_days": 90,
  "oldest_ts": "1742000000",
  "counts": {
    "channels": 12,
    "messages": 3456,
    "users": 78,
    "files": 90
  },
  "channels": [
    {
      "id": "C123456",
      "name": "general",
      "type": "public_channel",
      "file": "channels/C123456.json"
    }
  ]
}
```

### 3.2 Queue / HTTP 経路（バックグラウンドトリガ）

`SlackFetchQueueWorker::writeManifestAndFinish()` が、全チャンネル完了時に書く形。`since_days` / `oldest_ts` を**持たない**。`counts.channels` は成功したチャンネル数（`processed`）、`counts.failed` は失敗確定したチャンネル数、`counts.files` は実際に DL したインラインファイル数（State 由来）。

```json
{
  "schema_version": 1,
  "generated_at": "2026-06-05T01:23:45Z",
  "counts": {
    "channels": 12,
    "failed": 0,
    "messages": 3456,
    "users": 78,
    "files": 9
  },
  "channels": [
    {
      "id": "C123456",
      "name": "general",
      "type": "public_channel",
      "file": "channels/C123456.json"
    }
  ]
}
```

### 3.3 フィールド定義と経路差分

| フィールド | 型 | 説明 | Drush 経路 | Queue/HTTP 経路 |
|---|---|---|---|---|
| `schema_version` | int | スキーマ版（現在 `1` 固定） | あり | あり |
| `generated_at` | string | manifest 生成時刻。ISO-8601 UTC（`gmdate('Y-m-d\TH:i:s\Z', …)`） | あり | あり |
| `since_days` | int | `--since` で解析された取得日数（例 `90`） | **あり** | **なし** |
| `oldest_ts` | string | 取得下限の Unix 秒（文字列）。`time() - since_days*86400` | **あり** | **なし** |
| `counts.channels` | int | チャンネル数。Drush=出力数、Queue=成功数(`processed`) | あり | あり |
| `counts.failed` | int | 失敗確定したチャンネル数 | **なし** | **あり** |
| `counts.messages` | int | トップレベル（fold 後）メッセージ総数 | あり | あり |
| `counts.users` | int | `users.json` のユーザ数 | あり | あり |
| `counts.files` | int | 添付ファイル数 | workspace 総数（`files.list`） | DL したインライン数（State 累計） |
| `channels[]` | array | チャンネルインデックス（下記） | あり | あり |
| `channels[].id` | string | チャンネル ID | あり | あり |
| `channels[].name` | string | チャンネル名 | あり | あり |
| `channels[].type` | string | チャンネル種別（§5 の `type` と同じ値域） | あり | あり |
| `channels[].file` | string | チャンネル JSON への相対パス `channels/<id>.json` | あり | あり |

> 正直な注記（実装の事実）: `counts.files` の**数え方は経路で異なる**。Drush 経路は `files.list` で得た workspace 全体のファイル総数。Queue/HTTP 経路は `ChannelExporter` がメッセージのインライン `files[]` から**実際に DL した数**の累計（`files.list` は実行しない）。外部/remote file（非 Slack ホスト）はスキップされ DL 数に加算されない。`counts.failed > 0` のとき export 全体の terminal 状態は `error` になる（`docs/spec/portal-api.md` §6）。

---

## 4. `users.json`

トップレベル JSON 配列。各要素は `SlackWorkspaceMapper::toCanonicalUser()` が生成するユーザオブジェクト。

```json
[
  {
    "id": "U12345678",
    "name": "taro",
    "real_name": "山田 太郎",
    "display_name": "taro.y",
    "email": "taro@example.com",
    "is_bot": false,
    "deleted": false,
    "avatar": "https://avatars.slack-edge.com/.../192.png"
  }
]
```

| フィールド | 型 | 由来（raw Slack） | 備考 |
|---|---|---|---|
| `id` | string \| null | `members[].id` | 欠落時 `null` |
| `name` | string \| null | `members[].name` | 欠落時 `null` |
| `real_name` | string \| null | `members[].real_name` | 欠落時 `null` |
| `display_name` | string \| null | `members[].profile.display_name` | 欠落時 `null` |
| `email` | string \| null | `members[].profile.email` | 欠落時 `null`（PII） |
| `is_bot` | bool | `members[].is_bot` | 既定 `false` |
| `deleted` | bool | `members[].deleted` | 既定 `false` |
| `avatar` | string \| null | `members[].profile.image_192` | 192px アバター URL。欠落時 `null` |

---

## 5. `channels/<channel_id>.json`

`CanonicalJsonWriter::writeChannel()` が生成する、チャンネル 1 件ぶんのファイル。トップレベルは `schema_version` / `channel` / `messages` の 3 キー。

```json
{
  "schema_version": 1,
  "channel": {
    "id": "C123456",
    "name": "general",
    "type": "public_channel",
    "is_private": false,
    "is_im": false,
    "is_mpim": false,
    "members": [],
    "topic": "雑談用",
    "purpose": "全社の連絡"
  },
  "messages": [
    // §6 のメッセージオブジェクトの配列
  ]
}
```

### 5.1 トップレベル

| フィールド | 型 | 説明 |
|---|---|---|
| `schema_version` | int | スキーマ版（現在 `1` 固定） |
| `channel` | object | チャンネルメタ（§5.2） |
| `messages` | array | fold 済みメッセージの配列（§6） |

### 5.2 `channel` オブジェクト（`SlackWorkspaceMapper::toChannelMeta()`）

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | string | チャンネル ID。欠落時は空文字 |
| `name` | string | チャンネル名。IM の場合は `name` → `user` → `id` の順でフォールバック |
| `type` | string | `public_channel` \| `private_channel` \| `im` \| `mpim`（下記の導出規則） |
| `is_private` | bool | raw `is_private`（既定 `false`） |
| `is_im` | bool | raw `is_im`（既定 `false`） |
| `is_mpim` | bool | raw `is_mpim`（既定 `false`） |
| `members` | array | **常に空配列 `[]`**（現実装ではメンバー一覧を投入しない） |
| `topic` | string | raw `topic.value`。欠落時は空文字 |
| `purpose` | string | raw `purpose.value`。欠落時は空文字 |

**`type` 導出規則**（優先順位: im > mpim > private > public）:

- `is_im` が真 → `im`
- そうでなく `is_mpim` が真 → `mpim`
- そうでなく `is_private` が真 → `private_channel`
- いずれでもない → `public_channel`

> 正直な注記: `members` は `toChannelMeta()` で常に `[]` にセットされ、現実装ではどの経路でも投入されない。`is_private` / `is_im` / `is_mpim` のブール値と `type` から種別判定に用いること。

---

## 6. メッセージオブジェクト

`CanonicalMessageFormatter::format()` が生成する純粋な値オブジェクト（I/O・ネットワーク・DB なし）。`channels/<id>.json` の `messages[]` の各要素、および §6.5 のとおりスレッド親の `replies[]` の各要素もこの形を取る。

```json
{
  "slack_ts": "1700000000.000100",
  "channel_id": "C123456",
  "user_id": "U12345678",
  "bot_id": null,
  "username": null,
  "type": "message",
  "subtype": null,
  "text": "こんにちは",
  "posted_at": "2023-11-14T22:13:20Z",
  "edited": false,
  "thread_ts": "1700000000.000100",
  "reply_count": 2,
  "reactions": [
    { "name": "thumbsup", "count": 3, "users": ["U1", "U2", "U3"] }
  ],
  "files": [
    {
      "id": "F0123",
      "name": "diagram.png",
      "mimetype": "image/png",
      "url_private": "REDACTED",
      "local_path": "files/F0123.png"
    }
  ],
  "replies": [
    // スレッド親の場合のみ存在。各要素はメッセージオブジェクト（ただし自身の replies は持たない）
  ]
}
```

### 6.1 フィールド定義

| フィールド | 型 | 由来 / 説明 |
|---|---|---|
| `slack_ts` | string | raw `ts`（`"秒.マイクロ秒"`）。欠落時は空文字。チャンネル内で一意 |
| `channel_id` | string | 所属チャンネル ID（`format()` の引数） |
| `user_id` | string \| null | raw `user`。無ければ `null` |
| `bot_id` | string \| null | raw `bot_id`。無ければ `null` |
| `username` | string \| null | raw `username`。無ければ `null` |
| `type` | string | raw `type`。欠落時は `message` |
| `subtype` | string \| null | raw `subtype`。無ければ `null` |
| `text` | string | raw `text`。欠落時は空文字 |
| `posted_at` | string | ISO-8601 UTC。`slack_ts` の**整数秒部分**から `gmdate('Y-m-d\TH:i:s\Z', (int) $ts)` で導出（マイクロ秒は人間可読時刻には含めない） |
| `edited` | bool | raw `edited` が非空なら `true`、なければ `false` |
| `thread_ts` | string \| null | raw `thread_ts`。無ければ `null` |
| `reply_count` | int | raw `reply_count`。欠落時 `0` |
| `reactions` | array | リアクションの配列（§6.2）。無ければ `[]` |
| `files` | array | 添付ファイル参照の配列（§6.3）。無ければ `[]` |
| `replies` | array | スレッド親（thread-root）にのみ存在（§6.5）。標準メッセージや単独メッセージには**このキー自体が無い** |

### 6.2 `reactions[]`（`formatReactions()`）

raw に `reactions` が無い場合は `[]`。

| フィールド | 型 | 由来 |
|---|---|---|
| `name` | string | raw `reactions[].name`。欠落時は空文字 |
| `count` | int | raw `reactions[].count`。欠落時 `0` |
| `users` | array | raw `reactions[].users`（ユーザ ID 配列）。欠落時 `[]` |

### 6.3 `files[]`（`formatFiles()` ＋ `ChannelExporter`）

raw に `files` が無い場合は `[]`。整形直後（`CanonicalMessageFormatter`）は `url_private` に元 URL、`local_path` は `null`。その後 `ChannelExporter::downloadFiles()` がファイルをダウンロードして以下を**上書き**する。

| フィールド | 型 | 説明 |
|---|---|---|
| `id` | string | raw `files[].id`。欠落時は空文字 |
| `name` | string | raw `files[].name`。欠落時は空文字 |
| `mimetype` | string | raw `files[].mimetype`。欠落時は空文字 |
| `url_private` | string | 整形直後は raw `files[].url_private`。**ダウンロード後は `REDACTED`**（token 付き URL をアーカイブに残さないため） |
| `local_path` | string \| null | 整形直後は `null`。**ダウンロード後は相対パス `files/<id>.<ext>`**（`<ext>` は `name` から導出した小文字拡張子、無ければ `bin`） |

> アーカイブとして永続化される最終形では、添付があるメッセージの `url_private` は `REDACTED`、`local_path` は `files/<id>.<ext>` を指す。`local_path` が `null` のままになるのは、ダウンロードパスを経由しないケース（整形のみの中間状態）に限られる。

### 6.4 `posted_at` の導出例

`slack_ts = "1700000000.000100"` → 整数秒 `1700000000` → `posted_at = "2023-11-14T22:13:20Z"`。マイクロ秒部分（`.000100`）は `slack_ts` の一意性のために保持されるが、`posted_at` には反映されない。

### 6.5 スレッドの fold（`ThreadFolder::fold()`）

`messages[]` は `ThreadFolder` によって折りたたまれている。分類は次のとおり。

- スレッド親（thread-root）: `thread_ts === slack_ts`
- 返信（reply）: `thread_ts !== null` かつ `thread_ts !== slack_ts`
- 単独（standalone）: `thread_ts === null`

fold 後の `messages[]` の挙動:

- **親と単独メッセージはトップレベルに残る**（入力の相対順序を維持）。
- **返信はトップレベルから除かれ、親の `replies[]` に格納**される。`replies[]` は `slack_ts` 昇順（文字列比較 `strcmp`）でソートされる。
- **`replies` キーが付くのはスレッド親のみ**。単独メッセージは一切変更されず `replies` キーを持たない。
- 各 `replies[]` の要素はメッセージオブジェクト（§6）だが、**自身の `replies` キーは持たない**（ネストは 1 段階）。
- **孤児返信（orphan）**: 親が入力に存在しない返信はトップレベルの**末尾**に出力され、データが黙って失われない。
- 入力メッセージは破壊的に変更されない（親のコピーに `replies` を付与）。

---

## 7. M2 migrate（ADR-0006）からの参照ポイント

migrate_plus（JSON source）で取り込む際の主要キー対応の目安（詳細は ADR-0006 を正典とする）:

- 一意キー（冪等）: メッセージは `slack_ts`、チャンネルは `channel.id`、ユーザは `users[].id`。
- チャンネル参照: メッセージの `channel_id` → `slack_channels`（taxonomy）。
- ユーザ参照: メッセージの `user_id` → user 参照。
- 投稿時刻: `posted_at`（ISO-8601 UTC）。
- スレッド親子: `thread_ts`（親 = `thread_ts === slack_ts`、`replies[]` がネストされた返信）。
- リアクション: `reactions[]`、添付: `files[]`（`local_path` から `files/<id>.<ext>` を解決）。

依存取込順は channels → users → messages → files（ADR-0006）。

---

## 8. 関連ドキュメント

- 生成パイプラインの詳細（how）: [docs/spec/ingest-pipeline.md](./ingest-pipeline.md)
- 取込先 Drupal データモデル（what）: [docs/spec/data-model.md](./data-model.md)
- migrate 取込手順（how）: [docs/spec/migrate.md](./migrate.md)
- 保存形式の決定: [ADR-0004 — 保存形式＝canonical JSON ＋ MariaDB](../adr/0004-storage-canonical-json-and-mariadb.md)
- Drupal データモデルと migrate: [ADR-0006 — Slack→Drupal データモデルと Migrate 取込](../adr/0006-slack-to-drupal-data-model-and-migrate.md)
- 公開範囲（status/privacy・email 非取込）: [ADR-0013 — 匿名閲覧可否とチャンネルプライバシー](../adr/0013-anonymous-readability-and-channel-privacy.md)
- secrets / PII 取扱: `.claude/rules/secrets-and-pii.md`
