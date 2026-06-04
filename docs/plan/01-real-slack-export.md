# 01. 実 Slack エクスポート ★最優先

⚠️ 無料 Slack の可視履歴は毎日消える。**この機能を最優先で緑にし、早期に 1 回 `make export` を実行**してデータを確保する。`.claude/rules/slack-export-safety.md` 準拠。担当：`drupal-backend-implementer`。

## ToDo（1 ToDo = 1 BDD = 1 Red、size タグ）
- [ ] (small) SlackClientFactory：`xoxp-` token を Bearer に設定した `jolicode/slack-php-api` クライアントを生成する
- [ ] (small) cursor 反復：`response_metadata.next_cursor` が空になるまで全ページを yield、空で停止する
- [ ] (small) 429 backoff：`guzzle_retry_middleware` 設定が `Retry-After` を尊重し指数バックオフ（上限あり）する
- [ ] (small) canonical 整形：raw payload → canonical message（必須欄・編集済み・subtype）へ正規化する
- [ ] (small) スレッド畳み込み：`thread_ts == ts` の親に replies をぶら下げる
- [ ] (medium) client fetch：respx 相当の Guzzle `MockHandler` でページング＋429→200 リトライを検証する
- [ ] (medium) ファイル DL：`url_private` を Bearer＋stream でディスクへ保存する
- [ ] (medium) CanonicalJsonWriter：`public://slack_archive/<ts>/` に正規化 JSON を出力する
- [ ] (medium) SlackFetchQueueWorker：1 ページ取得し次 cursor を enqueue（MockHandler）する
- [ ] (large) `ddev drush slack:export --since=90d` が全チャンネル（public/private/DM/MPDM）＋threads＋files＋users＋reactions を取得し archive を生成する（fake Slack API）
- [ ] ADR-0003 / ADR-0004 を `accepted` に（documentor）

## 完了の定義
- `make export` が canonical JSON を `public://slack_archive/` に生成。token はログに出ない。再実行で冪等。

## 次
→ 02-drupal-model-and-migrate.md
