# ingest パイプライン仕様（reference）

`slack_portal` モジュールの **ingest（取得）パイプライン**の確定仕様。無料 Slack ワークスペースの直近〜90 日分の会話・スレッド・添付ファイル・ユーザを Slack Web API で取得し、canonical 正規化 JSON を `public://slack_archive/latest/` に出力するまでの動作を、ソース実装に即して記述する。

> このドキュメントは「取得（fetch）→ 正規化 → 書き出し」の制御フローを対象とする。出力 JSON のスキーマ自体は別仕様で扱う。
>
> - 出力スキーマ: [docs/spec/canonical-json.md](./canonical-json.md)
> - HTTP トリガ／ステータス API: [docs/spec/portal-api.md](./portal-api.md)
> - トークン／資格情報の解決: [docs/spec/credentials.md](./credentials.md)
> - 取得方式の決定: [ADR-0003 Slack 取得を PHP/Drupal ネイティブで実装](../adr/0003-slack-acquisition-php-native.md)

---

## 1. 概要

ingest は **2 つの駆動経路**を持ち、両者は同一のサービス群（`SlackClientFactory` / `SlackFetcher` / `ChannelExporter` / `SlackFileDownloader` / `CanonicalJsonWriter`）を共有する。

| 経路 | エントリポイント | 実行モデル | タイムアウト |
|------|------------------|------------|--------------|
| **(A) Drush インライン** | `ddev drush slack:export` | 全チャンネルを 1 プロセスで逐次処理（キューを使わない） | Web タイムアウトなし（Drush 実行） |
| **(B) HTTP トリガ + バックグラウンド** | `POST /api/slack-portal/export` → Queue `slack_portal_fetch` → cron | チャンネルごとにキュー項目化し、cron worker がバックグラウンド処理 | キュー worker は cron あたり `time: 60` 秒で処理 |

両経路に共通する単一チャンネルの処理本体は `ChannelExporter::exportChannel()` であり、ここがパイプラインの中核となる（§4）。

実装ファイル:

- `web/modules/custom/slack_portal/src/Service/SlackClientFactory.php`
- `web/modules/custom/slack_portal/src/Service/CursorIterator.php`
- `web/modules/custom/slack_portal/src/Service/SlackFetcher.php`
- `web/modules/custom/slack_portal/src/Service/ChannelExporter.php`
- `web/modules/custom/slack_portal/src/Service/SlackFileDownloader.php`
- `web/modules/custom/slack_portal/src/Service/CanonicalJsonWriter.php`
- `web/modules/custom/slack_portal/src/Service/ExportTrigger.php`
- `web/modules/custom/slack_portal/src/Service/ExportStateService.php`
- `web/modules/custom/slack_portal/src/Plugin/QueueWorker/SlackFetchQueueWorker.php`
- `web/modules/custom/slack_portal/src/Drush/Commands/SlackExportCommands.php`

---

## 2. 経路 (A): Drush インライン (`slack:export`)

`SlackExportCommands::export()` が単一プロセスで全ワークスペースを逐次エクスポートする。キュー／バッチを使わないため、Drush の「Web タイムアウトなし」特性に依存して長時間実行を許容する。

### 2.1 オプション

```
ddev drush slack:export --since=90d
```

| オプション | 既定値 | 意味 |
|------------|--------|------|
| `--since` | `90d` | 取得対象の日数。`'90d'`・`'30'` のような書式を受け付ける（§6）。 |

### 2.2 実行順序

1. **`--since` を解析**して `$days` を得る。`$oldest = time() - $days * 86400`（Unix 秒）。
2. **クライアント構築**: `SlackTokenProvider::getToken()` でトークンを解決し、`SlackClientFactory::create($token)` で API クライアントを生成。
3. **ユーザ取得**: `SlackFetcher::fetchUsers()`（`users.list`、cursor pagination）→ `SlackWorkspaceMapper::toCanonicalUser()` で正規化 → `CanonicalJsonWriter::writeUsers()` で `users.json` を書き出し。
4. **チャンネル取得 → 逐次エクスポート**: `SlackFetcher::fetchChannels($client, 'public_channel,private_channel,mpim,im')`（`conversations.list`、cursor pagination）で各チャンネルメタを得て、チャンネルごとに `ChannelExporter::exportChannel($client, $token, $channelMeta, $oldest)` を呼ぶ（§4）。返り値の `messages` を合計し、チャンネル index（`id` / `name` / `type` / `file`）を蓄積する。
5. **ファイル件数の集計（files.list パス）**: `SlackFetcher::fetchFiles($client, $oldest)`（`files.list`、ページ番号ページング、§5）を反復して件数だけを数える。**この経路では `files.list` の結果はマニフェストの件数算出にのみ使われ**、ファイル本体のダウンロードやチャンネル添付との突き合わせには使われない（ファイル本体の DL はステップ 4 のチャンネル処理内でメッセージインライン添付に対して行われる）。
6. **マニフェスト書き出し**: `CanonicalJsonWriter::writeManifest()` で `manifest.json` を書く。

### 2.3 (A) のマニフェスト内容

`SlackExportCommands` が書き出す `manifest.json` のキー（実装より）:

```json
{
  "schema_version": 1,
  "generated_at": "<gmdate Y-m-d\\TH:i:s\\Z>",
  "since_days": 90,
  "oldest_ts": "<oldest Unix 秒の文字列>",
  "counts": {
    "channels": 0,
    "messages": 0,
    "users": 0,
    "files": 0
  },
  "channels": [
    { "id": "C123", "name": "general", "type": "public_channel", "file": "channels/C123.json" }
  ]
}
```

`counts.files` は **ステップ 5 の `files.list` パスで数えた総ファイル数**（チャンネル単位の内訳ではない）。

---

## 3. 経路 (B): HTTP トリガ + バックグラウンド Queue/cron

ポータルからのトリガで `ExportTrigger::trigger()` が起動し、チャンネルごとに **Queue `slack_portal_fetch`** へ項目を投入する。実際のチャンネル処理は cron が回す `SlackFetchQueueWorker` がバックグラウンドで行う。

### 3.1 トリガ API

ルーティング（`slack_portal.routing.yml`）:

| ルート | メソッド | パス | 要件 |
|--------|----------|------|------|
| `slack_portal.api.export` | `POST` | `/api/slack-portal/export` | `_permission: administer slack_portal` ＋ `_csrf_request_header_token: TRUE` |
| `slack_portal.api.status` | `GET` | `/api/slack-portal/status` | `_permission: administer slack_portal` |

`SlackPortalApiController::triggerExport()` が `ExportTrigger::trigger()` を呼ぶ。詳細レスポンス形式は [docs/spec/portal-api.md](./portal-api.md) を参照。

### 3.2 `ExportTrigger::trigger()` の実行順序

1. **トークン解決とクライアント構築**: `SlackTokenProvider::getToken()` → `SlackClientFactory::create($token)`。
2. **日数の解決**: `SlackTokenProvider::getSinceDays()`（Settings / 環境変数 / 既定 90、§6）。`$oldest = $time->getCurrentTime() - $days * 86400`。
3. **ユーザ取得＆書き出し**: `SlackFetcher::fetchUsers()` → `SlackWorkspaceMapper::toCanonicalUser()` → `CanonicalJsonWriter::writeUsers()`（`users.json`）。
4. **チャンネル取得**: `SlackFetcher::fetchChannels($client, 'public_channel,private_channel,mpim,im')` → `SlackWorkspaceMapper::toChannelMeta()`。
5. **状態を `running` に遷移**: `ExportStateService::start(channelCount, userCount)`。
6. **チャンネルごとに 1 キュー項目を投入**: `slack_portal_fetch` キューへ `['channel_meta' => $channelMeta, 'oldest' => $oldest]` を `createItem()`。
7. **サマリを返す**: `['queued' => <チャンネル数>, 'users' => <ユーザ数>]`。

> トリガ時点で **users.json は同期的に書かれる**が、チャンネル本体・添付・マニフェストは後続の cron 処理に委ねられる。

### 3.3 バックグラウンド処理: `SlackFetchQueueWorker`

QueueWorker プラグイン定義（属性）:

```php
#[QueueWorker(
  id: 'slack_portal_fetch',
  title: new TranslatableMarkup('Slack Portal channel fetch'),
  cron: ['time' => 60],
)]
```

`cron: ['time' => 60]` により、cron 実行ごとに最大 **60 秒**だけこのキューを処理する。

`processItem($data)` の動作（1 項目 = 1 チャンネル）:

1. キュー項目から `channel_meta` / `oldest`（未設定なら `NULL`）/ `attempt`（未設定なら `0`）を取り出す。
2. `SlackTokenProvider::getToken()` → `SlackClientFactory::create($token, getMaxRetries())`。
3. `ChannelExporter::exportChannel($client, $token, $channelMeta, $oldest)` を実行（§4）。返り値は `['messages' => N, 'files' => M]`。
4. **成功時**: `SlackWorkspaceMapper::toChannelIndexEntry($channelMeta)`（`id`/`name`/`type`/`file`）を組み、`ExportStateService::recordChannel($indexEntry, $messages, $files)` で進捗を加算する（channel id で**冪等**＝再配信で二重計上しない）。
5. **失敗時（有界リトライ）**: 例外を**再 throw しない**。`attempt + 1 < MAX_ATTEMPTS`（=3）なら、`attempt` を +1 した項目を `slack_portal_fetch` に**再 enqueue**して現項目を消費する（status は `running` のまま）。上限到達なら `ExportStateService::recordFailure($channelId, "channel <id> export failed after 3 attempts")`（トークン非含有の事前マスク文字列）で失敗を確定する。いずれもログは `SecretMasker::mask()` を通す。
6. **完了判定**: 成功・失敗確定のいずれの後でも、`ExportStateService::isComplete()`（`total > 0 && processed + failed >= total`）が真なら `writeManifestAndFinish()` で **manifest.json** を書き、`finish()` で terminal 状態へ遷移する（**失敗が 1 件でもあれば `error`、無ければ `done`**）。再 enqueue だけの場合は未完了なので no-op。

### 3.4 (B) のマニフェスト内容

`SlackFetchQueueWorker::writeManifestAndFinish()` が書く `manifest.json`（実装より）:

```json
{
  "schema_version": 1,
  "generated_at": "<gmdate Y-m-d\\TH:i:s\\Z>",
  "counts": {
    "channels": 0,
    "failed": 0,
    "messages": 0,
    "users": 0,
    "files": 0
  },
  "channels": []
}
```

経路 (A) との差分（実装の事実として明記）:

- `since_days` / `oldest_ts` キーは **含まれない**。
- `counts.channels` は **`processed`**（成功したチャンネル数）、`counts.failed` は失敗確定したチャンネル数。`counts.messages` / `counts.users` / `counts.files` と `channels` 配列はいずれも `ExportStateService` の累積値から取る。`counts.files` は **ChannelExporter が実際に DL したインラインファイル数**（`files.list` の総数ではない＝経路 A とは数え方が異なる）。

---

## 4. 単一チャンネルのパイプライン（`ChannelExporter::exportChannel()`）

両経路が共有する中核。`$client`・`$token`・`$channelMeta`（少なくとも `id` を含む）・`$oldest`（`?int`）を受け取り、`['messages' => <折りたたみ後トップレベル件数>]` を返す。呼び出し間で状態を持たず、同一入力に対して冪等。

実行順序（番号付き）:

1. **履歴取得**: `SlackFetcher::fetchHistory($client, $channelId, $oldest)`（`conversations.history`、cursor pagination、`limit=200`）で全メッセージを取得。各 raw メッセージを `CanonicalMessageFormatter::format($rawMsg, $channelId)` で正規化し、フラットリストに積む。
2. **スレッド返信取得**: 各正規化メッセージのうち **スレッドルート**（`thread_ts === slack_ts` かつ `reply_count > 0`）について `SlackFetcher::fetchReplies($client, $channelId, $slackTs)` を呼び、返信を同じフラットリストに追加する。`fetchReplies` は **親メッセージ（`ts === threadTs`）を除外**し、返信のみを返す。
3. **インラインファイルのダウンロード**: フラットリスト中の各メッセージの `files[]` を走査し、`SlackFileDownloader::download()` で本体を取得（§5）。各ファイルに `local_path`（相対パス `files/<id>.<ext>`）を設定し、`url_private` を `'REDACTED'` に置換する（トークン付き URL を JSON に残さないため）。
4. **スレッド折りたたみ**: `ThreadFolder::fold($flatList)` でフラットリストを親メッセージ配下に返信をぶら下げた構造へ折りたたむ。
5. **canonical JSON 書き出し**: `CanonicalJsonWriter::writeChannel($channelId, $channelMeta, $folded)` で `channels/<channelId>.json` を書く。
6. **統計を返す**: `['messages' => count($folded)]`（折りたたみ後のトップレベル件数）。

> 添付ファイルの DL は **このパイプライン内（ステップ 3）でメッセージのインライン `files[]` に対してのみ**行われる。`files.list`（§5）はチャンネルパイプラインの一部ではない。

---

## 5. ページネーション方式

### 5.1 cursor pagination（`CursorIterator` / `SlackFetcher`）

`conversations.history`・`conversations.list`・`users.list` は Slack の **cursor pagination** を使う。`SlackFetcher` は各エンドポイントのレスポンスを `FETCH_RESPONSE` モードで生 PSR-7 として受け取り、`decodePage()` で対象配列キー（`messages` / `channels` / `members`）と `response_metadata.next_cursor` を取り出す。

`CursorIterator::pages()` のループ条件:

- 初回 cursor は `''`。
- 各ページの `next_cursor` を次の cursor として使う。
- **`next_cursor` が空文字・`null`・不在になった時点でループ終了**（最後のページの items を yield した後）。

全ての cursor 系リクエストは `limit=200`。`fetchHistory` は `$oldest !== NULL` のとき `oldest` パラメータ（文字列）を付与する。

> `FETCH_RESPONSE` を使う理由（実装コメント）: jolicode の `SlackErrorPlugin` がレスポンス本体に対し `getContents()` を呼ぶためストリームが EOF に達する。`(string) $response->getBody()` で `Stream::__toString` 経由の rewind が起き、JSON を正しくデコードできる。

### 5.2 ページ番号ページング（`files.list`）

`SlackFetcher::fetchFiles()` は **cursor を使わない**。`files.list` のレスポンスに含まれる `paging` オブジェクト（`page` / `pages`）を見てページ番号を増分する:

- `count=200`、`page=1` から開始（いずれも文字列）。`$tsFrom !== NULL` のとき `ts_from`（文字列）を付与。
- 各レスポンスの `files` を yield し、`paging.pages` を `$totalPages` として読む。
- `$page` を増分し、**`$page <= $totalPages` かつ直前ページの `files` が空でない**間ループする。

前述のとおり `files.list` は **経路 (A) のマニフェスト件数集計**でのみ使われる。

---

## 6. `--since` / since-days の文法

取得開始時刻は `oldest = now - days * 86400`（Unix 秒）で算出される。`days` の解決は経路で異なる。

### 6.1 経路 (A): `--since` の解析（`SlackExportCommands::parseDays()`）

- 入力末尾の `d` / `D` を除去（`rtrim($since, 'dD')`）。
- 除去後が**正の数値**なら整数化して採用。
  - `'90d'` → `90`
  - `'30'` → `30`
- それ以外（非数値・0 以下）は **既定値 `90`**。
- `now` は `time()`。

### 6.2 経路 (B): `getSinceDays()`（`SlackTokenProvider`）

解決順（最初に正値が得られたものを採用、いずれも 0 以下は「未設定」扱い）:

1. Settings キー `slack_export_since_days`
2. 環境変数 `SLACK_EXPORT_SINCE_DAYS`
3. 既定 `90`

`now` は `TimeInterface::getCurrentTime()`。

---

## 7. レート制限と再試行（429 / 503 backoff）

`SlackClientFactory` は Guzzle の `HandlerStack` に `caseyamcl/guzzle_retry_middleware`（`GuzzleRetryMiddleware`）を push する。これは jolicode の php-http プラグイン層より下の **Guzzle トランスポート層**で再試行するため、`SlackErrorPlugin` が応答を見る前に 429／503 を吸収する。

`SlackClientFactory::defaultRetryOptions($maxRetries)`（実装値）:

```php
[
  'max_retry_attempts'         => $maxRetries ?? 10,
  'retry_on_status'            => [429, 503],
  'default_retry_multiplier'   => 1.5,
  'max_allowable_timeout_secs' => 60,
]
```

挙動:

- **対象ステータス**: `429`（ratelimited）と `503`（service unavailable）。
- **`Retry-After` 尊重**: ヘッダがあればその秒数を待つ。
- **指数バックオフ**: `Retry-After` が無い場合は `1.5×` の倍率で増加。
- **単一待機の上限**: 1 回の再試行待機は最大 **60 秒**。
- **最大再試行回数**: `SlackTokenProvider::getMaxRetries()`（Settings `slack_rate_limit_max_retries` / 環境変数 `SLACK_RATE_LIMIT_MAX_RETRIES` / 既定 **10**）の値。`create($token, $maxRetries)` 経由で全呼び出し元（Drush / ExportTrigger / QueueWorker）が設定値を渡す。

> 注: この middleware 層の retry（429/503 backoff）は HTTP 1 リクエスト内の再試行。チャンネル単位の失敗に対する**有界リトライ（attempt / MAX_ATTEMPTS=3 の再 enqueue）は QueueWorker 層**（§3.3）で別途行う。

---

## 8. ファイルの取り扱い

### 8.1 ダウンロード（`SlackFileDownloader::download()`）

- 取得対象は **メッセージのインライン `files[]`**（§4 ステップ 3）。チャンネルパイプラインからのみ呼ばれる。
- **ホスト許可リスト（token 流出 / SSRF 防止）**: Bearer を付ける前に `url_private` を検証し、**`https` かつ host が `slack.com` / `*.slack.com`** の場合のみダウンロードする。それ以外（external/remote file 等で `url_private` が攻撃者ホストを指す場合）は**リクエストせずスキップ**し警告ログ（token 非出力）を残す。これによりワークスペース資格情報を Slack 以外へ送らない。
- **Bearer 認証 + ストリーム**: `Authorization: Bearer <token>` ヘッダを付け、Guzzle の `sink` オプションでディスクへ直接ストリームする（本体をメモリに全展開しない）。
- **保存パス**: `baseDir()/files/<fileId>.<ext>`。`<ext>` はファイル名から導出（小文字、拡張子が無ければ `bin`）。
- **トークンの秘匿**: トークンはヘッダにのみ載り、JSON へは `url_private = 'REDACTED'` として書かれる。`local_path` に相対パスを記録する。

### 8.2 冪等な重複排除

`SlackFileDownloader` は **既存ファイルのサイズ一致でスキップ**する:

- 宛先が既に存在し、`$expectedSize` が `NULL`、または既存ファイルの `filesize()` が `$expectedSize` と一致するなら、HTTP リクエストを行わず宛先 URI を返す。
- `ChannelExporter` は各 file の `size` を `$expectedSize` として渡すため、同一 ID・同一サイズのファイルは再ダウンロードされない（ファイル ID をパスに使うため ID 単位で重複排除される）。

### 8.3 `files.list` との関係（実装の事実）

- 経路 (A) は `files.list` を**件数集計目的でのみ**反復し（`counts.files` = workspace 総ファイル数）、その結果でダウンロードや突き合わせは行わない。
- 経路 (B)（キュー worker）は `files.list` を**まったく実行しない**。`counts.files` は **ChannelExporter が実際に DL したインラインファイル数の累計**（State 由来）であり、経路 A の総数とは数え方が異なる。
- いずれの経路でも `files.list` の結果とインライン `files[]` の dedup-merge は行わない。ファイルの重複排除は §8.2 のダウンロード単位（宛先サイズ一致）でのみ機能する。

---

## 9. シークレットの取り扱い

`.claude/rules/secrets-and-pii.md`（SECRETS RULE）に従い、Slack トークン（`xoxp-` / `xoxb-`）は**ログ・例外・レスポンス・キュー項目・出力 JSON のいずれにも出力／永続化しない**。実装上の保証点:

- `SlackTokenProvider` の例外メッセージはトークン／暗号文を含まない汎用文言（「Slack user token is not configured.」等）。
- `ChannelExporter` はファイル DL 後に `url_private` を `'REDACTED'` へ置換。
- `SlackFileDownloader` はトークンを `Authorization` ヘッダにのみ載せ、ログには出さない。さらに **`https` の Slack ホスト（`slack.com` / `*.slack.com`）以外には一切リクエストしない**ため、token が Slack 以外へ送られることはない（§8.1）。
- `ExportTrigger` のログ・返り値・キュー項目データにトークンは現れない。
- `SlackFetchQueueWorker` は失敗時に事前マスク済みの `"channel <id> export failed"` のみを `ExportStateService::fail()` へ渡す。
- `SlackPortalApiController` はエラー応答前に `xox[a-z]-…` パターンを `[REDACTED]` へ置換する。

---

## 10. 冪等性と再開可能性

- **canonical JSON 書き出し**: `CanonicalJsonWriter` は決定論的エンコード（`JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`）と上書き（rename しない）で、同一入力に対しバイト等価な出力を保証する。出力先は固定の `public://slack_archive/latest/` 配下。
- **ファイル DL**: 宛先サイズ一致でスキップ（§8.2）。
- **チャンネル再処理**: `ChannelExporter::exportChannel()` はステートレスかつ冪等。キュー worker は失敗時に例外を再 throw し、Drupal のキュー機構による再試行で安全に再処理できる。
- **進捗の真実源**: 経路 (B) の進捗は `ExportStateService`（State キー `slack_portal.export_status`）。`idle → running → done`（または `error`）と遷移する。
