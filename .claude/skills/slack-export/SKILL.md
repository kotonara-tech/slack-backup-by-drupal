---
name: slack-export
description: 実 Slack ワークスペースの直近90日を取得し canonical 正規化 JSON を public://slack_archive/ に出力、続けて Drupal へ取込む。Milestone 1 の実装が緑になってから有効。トークンは echo しない。
---

# /slack-export

⚠️ **90 日 erosion 対策の最重要操作。** `docs/plan/01-real-slack-export.md` の実装が緑になってから使う。

## 前提
- `SLACK_USER_TOKEN`（`xoxp-`）を env / Drupal Key で設定済み（**echo・ログ出力しない**）。`.claude/rules/slack-export-safety.md` 準拠。
- DDEV 起動済み（`ddev start`）。

## 手順
1. 取得：`ddev drush slack:export --since=90d`
   - 全チャンネル（public/private/DM/MPDM）＋ threads ＋ files ＋ users ＋ reactions を取得。
   - cursor pagination ＋ 429 backoff ＋ Queue/Batch で冪等・再開可能。
   - 出力：canonical 正規化 JSON を `public://slack_archive/<timestamp>/`（恒久バックアップの正典）。
2. 取込：`ddev drush migrate:import --group=slack_portal`
   - canonical JSON → Drupal エンティティ（MariaDB）。再実行しても重複しない。
3. 確認：`ddev drush migrate:status --group=slack_portal`、JSON:API（`/jsonapi/...`）で件数確認。

## 注意
- アーカイブと token を**コミットしない**（`.gitignore` / `tools/check_no_archive_committed.sh` / detect-secrets）。
- 大量データはチャンネル単位分割＋ストリーミングでメモリを抑える。
