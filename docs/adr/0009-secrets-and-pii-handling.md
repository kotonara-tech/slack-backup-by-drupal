---
status: proposed
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0009: secrets / PII の取扱

## Context and Problem Statement
Slack エクスポートは PII（実名・email・DM 本文）と認証トークンを含む。誤コミット・誤公開は重大インシデント。

## Decision Drivers
- トークン/アーカイブの漏洩防止。多層防御。

## Considered Options
1. トークンをコード/設定に直書き、アーカイブをリポに含める（不可）
2. **トークンは Drupal Key / settings.local.php / env、canonical アーカイブは非コミット、多層検出（gitignore + ガードスクリプト + detect-secrets）**

## Decision Outcome
採用: **Option 2**。`xoxp-` token は `drupal/key`（推奨）/ `settings.local.php` / `.env`（いずれも gitignore）で管理しコードに直書きしない・ログに出さない。canonical アーカイブ（`public://slack_archive/`）と DB ダンプはコミットしない。検出は **`.gitignore` ＋ `tools/check_no_archive_committed.sh`（pre-commit）＋ `detect-secrets`（`.secrets.baseline`）** の三重。`.secrets.baseline` 更新は人間レビュー必須。

## Consequences
### Positive
- 漏洩リスクを構造的に低減。
### Negative
- 開発者がトークン設定の一手間を要する。

## Confirmation
- [ ] `tools/check_no_archive_committed.sh` が token/アーカイブを検出。`pre-commit run --all-files` が green。

## More Information
- `.claude/rules/{secrets-and-pii,slack-export-safety}.md`。関連: ADR-0003, ADR-0008。
