# CLAUDE.md — slack-backup-by-drupal 開発規約

Coding Agent（Claude Code）が**自走**で開発するための最上位規約。`.claude/rules/` を正典として参照し、矛盾する場合は本ファイル → `.claude/rules/` → 各コンポーネントの `CLAUDE.md` の順に優先する。

## 1. プロジェクト概要

無料版 Slack workspace に残る**直近〜90日**の全チャンネル会話・スレッド・ファイルを Slack Web API で取得し、**恒久バックアップ（正規化 JSON）＋ Drupal の DB（MariaDB）**に保存して、Drupal をヘッドレス backend とする社内ポータルから**閲覧・全文検索**できるシステム。まずは**ローカル完結**、後でレンタルサーバ／IaaS（Drupal の MariaDB がそのまま搬送対象の DB）。

| 層 | スタック |
|----|----|
| 取得（ingest） | **PHP / Drush コマンド** ＋ Drupal **Queue/Batch API** ＋ `jolicode/slack-php-api` ＋ `caseyamcl/guzzle_retry_middleware` |
| バックアップ正典 | **canonical 正規化 JSON**（`public://slack_archive/`、gitignore） |
| 保存 DB | **Drupal エンティティ（MariaDB）**、`migrate_plus`(JSON source) で冪等取込 |
| API | **Drupal 11 / PHP 8.3** — JSON:API(read-only) ＋ Search API(DB backend) ＋ `jsonapi_search_api` ＋ facets ＋ `jsonapi_extras` ＋ CORS |
| フロント | **Next.js 15 (App Router) ＋ next-drupal ＋ React ＋ TypeScript ＋ Mantine ＋ TanStack Query ＋ drupal-jsonapi-params** |
| ランタイム | **DDEV**（Drupal/MariaDB/web/Drush）＋ `docker-compose`（frontend）|

> **バックエンドは PHP 一本**。Python・pytest・ruff・mypy・別 raw DB は**採用しない**。フロントは TypeScript/React。

## 2. 開発哲学：TDD First

t-wada / Kent Beck 流の **Red → Green → Refactor** を全コンポーネントに課す。

```
🔴 Red      失敗するテストを「先」に書く（未実装の関数/振る舞いを参照）。実装は書かない。
🟢 Green    テストを通す「最小」の実装。過剰設計しない。テストを通すためにテストを書き換えない。
🔵 Refactor テスト緑のまま構造改善（重複除去・命名・抽出）。振る舞いは変えない。
```

詳細は `.claude/rules/tdd-enforcement.md`。**振る舞いの変更と構造の変更を同じ commit に混ぜない。**

## 3. TDD ワークフロー：ToDo リストから始める

- 進捗の真実源は Plan ファイル `~/.claude/plans/<slug>.md`（リポ外）。
- Plan は `- [ ]` チェックボックスの ToDo で始める。**1 ToDo = 1 BDD シナリオ = 1 Red サイクル**。
- 完了で `- [x]`。ロードマップの正本は `docs/plan/`。

## 4. BDD によるテスト記述

各 ToDo は Given-When-Then で記述。

- **Backend (PHPUnit)**：テストメソッド名・docstring で振る舞いを語る（例 `testReturns403OnWrite`）。Given-When-Then をコメント/Arrange-Act-Assert で表現。
- **Frontend (Vitest/Playwright)**：`describe`/`it('...returns... when...')` で振る舞いを記述。

## 5. テストピラミッド（Google SWE Book Ch.11, small≫medium>large, 約 80/15/5）

| size | Backend（PHPUnit） | Frontend |
|------|----|----|
| **small** | `UnitTestCase`（I/O・network・sleep なし、<1s。純ロジック。Slack は Guzzle `MockHandler` でモック） | Vitest（コンポーネント/関数） |
| **medium** | `KernelTestBase`（DB・サービス・migrate・Search API 索引、localhost） | — |
| **large** | `BrowserTestBase` / `JsonApiFunctionalTestBase`（フル Drupal・実 HTTP・JSON:API/検索 E2E）／任意 DTT `ExistingSiteBase` | Playwright（E2E） |

詳細・基底クラス対応は `.claude/rules/test-pyramid.md`。**Drupal/PHP に pytest・ruff・mypy を持ち込まない。**

## 6. モデル使い分け

| 用途 | モデル |
|----|----|
| 親会話（オーケストレーション・修正・判断） | **常に Opus** |
| 実装（Red-Green-Refactor 実行） | **Sonnet**（`drupal-backend-implementer` / `frontend-implementer`） |
| 設計・テストレビュー・失敗分析・文書/ADR | **Opus**（`test-designer` / `test-reviewer` / `failure-analyst` / `documentor`） |

## 7. サブエージェント（`.claude/agents/`）

| agent | model | 役割 |
|----|----|----|
| `test-designer` | opus | 要件→Given-When-Then ToDo を Plan へ追記、small/medium/large 付与、実装 agent へルーティング |
| `drupal-backend-implementer` | sonnet | **PHP/Drupal 専任**の 1 サイクル実装（PHPUnit→PHPStan/PHPCS） |
| `frontend-implementer` | sonnet | **Next.js/React/TS 専任**の 1 サイクル実装（Vitest/Playwright→tsc/eslint） |
| `test-reviewer` | opus | 両スタック横断のテスト品質・ピラミッド適合・flakiness 監査（実装変更なし） |
| `failure-analyst` | opus | 赤テスト/静的解析の根本原因分析（編集なし） |
| `documentor` | opus | ADR(MADR)・README・CHANGELOG・docs/plan・各 CLAUDE.md・Diátaxis を所有（コード非干渉） |

## 8. ADR 運用

- 形式：**MADR**（軽量・Markdown）。場所 `docs/adr/NNNN-kebab-case.md`（4 桁ゼロ詰め）。**< 200 行**。
- Status：`proposed` → `accepted` → `superseded`/`deprecated`。
- **承認済み ADR は書き換えない。** 変更時は新 ADR を起こし `Supersedes: ADR-NNNN`、旧を `superseded` にして `Superseded by: ADR-XXXX` を追記。
- 起草/改訂は `documentor`（Opus）が担当。テンプレは `docs/adr/template.md`。

## 9. `.claude/rules/`（正典）

- `tdd-enforcement.md` — Red-Green-Refactor の厳守とアンチパターン
- `test-pyramid.md` — size 定義・PHPUnit 基底クラス対応・marker
- `commit-discipline.md` — コミット/PR 規律
- `secrets-and-pii.md` — token/PII/アーカイブの取扱
- `slack-export-safety.md` — Slack 取得の安全・90日緊急性・レート制限

## 10. ディレクトリ規約

- 取得＋ポータルは単一モジュール `web/modules/custom/slack_portal/`（ingest は `src/Service`・`src/Drush`・`src/Plugin/QueueWorker`、portal は `config/install`・`migrations`・`src/Plugin/migrate`）。
- テスト：`web/modules/custom/slack_portal/tests/src/{Unit,Kernel,Functional}`（任意 `ExistingSite`）。
- フロント：`frontend/`（`app/`・`lib/`・`tests/{unit,e2e}`）。
- ドキュメント：`docs/adr`・`docs/plan`・`docs/{tutorials,how-to,reference,explanation}`（Diátaxis）。

## 11. コマンド早見表（**すべて DDEV 前提**）

```bash
make setup                 # DDEV 導入(無ければ)→ddev start→composer install→frontend npm ci→pre-commit install
make test                  # PHPUnit(Unit+Kernel) + Vitest
make phpunit               # ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Unit,Kernel
make phpstan               # ddev exec vendor/bin/phpstan analyze web/modules/custom/slack_portal -l 5
make phpcs / make phpcbf   # コーディング規約チェック / 自動修正
make test-frontend         # cd frontend && npx vitest run
make ci-local              # CI 相当（= /local-ci all）
make export                # ★ ddev drush slack:export --since=90d （実 Slack 取得）
make migrate               # ddev drush migrate:import --group=slack_portal
```

`/local-ci all` は push 前に**必ず**通すこと（§12）。

## 12. コミット / PR 規約

- **Conventional Commits**：`test:` / `feat:` / `fix:` / `refactor:` / `tidy:` / `docs:` / `chore:`（scope 例：`ingest`,`migrate`,`jsonapi`,`search`,`frontend`,`ci`,`adr`）。
- Red / Green / Refactor は**別コミット**。**1 commit ≤ 200 行**目安。
- コミット末尾に必須トレーラ：
  ```
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  ```
- **禁止**：`git add -A`（ファイル個別指定）／`--no-verify`／`--amend` での失敗コミット改変／`git push --force*`／`main` への直 push（PR 経由）。
- **push 前に `/local-ci all` 通過必須**。
- **PR 作成時に `/review` と `/security-review` を必ず実行**し、結果を PR 本文/コメントに添付。PR 本文には ToDo 達成状況・影響 ADR・追加したテスト層を記す。

## 13. 重要な制約・禁止事項

- 編集不可：`.env`・`**/.env.*`・`~/.ssh/*`・`~/.claude/settings.json`（global）・`.secrets.baseline`（人間レビュー）。
- **canonical アーカイブ（`public://slack_archive/`）と Slack token（`xoxp-`）を絶対に commit / ログ出力しない。** token は Drupal Key モジュール or `settings.local.php`/env で管理（`.claude/rules/secrets-and-pii.md`）。
- **90日 erosion**：無料 Slack の可視履歴は毎日消える。実エクスポート（`docs/plan/01-real-slack-export.md`）が最優先。
- 破壊的操作（force-push / hard reset / broad rm / `drush sql-drop` / `ddev delete`）は不可（`.claude/settings.json` で deny）。
