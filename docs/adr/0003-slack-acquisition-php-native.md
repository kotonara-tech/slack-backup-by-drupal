---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0003: Slack 取得を PHP/Drupal ネイティブで実装

## Context and Problem Statement
無料 Slack の直近〜90 日を全チャンネル取得する。バックエンドに Python(fetcher) と PHP(Drupal) が混在すると、テストランナー/静的解析/言語が二重化し coding agent が混乱する。

## Decision Drivers
- バックエンドの単一言語化（PHP）。テストは PHPUnit のみ、静的解析は PHPStan/PHPCS のみ。
- 長時間・レート制限・再開可能な取得を Drupal の仕組みで実現。

## Considered Options
1. Python fetcher（slack_sdk）＋ 別プロセス
2. migrate_plus の HTTP source で直接 Slack を叩く
3. **slack_portal 内の Drush コマンド ＋ Queue/Batch API ＋ `jolicode/slack-php-api` ＋ `caseyamcl/guzzle_retry_middleware`**

## Decision Outcome
採用: **Option 3**。`jolicode/slack-php-api`（Slack 公式 OpenAPI 生成・活発保守・PHP8.3 互換）で `conversations.list/history/replies`・`users.list`・`files.*` を取得。**user token（xoxp-）** で private/DM 読取と Tier-1 制限回避。cursor pagination＋429 backoff（Retry-After）＋Queue/Batch で冪等・再開可能。`url_private` は Bearer＋stream DL。Option 2 は cursor/二次フェッチ/429/ファイル DL に declarative が不向きなため取込専用に留める（ADR-0006）。

## Consequences
### Positive
- バックエンド PHP 一本。再開可能・冪等。Drush は Web タイムアウトなし。
### Negative
- cursor/backoff/stream DL を自前実装（SDK は薄い）。Slack 公式 SDK の一級は Python。

## Confirmation
- [x] `ddev drush slack:export --since=90d` が canonical JSON を生成（Milestone 1 で検証済み: `tests/src/Kernel/Drush/SlackExportCommandsTest`、fake workspace で全チャンネル種別＋thread 畳込＋reactions＋files、2 回実行で byte 同一）。
- [x] cursor pagination / 429 backoff / `url_private` の Bearer+stream DL を Unit/Kernel テストで検証（`CursorIteratorTest`・`SlackFetcherTest`・`SlackFileDownloaderTest`）。

## More Information
- `.claude/rules/slack-export-safety.md`。Slack rate limits 変更(2025-05-29)。関連: ADR-0004, ADR-0009。
