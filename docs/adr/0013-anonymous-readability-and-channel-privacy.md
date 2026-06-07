---
status: accepted
date: 2026-06-07
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0013: 匿名閲覧可否とチャンネルプライバシー（公開範囲ゲーティング）

## Context and Problem Statement
Slack workspace の全履歴（public / private / im / mpim）を canonical JSON（ADR-0004）として保全し、Drupal
エンティティへ取り込み（ADR-0006）、ヘッドレス API（ADR-0005）＋匿名 read のポータル（ADR-0008）で閲覧・検索する。
しかし実エクスポート（実測）では **119 チャンネル中 104（約 87%）が private / im / mpim** であり、本文・実名・ユーザ ID
等の PII を含む。完全アーカイブをポータルに載せつつ、private / DM を匿名公開で漏洩させない**公開範囲の境界**を
データモデル構築の**前に**確定する必要がある（漏洩は重大インシデント・ADR-0009）。

canonical の **message 行はプライバシーフラグを持たない**（種別は channel オブジェクト側の `type` のみ）。したがって
取込時に各メッセージへ channel 種別を伝播し、そこから可視性（`status`）を導出できる設計でなければならない。

本 ADR は **ADR-0006（データモデルと migrate）の前提（gating）** であり、本決定が確定するまで ADR-0006 は accepted に
しない（アクセス方針未確定のままモデルを固定しないため）。

## Decision Drivers
- private / DM の匿名漏洩を**構造的に**防ぐ（PII 保護・ADR-0009）。
- それでも**完全アーカイブ**をポータルに保持する（90 日 erosion 後の唯一の正本）。
- 全文検索（Search API・ADR-0005）が private 本文を再露出させない。
- 実装が単純で core のアクセス制御・索引機構に素直に乗る（過剰設計しない）。
- 将来のクラウド公開時に認証付き閲覧へ拡張可能（ADR-0008 の superseding 余地）。

## Considered Options
1. **public チャンネルのメッセージだけ取り込む**（private / DM を破棄）。
2. **全メッセージを取り込み、`status`（published/unpublished）で可視性をゲートする**
   （public_channel→published＝匿名 read 可、private_channel / im / mpim→unpublished＝匿名不可）。
3. 全メッセージを published で取り込み、フィールド/エンティティ単位の独自アクセス層で都度フィルタする。

## Decision Outcome
採用: **Option 2（= 通称 "Option B"）**。

- **全メッセージを node 化**し、`slack_message.status` を **channel 種別から導出**する:
  - `public_channel` → **published（status=1、匿名 read 可）**
  - `private_channel` / `im` / `mpim` → **unpublished（status=0、匿名不可・将来の認証時のみ閲覧）**
- canonical の message 行はプライバシーフラグを持たないため、**source plugin が各行に `channel_type` を付与**し、
  migration が `status` をそこから導出する（実装は ADR-0006・`docs/spec/migrate.md`）。
- **email は取り込まない**（`slack_users` から `field_email` を除外、users migration でもマップしない）。
- **real_name / display_name / avatar は取り込み、匿名表示を許容**する。これらは public チャンネルの投稿者識別に
  必要で、email や DM 本文に比べ機微性が低い（社内ポータル・ADR-0008 の匿名 read 前提）。
- **Search API は published のみ索引**する（M3・plan/03 への申し送り）。unpublished の private を全文検索で再露出させない。

Option 1 は完全アーカイブ要件（87% を失う）に反するため不可。Option 3 は core の node access / 索引フィルタを迂回する独自
アクセス層となり、漏洩面・保守コストが大きく脆いため不可。Option 2 は core の published/unpublished 機構（匿名は published
のみ閲覧）と Search API の "published のみ索引" に素直に乗り、単一フラグで境界を表現できる。

## Consequences
### Positive
- 完全アーカイブを保持しつつ、匿名漏洩を**単一の `status` フラグ**で構造的に防止。
- public の会話は今すぐ匿名で閲覧・全文検索でき、private / DM は安全に保管され将来の認証閲覧に備えられる。
- core の node access＋Search API の published-only 索引に乗るため実装・監査が単純。

### Negative
- private / DM は現時点ではポータルから**閲覧不可**（将来の認証導入＝ADR-0008 の superseding が必要）。
- canonical の message 行にプライバシーフラグが無いため、**source plugin が channel_type を各行へ付与**する責務を負う
  （実装上の制約）。
- public チャンネル投稿者の real_name が匿名に表示される（email 除外で機微性を抑えた上での容認トレードオフ）。
- **添付ファイルの公開範囲は本 ADR では未対応（既知のギャップ・M3 で対処）**: private / im / mpim の添付も M1 で
  `public://slack_archive/latest/files/` に保存され、Web サーバが直接配信し得る。M2 は各ファイルを managed `file`
  エンティティ化するため、JSON:API `file--file` 経由で URI が列挙され得る（匿名アクセスは `access content` 権限の有無に依存）。
  本 ADR の `status` gating は node 本文のみを対象とし、ファイル実体は対象外。**M3 の認証導入時に対処する**
  （`private://` への移送／`file--file` JSON:API リソース無効化／`slack_archive/` URI のファイルアクセス制御 のいずれか）。
  追随は `docs/plan/03-jsonapi-and-search.md`。

## Confirmation
- [x] Kernel: `private_channel` / `im` / `mpim` fixture のメッセージ node が **unpublished（status=0）**、
      `public_channel` が **published（status=1）** になる（`SlackMessagesScalarMigrateTest` /
      `SlackMigrationIdempotencyTest`）。実 export では **916 件中 published 141 / unpublished 775**
      （public チャンネル 15 / private・im・mpim 104）で gating が機能。
- [x] users migration が **email をマップしない**（`slack_users` に `field_email` が存在しない＝
      `SlackUsersMigrateTest` で `hasField('field_email') === false`）。
- [ ] M3: Search API index が **published のみ**を対象にする（datasource の bundle/status フィルタ）。plan/03 へ申し送り。

## More Information
- 関連: [ADR-0005](0005-headless-drupal-jsonapi-search.md)（JSON:API read-only＋Search API DB）、
  [ADR-0006](0006-slack-to-drupal-data-model-and-migrate.md)（データモデル・本 ADR が gating）、
  [ADR-0008](0008-internal-portal-auth-and-cors.md)（匿名 read＋CORS・将来の認証拡張）、
  [ADR-0009](0009-secrets-and-pii-handling.md)（secrets / PII の取扱）。
- 仕様: `docs/spec/data-model.md`（status/privacy・email 除外）、`docs/spec/migrate.md`（channel_type→status 導出）。
- Drupal node access（published/unpublished と匿名権限）、Search API "Index items immediately" / published フィルタ。
