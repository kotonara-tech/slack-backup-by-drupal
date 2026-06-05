---
status: accepted
date: 2026-06-05
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0012: ポータル管理 credential ＋ frontend トリガ background ingest

## Context and Problem Statement

Milestone 1 の拡張として、Slack workspace の URL と user token（`xoxp-`）を**ポータル側で管理**し、取得（ingest）を**フロントエンドからトリガ**できるようにしたい。これまで token は `settings.local.php` / 環境変数で運用していたが（ADR-0009）、運用者が UI から設定・更新できる経路が必要になった。

token は実名・email・DM 本文へのアクセス権を持つ**秘匿情報**であり、平文で config（git 追跡対象）に保存することはできない。一方、閲覧側の Drupal API は **read-only JSON:API**（ADR-0005）＋匿名 read（ADR-0008）であり、取得を起動する**特権 write 操作**をそこに載せることはできない。

したがって、(a) token の at-rest 暗号化と保管経路、(b) read-only API とは分離した認証付きトリガ／状態取得経路、の 2 点を決める。本 ADR は ADR-0008（auth/CORS）と ADR-0009（secrets/PII）を**拡張（extends）するものであり、いずれも supersede しない**。

## Decision Drivers

- token を平文で config / git / ログに出さない（ADR-0009 の多層防御を維持）。
- 暗号鍵（bootstrap secret）をコード変更なしで外部 secret manager に差し替え可能にする。
- read-only JSON:API の不変条件（write は 403/405）を壊さない（ADR-0005/0008）。
- 取得は 90 日 erosion 対策として再開可能・冪等・background 実行（Queue/Batch、Drush 同等）。

## Considered Options

1. token を `slack_portal.settings` config / `settings.php` に**平文保存**する。
2. **Simple OAuth** を全面採用し OAuth フローで token を授受・保管する。
3. **採用案** — `drupal/key` ＋ `drupal/encrypt`（`real_aes`）で token を暗号化し State に ciphertext 保管、取得は read-only JSON:API とは別の**認証付きカスタムルート**で起動する。

## Decision Outcome

採用: **Option 3**。

### (a) token の at-rest 暗号化と解決順

- 暗号化方式は `drupal/encrypt` の encryption profile `slack_portal`（`encryption_method: real_aes`、`defuse/php-encryption` ベース、`config/install/encrypt.profile.slack_portal.yml`）。
- 暗号鍵は `drupal/key` の key `slack_portal_encryption`（`key_type: encryption`、256-bit、`config/install/key.key.slack_portal_encryption.yml`）。**鍵の provider は env**＝環境変数 `SLACK_ENCRYPTION_KEY`（base64-encoded の 32 byte ランダム値）。クラウドでは file provider や外部 provider に差し替え可能（config はそのまま）。
- UI 入力：`SlackSettingsForm`（`/admin/config/services/slack-portal`、perm `administer slack_portal`）が workspace URL を config `slack_portal.settings` に、token を encryption profile で暗号化し **Drupal State キー `slack_portal.token_ciphertext`** に ciphertext として保存する。平文・ciphertext ともログに出さない。token 欄が空なら既存 ciphertext を維持。profile 未設置時は token を保存せずエラー表示。
- 解決：`SlackTokenProvider::getToken()` の優先順は **(1) Settings `slack_user_token` → (2) env `SLACK_USER_TOKEN` → (3) State ciphertext を encrypt で復号**（最初の非空が勝つ）。復号失敗・未設定の例外メッセージは値を一切含まない。これにより env / 外部 secret manager（ESO・AWS Secrets Manager・Vault・Azure 等）へ**コード変更なし**で差し替えできる。

### (b) 認証付きトリガ／状態取得ルート

- read-only JSON:API とは別の custom route（`slack_portal.routing.yml`）：
  - `POST /api/slack-portal/export` — perm `administer slack_portal` ＋ CSRF（`_csrf_request_header_token: 'TRUE'`）。
  - `GET  /api/slack-portal/status` — perm `administer slack_portal`。
- CORS は ADR-0008 で確立した Drupal `services.yml` 側の設定（frontend origin のみ許可、認証付きルートゆえ `supportsCredentials`）を踏襲する。モジュール側では CORS を定義しない。
- 処理フロー：`ExportTrigger::trigger()` が token を解決→users/channels を列挙→users.json を書き→`ExportStateService::start()` で **running** に遷移→**1 チャンネル = 1 キュー項目**を `slack_portal_fetch` キューへ enqueue。background の `SlackFetchQueueWorker`（`#[QueueWorker(id: 'slack_portal_fetch', cron: ['time' => 60])]`）が各項目を処理し、全チャンネル完了で manifest を書き `finish()` で **done** に遷移。失敗時は masked error を State に記録し再 throw（Drupal キューが再試行）。

### 重要な正確性: HTTP の "queued" と永続 export 状態の違い

`POST /export` の HTTP 応答は**一度きりの ack** として `{"status":"queued","queued":<int>,"users":<int>}` を返す（コントローラが `['status' => 'queued'] + result` を組み立てる）。一方、`ExportStateService` が State `slack_portal.export_status` に**永続化する状態は idle → running → done | error のみ**であり、`"queued"` という状態は持たない。`trigger()` が同期的に `start()` を呼び running にするため、enqueue 直後に `GET /status` を呼ぶと `running` が返る。HTTP の `queued` はあくまでレスポンス上のラベルである。

## Consequences

### Positive
- token が平文で config / git に出ない。暗号鍵は env / 外部 provider に差し替え可能で、クラウド移行時もコード不変。
- 取得トリガが read-only JSON:API から分離され、ADR-0005/0008 の不変条件（匿名 read・write 拒否）を維持。
- 取得は Queue で background 実行され、チャンネル単位で再開可能・冪等。状態は `GET /status` で観測可能。

### Negative
- 運用者は `SLACK_ENCRYPTION_KEY`（base64 32 byte）の払い出し・保管が必要（鍵を失うと既存 ciphertext を復号不能）。
- HTTP の `queued` と永続状態 `running` の語彙差が混乱を招きうる（本 ADR と spec で明記して緩和）。

## Confirmation

実装済みテスト（`web/modules/custom/slack_portal/tests/src/Kernel/` 配下）で確認する。

- [x] `Service/SlackTokenProviderEncryptTest`（`testDecryptsTokenFromState`, `testSettingsTakesPriorityOverState`）— State ciphertext の復号と解決順。
- [x] `Form/SlackSettingsFormTest`（`testSubmitEncryptsTokenAndSavesUrl`, `testEmptyTokenKeepsExistingCiphertext`, `testPlaintextTokenNeverStoredInStateOrConfig`）— 暗号化保存・平文非保存。
- [x] `Controller/SlackPortalApiControllerTest`（`testTriggerExportReturnsQueuedOnSuccess`, `testTriggerExportReturns500OnExceptionWithoutTokenLeak`, `testStatusReturnsRunningState`）— 200/queued・500・status。perm/CSRF はルート要件（`slack_portal.routing.yml`）で強制。
- [x] `Service/ExportTriggerTest`（`testTriggerEnqueuesChannelsAndSetsRunningState`）— enqueue ＋ running 遷移。
- [x] `Plugin/QueueWorker/SlackFetchQueueWorkerTest`（`testProcessItemCompletesWhenSingleChannel`, `testProcessItemStillRunningWhenNotAllChannelsDone`）— 処理 ＋ done。
- [x] `Service/ExportStateServiceTest`（`testStartSetsRunningStatus`, `testFinishSetsDoneStatus`, `testFailSetsErrorStatus`, `testGetStatusDefaultsToIdleWhenUnset`）— idle→running→done|error の状態機械。

## More Information

- 本 ADR は **ADR-0008（auth/CORS）** と **ADR-0009（secrets/PII）** を **extends（supersede ではない）**。関連: ADR-0003（PHP ネイティブ取得）, ADR-0004（canonical JSON ＋ MariaDB）, ADR-0005（read-only JSON:API ＋ Search API）, ADR-0006（Slack→Drupal データモデル）。
- 仕様詳細: `docs/spec/credentials.md`（credential 管理）, `docs/spec/portal-api.md`（trigger/status API）。
- 出典: drupal.org "Securing Authentication Credentials"、`drupal/key`（https://www.drupal.org/project/key）、`drupal/encrypt`（https://www.drupal.org/project/encrypt）、Decoupled Drupal / JSON:API の CSRF・CORS 取扱い。
