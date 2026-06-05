# Slack Portal トリガー / ステータス HTTP API リファレンス

> Diátaxis: **reference**（仕様）。本書は `slack_portal` モジュールが公開する **書き込み系トリガー API** と **ステータス取得 API** の確定済み契約を記述する。
> 記載内容は実装ソース（`slack_portal.routing.yml` / `SlackPortalApiController` / `ExportTrigger` / `ExportStateService`）に基づく。`docs/spec/credentials.md`・`docs/spec/ingest-pipeline.md`・ADR-0012・ADR-0008 と相互参照する。

## 1. 概要

このモジュールは、ポータル（Next.js フロント）から **Slack ワークスペースの直近約 90 日のエクスポート**を起動し、その**バックグラウンド進捗**を取得するための 2 つの HTTP JSON エンドポイントを公開する。

- **POST `/api/slack-portal/export`** — エクスポートを起動し、チャンネルをキューに投入する（書き込み）。
- **GET `/api/slack-portal/status`** — 現在のエクスポート状態を返す（読み取り）。

> **JSON:API は read-only のまま**（ADR-0005 / ADR-0008）。閲覧・全文検索は JSON:API + Search API 経由で行い、本書が扱う**書き込み系トリガー API は JSON:API とは別系統の独自ルート**である。両者を混同しないこと。

## 2. エンドポイント一覧

| メソッド | パス | コントローラ | 権限 | CSRF | 用途 |
|----------|------|--------------|------|------|------|
| `POST` | `/api/slack-portal/export` | `SlackPortalApiController::triggerExport` | `administer slack_portal` | 必須（`_csrf_request_header_token: 'TRUE'`） | エクスポートを起動しチャンネルをキュー投入 |
| `GET` | `/api/slack-portal/status` | `SlackPortalApiController::status` | `administer slack_portal` | 不要 | 現在のエクスポート状態を返す |

ルート定義の正典は `web/modules/custom/slack_portal/slack_portal.routing.yml`。

```yaml
slack_portal.api.export:
  path: '/api/slack-portal/export'
  defaults:
    _controller: '\Drupal\slack_portal\Controller\SlackPortalApiController::triggerExport'
  methods: [POST]
  requirements:
    _permission: 'administer slack_portal'
    _csrf_request_header_token: 'TRUE'

slack_portal.api.status:
  path: '/api/slack-portal/status'
  defaults:
    _controller: '\Drupal\slack_portal\Controller\SlackPortalApiController::status'
  methods: [GET]
  requirements:
    _permission: 'administer slack_portal'
```

## 3. 認証・認可

### 3.1 権限

両エンドポイントとも Drupal 権限 **`administer slack_portal`** を要求する（`_permission`）。この権限は `slack_portal.permissions.yml` で定義され、`restrict access: true`（高リスク権限）でマークされている。

権限を持たないユーザのアクセスは Drupal のアクセスチェックにより **403 Forbidden** となる（コントローラには到達しない）。

### 3.2 CSRF（POST のみ）

`POST /api/slack-portal/export` は `_csrf_request_header_token: 'TRUE'` を要求する。クライアントは以下のヘッダを付与しなければならない。

```
X-CSRF-Token: <token>
```

トークンは認証済みセッションで以下のエンドポイントから取得する。

```
GET {DRUPAL}/session/token
```

レスポンス本文（プレーンテキスト）がそのまま `X-CSRF-Token` 値となる。トークンが欠落・不正な場合、リクエストは **403 Forbidden** となる（コントローラには到達しない）。

`GET /api/slack-portal/status` は CSRF を要求しない。

### 3.3 セッション（ローカル認証）

ローカル運用の認証は **Drupal のセッション Cookie** で行う。フロントからの fetch は **資格情報込み**（`credentials: 'include'`）で送信し、`administer slack_portal` を持つユーザのセッションを使用すること。

### 3.4 CORS

CORS 設定は **`web/sites/*/services.yml`**（gitignore 済み、デプロイ環境で配置する設定）で行う。リポジトリには配布されないため、デプロイ時に以下を満たすよう設定する。

- `allowedOrigins`: **フロントの origin のみ**（ワイルドカード `*` 不可。資格情報込みリクエストでは origin を明示する必要がある）。
- `supportsCredentials: true`（Cookie / `X-CSRF-Token` を伴う cross-origin リクエストを許可する）。
- `allowedMethods` / `allowedHeaders`: 少なくとも本 API で使う `GET` / `POST` と `X-CSRF-Token` を許可する。

> リポジトリ同梱の `web/sites/default/default.services.yml` は雛形であり、`cors.config.enabled: false` / `allowedOrigins: ['*']` / `supportsCredentials: false` のデフォルトのままである。本番では実環境の `services.yml` で上書きすること。詳細は ADR-0008 を参照。

## 4. POST /api/slack-portal/export

### 4.1 リクエスト

- **本文**: 不要（コントローラ `triggerExport()` はリクエストボディを参照しない）。エクスポート対象期間（`since` 日数）やトークンは**サーバ側の設定から解決**される（`SlackTokenProvider`、`docs/spec/credentials.md` 参照）。
- **必須ヘッダ**:
  - `X-CSRF-Token: <token>`（§3.2）
  - セッション Cookie（`credentials: 'include'`）

```
POST /api/slack-portal/export
X-CSRF-Token: <token>
Cookie: <Drupal session>
```

### 4.2 処理内容

`ExportTrigger::trigger()` を呼び、内部で以下を順に実行する（`docs/spec/ingest-pipeline.md` 参照）。

1. トークン解決とクライアント構築。
2. ユーザ取得 → canonical JSON（`users.json`）書き込み。
3. チャンネル取得と oldest（取得開始 Unix 時刻）算出。
4. `ExportStateService::start()` で状態を **`running`** に遷移。
5. チャンネルごとに `slack_portal_fetch` キューへ 1 件ずつ投入（バックグラウンドワーカーが処理）。
6. サマリ配列を返却。

`ExportTrigger::trigger()` は `['queued' => <チャンネル数>, 'users' => <ユーザ数>]` を返す。コントローラはこれに `status: 'queued'` を**前置**してレスポンス化する。

### 4.3 成功レスポンス（200 OK）

```json
{
  "status": "queued",
  "queued": 12,
  "users": 34
}
```

| フィールド | 型 | 意味 |
|------------|----|----|
| `status` | string | 常に `"queued"`（POST の一回限りの応答。後述の永続状態とは別物） |
| `queued` | int | キューに投入したチャンネル数 |
| `users` | int | このエクスポートで取得・書き出したユーザ数 |

> **重要**: `status: "queued"` は **この POST 応答にだけ現れる一回限りの ack** であり、永続化される状態ではない。`GET /status` がこの値を返すことはない（§6）。

### 4.4 エラーレスポンス（500 Internal Server Error）

`ExportTrigger::trigger()` が例外を投げた場合（例: トークン未設定、Slack API エラー）、コントローラが捕捉して 500 を返す。

```json
{
  "status": "error",
  "message": "Slack user token is not configured."
}
```

| フィールド | 型 | 意味 |
|------------|----|----|
| `status` | string | 常に `"error"` |
| `message` | string | **サニタイズ済み**のエラーメッセージ |

**トークン秘匿（必読）**: `message` は正規表現 `/xox[a-z]-[^\s"']+/i` により Slack トークン様文字列（`xoxp-…` / `xoxb-…`）を `[REDACTED]` へ置換した上で返す。レスポンス本文・ログのいずれにも生トークンは出力されない（`SlackPortalApiControllerTest::testTriggerExportReturns500OnExceptionWithoutTokenLeak` で検証）。

### 4.5 403 となる条件

- `administer slack_portal` 権限を持たない（§3.1）。
- `X-CSRF-Token` が欠落または不正（§3.2）。

これらは Drupal のルートアクセスチェックで弾かれ、コントローラ本体は実行されない。

## 5. GET /api/slack-portal/status

### 5.1 リクエスト

- **本文**: なし。
- **ヘッダ**: セッション Cookie（`credentials: 'include'`）。CSRF は不要。

### 5.2 レスポンス（200 OK）

`ExportStateService::getStatus()` が返す**完全な状態配列**をそのまま JSON 化して返す。状態が未保存の場合は idle 形状を返す。

実行中（`running`）の例:

```json
{
  "status": "running",
  "total": 12,
  "processed": 5,
  "failed": 0,
  "messages": 842,
  "users": 34,
  "files": 9,
  "channels": [
    {
      "id": "C0123ABCD",
      "name": "general",
      "type": "public_channel",
      "file": "channels/C0123ABCD.json"
    }
  ],
  "failed_channels": [],
  "started_at": 1717545600,
  "finished_at": null,
  "last_error": null
}
```

idle 形状（一度もエクスポートが起動されていない、または `reset()` 後）:

```json
{
  "status": "idle",
  "total": 0,
  "processed": 0,
  "failed": 0,
  "messages": 0,
  "users": 0,
  "files": 0,
  "channels": [],
  "failed_channels": [],
  "started_at": null,
  "finished_at": null,
  "last_error": null
}
```

### 5.3 フィールド定義

| フィールド | 型 | 意味 |
|------------|----|----|
| `status` | string | 状態。`idle` / `running` / `done` / `error` のいずれか（§6） |
| `total` | int | キュー投入したチャンネル数（`start()` 時に確定） |
| `processed` | int | 成功したチャンネル数（`recordChannel()` で加算、channel id で冪等） |
| `failed` | int | リトライ上限後に失敗確定したチャンネル数（`recordFailure()`） |
| `messages` | int | 取得したトップレベルメッセージ数の累計 |
| `users` | int | 取得したユーザ数（初回パスで確定） |
| `files` | int | DL したインラインファイル数の累計 |
| `channels` | array | 成功チャンネルのインデックスエントリ配列。各要素は `{id, name, type, file}` |
| `failed_channels` | array | 失敗確定したチャンネル id の配列 |
| `started_at` | int \| null | エクスポート開始 Unix 時刻（`running` 遷移時にセット） |
| `finished_at` | int \| null | 完了 Unix 時刻（terminal 遷移 `done`/`error` でセット） |
| `last_error` | string \| null | **事前マスク済み**のエラー文字列（失敗があるとき。`done` ではクリア） |

> `last_error` のマスクは**呼び出し側の責務**である。`ExportStateService::recordFailure()` / `fail()` は受け取った文字列をそのまま保存し、自動マスクは行わない（worker は channel id のみの定型文を渡す）。

## 6. 状態モデルとライフサイクル

エクスポート状態は Drupal State API（キー `slack_portal.export_status`）に**単一の配列**として永続化される。`status` フィールドの取りうる値と遷移は以下のとおり。

```
idle ──(POST /export → start())──▶ running ──(processed+failed >= total → finish())──┬─▶ done   (failed == 0)
                                                                                     └─▶ error  (failed > 0)
```

- **`idle`**: 初期状態（未保存時のデフォルト）。`reset()` でもこの形状に戻る。
- **`running`**: `ExportTrigger::trigger()` 内の `start()` で遷移。`started_at` がセットされる。**チャンネル単位の一時失敗は running を維持**し、QueueWorker が attempt を増やして再 enqueue（最大 `MAX_ATTEMPTS=3`）でリトライする（`docs/spec/ingest-pipeline.md` §3.3）。
- **`done`**: 全チャンネルが成功または失敗確定し（`processed + failed >= total`）、失敗が **0** のとき `finish()` が遷移。`finished_at` をセットし `last_error` をクリア。
- **`error`**: 同じ完了条件で**失敗が 1 件以上**のとき `finish()` が遷移。`last_error` に事前マスク済み文字列が残る。terminal なので running へは戻らない。
- 0 チャンネル（空 workspace）の場合は `ExportTrigger` が即 `finish()` し `done` になる（running に固着しない）。

> **`queued` という永続状態は存在しない。** `queued` は POST 応答（§4.3）の一回限りの ack 値であり、`GET /status` の `status` には決して現れない。

### ポーリングクライアントが観測する流れ

1. `POST /export` が **`{"status":"queued", …}`** を返す（ack。永続状態は同時に `running` へ）。
2. 以降 `GET /status` をポーリングすると `status` は **`running`** を返す（`processed`(+`failed`) が `total` に向かって増加）。一時失敗のリトライ中も `running` のまま。
3. 最終的に **`done`**（全成功）または **`error`**（一部チャンネルが失敗確定）へ収束する。`running` 中だけポーリングすれば terminal を取りこぼさない。

## 7. 関連ドキュメント

- **ADR-0012** — ポータル主導の ingest トリガー（本 API の設計判断）。
- **ADR-0008** — 内部ポータルの認証と CORS（セッション Cookie・CSRF・origin 制限の方針）。
- **ADR-0005** — ヘッドレス Drupal / JSON:API + Search API（read-only 系の閲覧・検索）。
- `docs/spec/credentials.md` — トークン・接続情報の管理（`SlackTokenProvider`、`since` 日数）。
- `docs/spec/ingest-pipeline.md` — エクスポートパイプライン（fetch → canonical JSON → キュー → ワーカー → migrate）。
