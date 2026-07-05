# slack-backup-by-drupal

無料版 Slack workspace に残る**直近〜90日**の全チャンネル会話・スレッド・ファイルを Slack Web API で取得し、**恒久バックアップ（正規化 JSON）＋ Drupal の DB**に保存して、**Drupal をヘッドレス backend とする社内ポータル**から閲覧・全文検索できるようにするシステム。まずはローカル完結で開発する。

> ⚠️ **無料プランの可視履歴は毎日 1 日分ずつ消えます（>1年は完全削除、公式一括エクスポートも不可）。** データ確保（実エクスポート）が最優先タスクです。→ `docs/plan/01-real-slack-export.md`

## アーキテクチャ

```
Drush コマンド (slack_portal / PHP)
  jolicode/slack-php-api + Queue/Batch + guzzle_retry_middleware
   ├─► canonical 正規化 JSON アーカイブ（private://slack_archive/、恒久バックアップ）
   └─► migrate_plus（JSON source）→ Drupal エンティティ（MariaDB）
                                      JSON:API(read-only) + Search API(DB) + jsonapi_search_api + facets
                                      ▼
                           Next.js + next-drupal + React + TS + Mantine（閲覧・全文検索）
```

- **バックエンド = PHP 一本**（Drupal 11 / Drush / PHPUnit）。Python は使わない。
- **フロント = React**（Next.js App Router / TypeScript）。
- **ランタイム = DDEV（Drupal）＋ docker-compose（frontend）**。

詳細な意思決定は `docs/adr/`、ロードマップは `docs/plan/`、開発規約は `CLAUDE.md` を参照。

## 必要なもの（ローカル開発）

- Docker（必須）
- [DDEV](https://ddev.com/)（無ければ `make setup` が導入。php/composer/drush は DDEV コンテナ内に同梱）
- Node.js 20+ / npm（frontend 用）
- pre-commit

## セットアップ

```bash
make setup        # DDEV 導入(無ければ) → ddev start → composer install → frontend npm ci → pre-commit install
```

`make setup` が以下を行う（手動なら `docs/plan/00-bootstrap.md` 参照）：

1. DDEV が無ければ導入し `ddev start`
2. `ddev composer install`（Drupal 11 core / contrib / 取得ライブラリ / dev ツール）
3. `cp web/core/phpunit.xml.dist web/phpunit.xml`（testsuites は slack_portal に限定）
4. `cd frontend && npm ci`
5. `pre-commit install --hook-type pre-commit --hook-type commit-msg --hook-type pre-push`

## 使い方

```bash
# Slack トークン（xoxp- user token）を設定（コミット禁止）
cp .env.example .env && $EDITOR .env        # SLACK_USER_TOKEN を記入（実運用は Drupal Key 推奨）

make export          # ★ 90日分を取得し canonical JSON を private://slack_archive/ へ
make migrate         # canonical JSON → Drupal エンティティ
# ポータル: https://slack-backup-by-drupal.ddev.site （JSON:API） / frontend: http://localhost:3000
```

## 開発（TDD）

t-wada 流 Red→Green→Refactor と Google テストピラミッド（small≫medium>large）で進める。

```bash
make test            # PHPUnit(Unit+Kernel) + Vitest
make ci-local        # CI 相当（push 前に必須） = /local-ci all
```

- バックエンドのテストは **PHPUnit**（`UnitTestCase`/`KernelTestBase`/`BrowserTestBase`）、静的解析は **PHPStan + PHPCS**。
- フロントは **Vitest + Playwright**、静的解析は **tsc + eslint**。
- 規約は `CLAUDE.md` と `.claude/rules/`、エージェントは `.claude/agents/`。

## ライセンス

GPL-3.0（`LICENSE`）。
