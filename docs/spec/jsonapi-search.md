---
title: JSON:API ／ Search API エンドポイント仕様
status: confirmed
audience: 開発者（M3 JSON:API/検索実装者・フロント実装者）
diataxis: reference
related:
  - docs/spec/data-model.md
  - docs/spec/migrate.md
  - docs/adr/0005-headless-drupal-jsonapi-search.md
  - docs/adr/0008-internal-portal-auth-and-cors.md
  - docs/adr/0013-anonymous-readability-and-channel-privacy.md
  - docs/adr/0014-canonical-archive-private-stream.md
---

# JSON:API ／ Search API エンドポイント仕様（確定仕様）

本書は `slack_portal` モジュールが公開する **JSON:API read-only エンドポイント**と **Search API 全文検索エンドポイント**の確定仕様（what）である。決定経緯は [ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md)（JSON:API＋Search API DB）および [ADR-0008](../adr/0008-internal-portal-auth-and-cors.md)（auth/CORS）。公開範囲・プライバシーの方針は [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)、添付アーカイブの private:// 移行は [ADR-0014](../adr/0014-canonical-archive-private-stream.md)。

- データモデル（what）: [docs/spec/data-model.md](./data-model.md)
- migrate 取込（how）: [docs/spec/migrate.md](./migrate.md)
- canonical JSON スキーマ: [docs/spec/canonical-json.md](./canonical-json.md)

---

## 1. 概要

| 層 | 内容 |
|---|---|
| **JSON:API read-only** | Drupal コア JSON:API を `jsonapi.settings.read_only = TRUE` で書込禁止化。`jsonapi_extras` でリソースホワイトリスト化 |
| **Search API DB** | `search_api_db` backend、サーバ `slack_db`、インデックス `slack_messages`。published のみ索引 |
| **jsonapi_search_api** | インデックスを JSON:API 形式で公開（`/jsonapi/index/slack_messages`）。facets 対応 |
| **プライバシー** | published node（＝public_channel）のみ匿名 read 可。private//archive は Web 非配信（[ADR-0014](../adr/0014-canonical-archive-private-stream.md)） |
| **CORS** | frontend origin `http://localhost:3000` のみ許可 |

---

## 2. エンドポイント一覧

| エンドポイント | メソッド | 説明 |
|---|---|---|
| `/jsonapi/node/slack_message` | GET | published な `slack_message` node のコレクション（published-only） |
| `/jsonapi/node/slack_message/{uuid}` | GET | 個別 published node |
| `/jsonapi/index/slack_messages` | GET | 全文検索＋facet（`jsonapi_search_api`） |
| `/jsonapi/taxonomy_term/slack_channels` | GET | チャンネル一覧（public のみ。§6 参照） |
| `/jsonapi/taxonomy_term/slack_users` | GET | ユーザ一覧 |
| `/jsonapi/file/file` | GET | managed file（添付 include 用） |
| POST / PATCH / DELETE（全リソース） | POST / PATCH / DELETE | **405 Method Not Allowed**（read-only 設定による） |

> `hook_install()` で `jsonapi.settings` の `read_only` を `TRUE` に設定。これにより書込メソッドはすべて 405 を返す。

---

## 3. クエリパラメータ（`/jsonapi/index/slack_messages`）

### 3.1 全文検索

| パラメータ | 説明 | 例 |
|---|---|---|
| `filter[fulltext]` | 全文検索クエリ。インデックスの fulltext フィールド（title・body・channel\_name・slack\_user\_name）を対象 | `?filter[fulltext]=hello` |

### 3.2 facet フィルタ

| パラメータ | 索引フィールド | 例 |
|---|---|---|
| `filter[channel]` | `channel_name`（`field_channel:entity:name`） | `?filter[channel]=general` |
| `filter[slack_user]` | `slack_user_name`（`field_slack_user:entity:name`） | `?filter[slack_user]=taro` |
| `filter[posted_at]` | `posted_at`（date） | `?filter[posted_at][value]=2026-01-01&filter[posted_at][operator]=>=` |

### 3.3 ページング・並び替え

| パラメータ | 説明 | 既定 |
|---|---|---|
| `page[limit]` | 1 ページあたりの件数 | サーバ既定（通常 10〜50） |
| `page[offset]` | オフセット（0 始まり） | `0` |
| `sort` | ソートフィールド（先頭 `-` で降順）。例 `sort=-posted_at` | 関連度スコア順 |

### 3.4 include（通常 JSON:API コレクション）

```
GET /jsonapi/node/slack_message?include=field_channel,field_slack_user,field_attachments
```

- `field_channel` → `taxonomy_term--slack_channels`
- `field_slack_user` → `taxonomy_term--slack_users`
- `field_attachments` → `file--file`

---

## 4. facets（`meta.facets`）

`jsonapi_search_api_facets` モジュールが `/jsonapi/index/slack_messages` レスポンスの `meta.facets` に facet 情報を付与する。

### 4.1 設定済み facets

| facet id | ラベル | 対象索引フィールド | `empty_behavior` |
|---|---|---|---|
| `channel` | Channel | `channel_name` | none（0 件時省略） |
| `slack_user` | Slack User | `slack_user_name` | none（0 件時省略） |
| `posted_at` | Posted At | `posted_at` | none（0 件時省略） |

### 4.2 `meta.facets` の形状

```json
{
  "data": [ ... ],
  "meta": {
    "facets": [
      {
        "id": "channel",
        "label": "Channel",
        "path": "filter[channel]",
        "terms": [
          {
            "url": "/jsonapi/index/slack_messages?filter[fulltext]=hello&filter[channel]=general",
            "values": {
              "value": "general",
              "count": 42,
              "active": false
            }
          },
          {
            "url": "/jsonapi/index/slack_messages?filter[fulltext]=hello&filter[channel]=random",
            "values": {
              "value": "random",
              "count": 7,
              "active": false
            }
          }
        ]
      },
      {
        "id": "slack_user",
        "label": "Slack User",
        "path": "filter[slack_user]",
        "terms": [ ... ]
      },
      {
        "id": "posted_at",
        "label": "Posted At",
        "path": "filter[posted_at]",
        "terms": [ ... ]
      }
    ]
  },
  "links": { ... }
}
```

- `empty_behavior: none` のため、ヒット件数 0 の facet は `meta.facets` から**省略される**（配列要素ごと消える）。
- `active: true` になるのは、現在のリクエストで当該 facet 値が filter として適用されている場合。

---

## 5. 公開リソース（jsonapi_extras ホワイトリスト）

`hook_install()` で `jsonapi_extras.settings` の `default_disabled` を `TRUE` にセットし、明示的に有効化したリソースのみ公開する。（`jsonapi.settings` には `read_only` のみ設定する。）

| リソース | 用途 |
|---|---|
| `node--slack_message` | メッセージ本文（published のみ） |
| `taxonomy_term--slack_channels` | チャンネル（public チャンネルのみ。§6 参照） |
| `taxonomy_term--slack_users` | ユーザ |
| `file--file` | 添付ファイル（`field_attachments` include 用） |

上記以外のリソース（例 `user--user`、`node_type--node_type` 等）は **404** を返す。

---

## 6. プライバシー

### 6.1 published-only 索引（Search API）

Search API インデックス `slack_messages` は `entity_status` プロセッサと `content_access` プロセッサを有効化し、**published 状態（`status = 1`）の node のみ**を索引する。unpublished（private_channel / im / mpim）はインデックスに入らないため、全文検索で本文が露出しない（[ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)）。

| node status | 匿名 JSON:API アクセス | 全文検索インデックス |
|---|---|---|
| published（public_channel） | 200 | 索引あり |
| unpublished（private/im/mpim） | 403 / 結果なし | 索引なし |

### 6.2 taxonomy_term アクセス制御（private チャンネル名の隠蔽）

`hook_taxonomy_term_access()` により、**管理者以外（匿名を含む）** は `field_channel_type != public_channel` の `slack_channels` term を閲覧できない（`AccessResult::forbidden()`）。

- `/jsonapi/taxonomy_term/slack_channels` コレクションで private / im / mpim チャンネル名が**列挙されない**。
- `include=field_channel` で private チャンネルを参照している node の include でも term が**返らない**。

`slack_users` term はアクセス制限なし（real_name / display_name は public 投稿者識別用として許容。ADR-0013）。

### 6.3 ファイルアクセス制御（`hook_file_download`）

`slack_archive/` 配下のファイルに対するダウンロードリクエストは `hook_file_download()` によって制御される。

- リクエストしたファイルを参照する `slack_message` node のうち、リクエスト者が**閲覧可能**（匿名 → published/public_channel のみ）なものが 1 件以上存在する場合 → **200**（ダウンロード許可）。
- 存在しない（すべて unpublished または参照なし）場合 → **−1（403 Forbidden）**。

### 6.4 canonical アーカイブの private:// 移行

canonical アーカイブは `private://slack_archive/latest/` に保存される（M3 で `public://` から移行）。`private://` は Drupal のプライベートファイルシステム（web root 外のディレクトリ）に解決され、nginx / Apache が直接配信できない。添付ファイルはすべて §6.3 の `hook_file_download` 経由でのみ配信される。詳細は [ADR-0014](../adr/0014-canonical-archive-private-stream.md) と [docs/how-to/private-files-setup.md](../how-to/private-files-setup.md) を参照。

---

## 7. CORS

フロントエンドから JSON:API エンドポイントに直接リクエストするため、Drupal の CORS 設定が必要。

### 7.1 必要な `services.yml` 設定

`web/sites/default/services.yml`（gitignore 対象；ローカル / DDEV 固有）に以下を追加する。

```yaml
parameters:
  cors.config:
    enabled: true
    allowedHeaders:
      - 'Content-Type'
      - 'Accept'
    allowedMethods:
      - 'GET'
      - 'OPTIONS'
    allowedOrigins:
      - 'http://localhost:3000'
    exposedHeaders: false
    maxAge: false
    supportsCredentials: false
```

- read-only ポータルでは `GET` と `OPTIONS`（preflight）のみが必要。
- `allowedOrigins` は frontend の開発サーバ origin に合わせる。本番 / staging では値を変更する。
- `services.yml` は gitignore 済み（PII・環境固有設定を含むため）。

---

## 8. Search API インデックス詳細

### 8.1 サーバ・インデックス設定

| 項目 | 値 |
|---|---|
| サーバ id | `slack_db` |
| backend | `search_api_db`（Drupal DB、MariaDB） |
| インデックス id | `slack_messages` |
| datasource | `entity:node`、bundle `slack_message` |
| published-only | `entity_status` プロセッサ ＋ `content_access` プロセッサ |
| `index_directly` | `true`（migrate 取込と同時に即時索引） |

### 8.2 索引フィールド

| 索引フィールド名 | Drupal フィールド | 型 | fulltext |
|---|---|---|---|
| `title` | `title` | text | ✓ |
| `body` | `field_body` | text | ✓ |
| `channel_name` | `field_channel:entity:name` | string / text | ✓ |
| `slack_user_name` | `field_slack_user:entity:name` | string / text | ✓ |
| `posted_at` | `field_posted_at` | date | — |
| `reaction_total` | `field_reaction_total` | integer | — |

### 8.3 ルート名

`jsonapi_search_api` モジュールがインデックス id から自動生成するルート名: `jsonapi_search_api.index_slack_messages`（パス `/jsonapi/index/slack_messages`）。

---

## 9. 既知の制約

### 9.1 CJK（日本語）全文検索の制限

`search_api_db` backend の全文検索トークナイザーは **bigram**（`min_chars: 3`、`overlap_cjk: 1`）を使用する。日本語などの CJK 文字は単語境界がなく、bigram 分割では完全一致しない検索クエリがある（例: 2 文字の日本語単語は `min_chars: 3` で索引されない）。

将来的な対処として Solr / Elasticsearch / Typesense への移行が ADR-0005 で言及されている。その際は superseding ADR を起こすこと。

### 9.2 Solr 等への将来移行

DB backend は無料 90 日分の小規模データ向け。大規模化・日本語精度向上が必要な場合は Search API + Solr backend または外部検索エンジンへの移行が必要（[ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md) 参照）。

---

## 10. 関連ドキュメント

- データモデル: [docs/spec/data-model.md](./data-model.md)
- migrate 取込: [docs/spec/migrate.md](./migrate.md)
- canonical JSON スキーマ: [docs/spec/canonical-json.md](./canonical-json.md)
- private:// ファイル設定: [docs/how-to/private-files-setup.md](../how-to/private-files-setup.md)
- 決定: [ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md)（JSON:API＋Search API DB）
- 決定: [ADR-0008](../adr/0008-internal-portal-auth-and-cors.md)（auth/CORS）
- 決定: [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)（public/private gating）
- 決定: [ADR-0014](../adr/0014-canonical-archive-private-stream.md)（private:// アーカイブ）
