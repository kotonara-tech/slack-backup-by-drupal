---
status: proposed
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
採用: **Option 2**。`slack_message` content type（body・field_channel 参照・field_slack_user_id・field_slack_ts(一意)・field_posted_at・field_reactions・添付）＋ channel は taxonomy（`slack_channels`）＋ user/file は参照。`migrate_plus`/`migrate_tools` の JSON source で canonical JSON から取込（`ids` に field_slack_ts → 冪等）。channels→users→messages→files の依存順。

## Consequences
### Positive
- ノードは JSON:API/Search API と相性良・性能良。再実行で重複しない。
### Negative
- スレッド/リアクションのモデリングに工夫が要る（thread_ts による親子、reactions の保持方法）。

## Confirmation
- [ ] Kernel: JSON→`slack_message` 生成、2 回実行で件数不変（冪等）。

## More Information
- Drupal Migrate API / migrate_plus JSON source。関連: ADR-0004, ADR-0005。
