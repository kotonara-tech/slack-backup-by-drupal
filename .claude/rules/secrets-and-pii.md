# Rule: secrets / PII の取扱

Slack エクスポートは **PII（実名・email・DM 本文・ユーザ ID）** と **認証トークン** を含む。最大限の注意で扱う。

## 絶対禁止（コミット / ログ出力）
- **Slack token（`xoxp-` / `xoxb-`）** を、コード・設定・コミット・ログ・スクリーンショットに出さない。
- **canonical アーカイブ**（`private://slack_archive/`、ADR-0014）と DB ダンプをコミットしない。
  - `.gitignore` で除外済み（`**/slack_archive/` が格納先に依らず対象。private ファイルディレクトリも gitignore 済み）。
  - `tools/check_no_archive_committed.sh`（pre-commit）と `detect-secrets`（`.secrets.baseline`）で二重に検出。

## トークンの保管
- 推奨：**Drupal Key モジュール**（`drupal/key`）で秘匿。
- 簡易：`settings.local.php`（gitignore 済）または環境変数（`.env`、gitignore 済）。
- コードからは設定/サービス経由で読む。直書きしない。ログに展開しない（マスクする）。

## baseline / レビュー
- `.secrets.baseline` の更新は**人間レビュー必須**。新たな検出は誤検知か実 secret かを確認し、誤検知のみ allowlist。
- ドキュメントの例示トークンには `# pragma: allowlist secret` を付ける。

## 出力時の配慮
- Playwright のスクリーンショット・テストデータに実 PII / token を含めない（ダミーを使う）。
- エラーログ・PR コメントに DM 本文や個人情報を貼らない。
