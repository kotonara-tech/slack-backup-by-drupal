---
status: proposed
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0002: モノレポ配置（slack_portal ＋ frontend）と DDEV ランタイム

## Context and Problem Statement
取得・取込・API・検索（バックエンド）とポータル UI（フロント）を 1 リポで開発し、ローカル完結で動かしたい。既存 `.gitignore` は Drupal composer "recommended-project"（`/web/core` 等）を前提。

## Decision Drivers
- coding agent の認知負荷を下げる単純な配置。
- ローカル再現性。Drupal の標準的なローカル基盤。

## Considered Options
1. 複数リポ（backend / frontend 分離）
2. 単一リポ・全部 docker-compose 手組み
3. **単一リポ：Drupal=DDEV、frontend=docker-compose（または host）。取得＋API は単一モジュール `slack_portal` に集約**

## Decision Outcome
採用: **Option 3**。リポ直下に Drupal recommended-project（`web/`）、**単一モジュール `web/modules/custom/slack_portal/`（同一モジュール内でディレクトリ分離：ingest は `src/Service`・`src/Drush`・`src/Plugin/QueueWorker`、portal は `config/install`・`migrations`・`src/Plugin/migrate`）**、`frontend/`。**Drupal は DDEV**（php/composer/drush をコンテナ同梱）、frontend は最小 `docker-compose.yml`（または `npm run dev`）。CLAUDE.md §10 と整合。

## Consequences
### Positive
- 既存 `.gitignore` と整合。`ddev` でホスト依存が Docker のみ。1 リポで完結。
### Negative
- DDEV 導入が前提（未導入だと backend 作業が始められない）。
- ingest と portal が同一モジュール（肥大化時は分割を再検討）。

## Confirmation
- [ ] `ddev start && ddev drush status` が成功。`docker compose config` がパース。

## More Information
- 関連: ADR-0005, ADR-0007。肥大化時 `slack_ingest` 分割を superseding ADR で。
