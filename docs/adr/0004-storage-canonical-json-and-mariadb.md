---
status: proposed
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0004: 保存形式＝canonical JSON ＋ MariaDB（別 raw DB 不採用）

## Context and Problem Statement
取得データの「恒久バックアップ」と「検索・閲覧用ストア」をどう持つか。当初案は別 raw Postgres だったが、PHP 単一化（ADR-0003）に伴い構成を簡素化したい。

## Decision Drivers
- バックアップの恒久性・可搬性。最終的にレンタルサーバ/IaaS の DB に載る。
- サービス数・言語の最小化。

## Considered Options
1. 別 raw Postgres（Drush 書込 → Migrate 取込）
2. JSON ファイルのみ（DB は Drupal 内部のみ）
3. **canonical 正規化 JSON（恒久バックアップの正典）＋ migrate_plus(JSON source) → Drupal エンティティ（MariaDB）**

## Decision Outcome
採用: **Option 3**。Drush が `public://slack_archive/<ts>/` に canonical 正規化 JSON を書く（正典・PII を含むため非コミット）。`migrate_plus` がそれを読み Drupal エンティティ（MariaDB）へ冪等取込。**MariaDB が最終 DB＝IaaS 搬送対象**。別 raw DB は持たない（サービス削減）。

## Consequences
### Positive
- バックアップはファイルとして可搬。サービスは DDEV(MariaDB) のみ。
### Negative
- 大規模時は JSON が肥大（チャンネル分割＋ストリーミング parser で対処）。

## Confirmation
- [ ] `make export` → `public://slack_archive/` に JSON、`make migrate` で再実行しても重複しない（Kernel テスト）。

## More Information
- canonical スキーマは Milestone 1/2 で確定。関連: ADR-0003, ADR-0006, ADR-0009。
