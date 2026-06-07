# 認証情報・シークレットの構成（credentials reference）

> Diátaxis: **reference**（事実の参照）。本書は slack_portal モジュールが Slack の user token と暗号鍵をどこに・どの形式で保持し、どの順序で解決するかを、ソース実装に即して記述する。設計判断の背景は ADR を、運用手順は how-to を参照すること。

対象モジュール: `web/modules/custom/slack_portal/`
対象 Drupal: 11 / PHP 8.3

---

## 1. 概要：何を・どこに保存するか

slack_portal が扱う秘匿情報は 2 種類ある。両者は保管場所も性質も異なる。

| 種別 | 値の例 | 保管場所 | git 追跡 |
|------|--------|----------|----------|
| **Slack user token** | `xoxp-…` | 暗号化して Drupal **State** (`slack_portal.token_ciphertext`) ／ または Settings ／ env | されない |
| **暗号鍵（bootstrap secret）** | base64 化した 32 byte 乱数 | **環境変数** `SLACK_ENCRYPTION_KEY` | されない |

Slack token は **平文で config / git に書かない**。State に置く場合は drupal/encrypt（`real_aes`）で暗号化した ciphertext のみを保存し、復号は実行時に必要なときだけ行う。復号に必要な鍵（暗号鍵）だけを env から供給する。これは「シークレットを平文で永続化せず、bootstrap secret のみを env／file に置く」という Drupal の一般的なベストプラクティスに沿った構成である。

`workspace_url`（Slack workspace の URL）は秘匿情報ではないため、通常の config（`slack_portal.settings`）に平文で保存される。

---

## 2. トポロジ図（暗号鍵 → 暗号化／復号）

```
            env var
        SLACK_ENCRYPTION_KEY
        (base64 化された 32 byte = 256bit 乱数)
                 │  base64_encoded: true
                 ▼
   Key エンティティ  key.key.slack_portal_encryption
        key_type:     encryption (key_size: 256)
        key_provider: env  (env_variable: SLACK_ENCRYPTION_KEY)
        key_input:    none
                 │
                 ▼
   Encrypt プロファイル  encrypt.profile.slack_portal
        encryption_method: real_aes        (defuse/openssl AES)
        encryption_key:    slack_portal_encryption
                 │
        ┌────────┴─────────┐
   encrypt()           decrypt()
   （保存時）           （取得時）
        │                  │
        ▼                  ▲
   Drupal State  slack_portal.token_ciphertext  ← ciphertext のみ保存
   （平文 token は config／git に出ない）
```

- 暗号鍵は **env のみ**から供給される（鍵自体は Key/Encrypt config に値として含まれない。config には「env のどの変数を読むか」だけが書かれる）。
- token の ciphertext は **State**（`key_value` テーブル相当）に入り、config export／git には載らない。
- 平文の token は「フォーム送信時の暗号化直前」と「復号して Slack API に渡す瞬間」だけメモリ上に存在する。

### 2.1 関連する config（install 時投入）

`web/modules/custom/slack_portal/config/install/key.key.slack_portal_encryption.yml`:

```yaml
id: slack_portal_encryption
label: 'Slack Portal Encryption Key'
key_type: encryption
key_type_settings:
  key_size: 256
key_provider: env
key_provider_settings:
  env_variable: SLACK_ENCRYPTION_KEY
  base64_encoded: true
  strip_line_breaks: true
key_input: none
key_input_settings: {}
```

`web/modules/custom/slack_portal/config/install/encrypt.profile.slack_portal.yml`:

```yaml
id: slack_portal
label: 'Slack Portal Encryption Profile'
encryption_method: real_aes
encryption_method_configuration: {}
encryption_key: slack_portal_encryption
dependencies:
  config:
    - key.key.slack_portal_encryption
  module:
    - real_aes
```

`SlackTokenProvider` / `SlackSettingsForm` は暗号化プロファイル ID `slack_portal`、State キー `slack_portal.token_ciphertext` を既定値として参照する。

---

## 3. token 解決順序（resolution order）

`SlackTokenProvider::getToken()`（`src/Service/SlackTokenProvider.php`）は、**最初に見つかった非空の値が勝つ**方式で 3 段階に解決する。各段で値を `trim()` し、空文字なら次の段へフォールバックする。

| 優先 | ソース | キー名 | 備考 |
|------|--------|--------|------|
| 1 | Drupal **Settings** | `slack_user_token` | `settings.local.php` / `settings.php`。`trim` 後に非空なら採用。 |
| 2 | **環境変数** | `SLACK_USER_TOKEN` | `getenv()`。`trim` 後に非空なら採用。 |
| 3 | Drupal **State**（暗号化済） | `slack_portal.token_ciphertext` | ciphertext を暗号化プロファイル `slack_portal` で復号。 |

解決ロジックの要点:

- いずれの段でも値が得られなければ `\RuntimeException('Slack user token is not configured.')` を投げる。
- State に ciphertext はあるが暗号化プロファイル `slack_portal` が取得できない（鍵未設定など）場合は、復号を試みず、その段はスキップされる（最終的に「未設定」例外に至る）。
- 復号に失敗した場合（鍵不一致・データ破損など）は `\RuntimeException('Slack user token could not be decrypted.')` を投げる。
- **例外メッセージは値を一切含まない**（cipher も token 断片も出さない）。これは `@throws` docblock とコード内コメントで明示されている。

> Settings／env を最優先にすることで、ポータル UI で暗号化保存する経路とは別に、運用・CI・ローカルでの一時オーバーライドが可能になっている。

---

## 4. 設定フォームの挙動（SlackSettingsForm）

管理画面 `/admin/config/services/slack-portal`（権限 `administer slack_portal` 必須）。`src/Form/SlackSettingsForm.php`。

### 4.1 フィールド

| フィールド | `#type` | 保存先 |
|------------|---------|--------|
| Slack Workspace URL | `url` | config `slack_portal.settings:workspace_url`（平文） |
| Slack User Token | `password` | 暗号化後 State `slack_portal.token_ciphertext` |

- token は `#type => password` のため、入力値は画面に表示されない。`#default_value` は常に空文字（既存 token を再表示しない）。

### 4.2 「設定済み」表示（マスク）

`buildForm()` は State の ciphertext 有無で description を出し分ける。

- 既に ciphertext が保存済み（非空文字列）: **「設定済み（再入力すると上書き。空欄なら現状維持）」**
- 未保存: **「未設定」**

入力欄自体が password タイプであり、token 平文・ciphertext は description にも default value にも出力されない。

### 4.3 送信時（submitForm）

1. `workspace_url` を config `slack_portal.settings` に保存する。
2. 送信された token が**非空**のときだけ暗号化処理に入る:
   - 暗号化プロファイル `slack_portal` が未インストールなら、`messenger()->addError('暗号化プロファイル未設定（SLACK_ENCRYPTION_KEY を設定してください）')` を表示し、**`parent::submitForm()` を呼ばずに return**（既定の保存成功メッセージを出さない）。
   - プロファイルがあれば `encryption->encrypt()` で暗号化し、ciphertext を State `slack_portal.token_ciphertext` に保存する。**平文も ciphertext もログに出さない**。
3. 送信された token が**空**のとき: 既存の State ciphertext は**変更しない**（現状維持）。

> したがって token を変更したいときだけ入力すればよく、URL だけ更新したい場合は token 欄を空欄のまま送信すれば既存 token が維持される。

---

## 5. 非ログ／マスク規約

token・ciphertext は**例外文・ログ・HTTP 応答・queue item・archive のいずれにも出さない**。実装上の担保は次のとおり。

- **SlackTokenProvider**: 例外メッセージは値非依存の固定文言のみ。復号失敗時も cipher を出さない（`catch (\Throwable)` 内コメントで明示）。
- **SlackSettingsForm**: 暗号化前後ともログ出力しない（コード内コメント `NEVER log the plaintext or the ciphertext`）。
- **SlackPortalApiController**（`src/Controller/SlackPortalApiController.php`）: `ExportTrigger` から伝播した例外を HTTP 応答へ返す前に**サニタイズ**する。

  ```php
  $safeMessage = preg_replace('/xox[a-z]-[^\s"\']+/i', '[REDACTED]', $rawMessage) ?? $rawMessage;
  ```

  Slack token 形（`xoxp-` / `xoxb-` など `xox?-` プレフィクスに続く非空白・非引用符の連なり）を `[REDACTED]` に置換する。置換後のメッセージのみをログ（`logger.channel.slack_portal`）と JSON 応答（`{"status":"error","message":"<sanitised>"}`、HTTP 500）に出す。

- canonical archive（`private://slack_archive/`）には会話・ファイルのみを書き、token は書かない。

---

## 6. 取得設定値（since-days / max-retries）

`SlackTokenProvider` は token 以外の取得パラメータも同じ「Settings → env → 既定値」の順序で解決する。各値は **0 以下を「未設定」扱い**にしてフォールバックする（正の整数のみ採用）。

| メソッド | Settings キー | env 変数 | 既定値 |
|----------|---------------|----------|--------|
| `getSinceDays()` | `slack_export_since_days` | `SLACK_EXPORT_SINCE_DAYS` | **90** |
| `getMaxRetries()` | `slack_rate_limit_max_retries` | `SLACK_RATE_LIMIT_MAX_RETRIES` | **10** |

`.env.example` には `SLACK_USER_TOKEN` / `SLACK_EXPORT_SINCE_DAYS=90` / `SLACK_RATE_LIMIT_MAX_RETRIES=10` / `SLACK_ENCRYPTION_KEY` が定義されている。

> 注: State 経由の暗号化保存（ポータル UI からの token 入力）を使う場合は `SLACK_ENCRYPTION_KEY`（base64 化した 32 byte 乱数。生成例 `openssl rand -base64 32`）の設定が必須。Settings(`slack_user_token`) / env(`SLACK_USER_TOKEN`) で直接 token を渡す場合は暗号鍵は不要（§3 の解決順を参照）。

---

## 7. env／外部 provider の差し替え（seam）

暗号鍵の供給元は Key モジュールの **key_provider** で抽象化されている。現状の install config は `key_provider: env`（dev 向け）。本番・クラウドでは Key エンティティの provider を差し替えるだけでよく、**`SlackTokenProvider` / フォーム側のコード変更は不要**（暗号化・復号は Encrypt プロファイル経由で抽象化されているため）。

| 環境 | 想定 provider | 鍵の所在 |
|------|---------------|----------|
| dev / ローカル | `env`（現状） | 環境変数 `SLACK_ENCRYPTION_KEY` |
| クラウド（簡易） | `file` provider | docroot 外のファイル |
| クラウド（推奨） | 外部 Key provider（AWS Secrets Manager / HashiCorp Vault / Azure Key Vault 等。ESO で注入） | 外部シークレットストア |

> **M1 スコープ**: 外部 Key provider の contrib モジュールは導入しない。env provider のみを採用し、上記の差し替え可能性は**シーム（拡張点）として担保**する位置づけ。

---

## 8. 権限

`slack_portal.permissions.yml`:

```yaml
administer slack_portal:
  title: 'Administer Slack Portal'
  description: 'Configure Slack workspace URL and encrypted token for the Slack Portal module.'
  restrict access: true
```

- `restrict access: true`（運用上のセキュリティ権限）。
- この権限が、設定フォーム（`/admin/config/services/slack-portal`）および API の trigger / status ルート双方の要件になっている。

---

## 9. 関連ドキュメント

- [ADR-0012 — ポータル管理 credential ＋ frontend トリガ background ingest](../adr/0012-portal-managed-credentials-and-triggered-ingest.md)（本トポロジの決定）。
- [ADR-0009 — secrets / PII の取扱](../adr/0009-secrets-and-pii-handling.md)（取扱方針）。
- [docs/spec/portal-api.md](./portal-api.md) — trigger / status API 仕様（本書の §5・§8 と相互参照）。
- [docs/spec/ingest-pipeline.md](./ingest-pipeline.md) — 取得パイプライン（token の利用箇所）。
- `.claude/rules/secrets-and-pii.md` — secrets / PII の運用ルール（コミット禁止・ログ禁止・Key/Settings/env での保管）。
