# 01. 実 Slack エクスポート ★最優先

⚠️ 無料 Slack の可視履歴は毎日消える。**この機能を最優先で緑にし、早期に 1 回 `make export` を実行**してデータを確保する。`.claude/rules/slack-export-safety.md` 準拠。担当：`drupal-backend-implementer`。

## ToDo（1 ToDo = 1 BDD = 1 Red、size タグ）
- [x] (small) SlackClientFactory：`xoxp-` token を Bearer に設定した `jolicode/slack-php-api` クライアントを生成する
- [x] (small) cursor 反復：`response_metadata.next_cursor` が空になるまで全ページを yield、空で停止する
- [x] (small) 429 backoff：`guzzle_retry_middleware` 設定が `Retry-After` を尊重し指数バックオフ（上限あり）する
- [x] (small) canonical 整形：raw payload → canonical message（必須欄・編集済み・subtype）へ正規化する
- [x] (small) スレッド畳み込み：`thread_ts == ts` の親に replies をぶら下げる
- [x] (medium) client fetch：respx 相当の Guzzle `MockHandler` でページング＋429→200 リトライを検証する
- [x] (medium) ファイル DL：`url_private` を Bearer＋stream でディスクへ保存する
- [x] (medium) CanonicalJsonWriter：`public://slack_archive/latest/` に正規化 JSON を出力する（決定的パス・byte 同一）
- [x] (medium) SlackFetchQueueWorker：1 チャンネル取得し state を更新（MockHandler）する
- [x] (large) `ddev drush slack:export --since=90d` が全チャンネル（public/private/DM/MPDM）＋threads＋files＋users＋reactions を取得し archive を生成する（fake Slack API）
- [x] ADR-0003 / ADR-0004 を `accepted` に（documentor）

## 完了の定義
- `make export` が canonical JSON を `public://slack_archive/` に生成。token はログに出ない。再実行で冪等。

## 次
→ 02-drupal-model-and-migrate.md
