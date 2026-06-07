---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0006: Slack→Drupal データモデルと Migrate 取込

## Context and Problem Statement
canonical JSON（ADR-0004）を Drupal のエンティティに落とし込み、JSON:API/検索（ADR-0005）で扱えるようにする。

## Decision Drivers
- JSON:API ネイティブ・性能・取込の冪等性。

## Considered Options
1. カスタムエンティティ型
2. **ノード（content type）＋ taxonomy ＋ 参照、migrate_plus(JSON source) で取込**
3. Feeds モジュール

## Decision Outcome
採用: **Option 2**。`slack_message` content type（`field_body`・`field_channel`/`field_slack_user` 参照・`field_slack_user_id`・`field_slack_ts`(一意=migrate id)・`field_posted_at`・`field_reactions`(JSON)＋`field_reaction_total`・`field_attachments`(file 参照)・`field_thread_ts`/`field_subtype`/`field_edited`/`field_reply_count`）＋ channel/user は taxonomy（`slack_channels`/`slack_users`）＋ file は managed file 参照。`migrate_plus` の JSON source（manifest/users）＋カスタム source（`slack_canonical_messages`/`slack_canonical_files`）で canonical JSON から取込（messages の `ids` は複合 `[channel_id, slack_ts]`＝Slack ts はチャンネル内のみ一意のため → 冪等、`track_changes` で編集再取込）。**channels→users→files→messages の依存順**。**公開範囲（status/privacy）・email 非取込は [ADR-0013](0013-anonymous-readability-and-channel-privacy.md) が gating**。確定仕様は `docs/spec/data-model.md`・`docs/spec/migrate.md`。

## Consequences
### Positive
- ノードは JSON:API/Search API と相性良・性能良。`slack_ts` 一意キー＋`track_changes` で再実行は冪等。
- thread_broadcast の二重折込は source 側で channel 内 `slack_ts` dedup により 1 行へ吸収。
### Negative
- スレッド/リアクションのモデリングに工夫を要する（reactions は JSON 文字列＋集計 `field_reaction_total`、reactions/title は専用 process plugin が必要だった）。
- canonical の message 行が privacy フラグを持たないため source が `channel_type` を各行へ付与する（ADR-0013）。

## Confirmation
- [x] Kernel: canonical JSON→`slack_message` node 生成、scalar/参照/status/title/posted_at を検証（`SlackMessages*MigrateTest`）。
- [x] flatten＋dedup: 実 export で top-level 497＋折込 reply−thread_broadcast 重複 25 = **916 行**を source が yield、**916 node** 生成。
- [x] 冪等: 2 回 import で node/term/file 件数・idmap 不変（実 export で再 import = 0 created/0 updated、`SlackMigrationIdempotencyTest`）。
- [x] 編集: `track_changes` で text/edited 変更行のみ再取込、新規 node 無し（`SlackMigrationIdempotencyTest`）。
- [x] privacy: public→published / private・im・mpim→unpublished（実 export 141/775、ADR-0013）。
- [x] files: `entity:file`（非コピー）＋`field_attachments` を migration_lookup で解決（実 export 65 file・49 node に添付）。

## More Information
- Drupal Migrate API / migrate_plus JSON source。確定仕様: `docs/spec/data-model.md`・`docs/spec/migrate.md`。関連: ADR-0004, ADR-0005, **ADR-0013**。
