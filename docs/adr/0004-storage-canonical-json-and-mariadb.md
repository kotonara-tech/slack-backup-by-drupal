---
status: accepted
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
採用: **Option 3**。Drush（および ポータルトリガの background queue）が **`public://slack_archive/latest/`** に canonical 正規化 JSON を書く（正典・PII を含むため非コミット）。保存先は ID 由来の決定的パス（`channels/<id>.json`・`users.json`・`manifest.json`）で**毎回同じ場所に上書き**し、再実行で **byte 同一（冪等）** になる（当初案の `<ts>/` ディレクトリは廃し、安定 `latest/` 解釈に更新＝Milestone 1 決定）。`migrate_plus` がそれを読み Drupal エンティティ（MariaDB）へ冪等取込。**MariaDB が最終 DB＝IaaS 搬送対象**。別 raw DB は持たない（サービス削減）。

## Consequences
### Positive
- バックアップはファイルとして可搬。サービスは DDEV(MariaDB) のみ。
### Negative
- 大規模時は JSON が肥大（チャンネル分割＋ストリーミング parser で対処）。

## Confirmation
- [x] `slack:export`（≒ `make export`）が `public://slack_archive/latest/` に canonical JSON を生成し、**再実行で byte 同一**（Milestone 1: `CanonicalJsonWriterTest`・`SlackExportCommandsTest`）。
- [ ] `make migrate` で再実行しても重複しない（Migrate 取込は Milestone 2 で実装＝ADR-0006）。

## More Information
- canonical スキーマは Milestone 1 で確定（`docs/spec/canonical-json.md`）。関連: ADR-0003, ADR-0006, ADR-0009, ADR-0012（ポータル管理 ingest）。
- 保存先は ADR-0014 で `private://` に変更（PII の Web 露出遮断）。保存形式・DB の決定自体は本 ADR が正典のまま不変。
