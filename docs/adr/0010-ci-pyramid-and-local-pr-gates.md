---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0010: CI（ピラミッド順）と local-ci / PR ゲート

## Context and Problem Statement
CI は全テストを実行したいが、重い E2E（Playwright / Functional）を毎 PR で回すと遅い。push 前のローカル検証と PR レビューの運用も決めたい。

## Decision Drivers
- 速い PR フィードバック。pyramid 順。ローカルと CI の一致。レビューの強制。

## Considered Options
1. 毎回全テスト（遅い）
2. **常時は pre-commit→lint→small→medium＋drupal-static＋frontend-build。large E2E は paths-filter で条件発火。push 前 `/local-ci all`、PR 時 `/review`＋`/security-review` 必須**

## Decision Outcome
採用: **Option 2**。
- **常時**：`pre-commit` → lint（fetcher なし。Drupal=PHPStan/PHPCS、frontend=tsc/eslint）→ `drupal-phpunit`(Unit+Kernel) ＋ `frontend-build`(build+vitest)。
- **条件発火（large）**：`dorny/paths-filter` で `frontend-e2e`(Playwright)＝`frontend/**`、`backend-e2e`(Functional)＝`web/modules/custom/slack_portal/**`・`composer.json`・migrations・config/install 変更時。main push / `workflow_dispatch` では無条件。
- Drupal ジョブは DDEV-in-CI（`ddev/github-action-setup-ddev`）。
- **push 前に `/local-ci all`（= `make ci-local`）通過必須**。**PR 作成時に `/review` と `/security-review` を必ず実行**。

## Consequences
### Positive
- PR が速い。該当領域変更時のみ E2E。ローカルと CI 一致。
### Negative
- paths-filter の設定漏れに注意（フィルタの網羅性を維持）。

## Confirmation
- [x] `make ci-local` が全ステージ green（pre-commit → PHPStan → PHPCS → PHPUnit Unit+Kernel 156/1138 assert → frontend tsc/eslint/vitest 92/build、2026-06-16）。CI(main) は run 27562781222 success、E2E（Playwright/Functional）は paths-filter で該当変更時のみ発火。

## More Information
- `.claude/skills/local-ci/SKILL.md`、`.claude/rules/commit-discipline.md`。関連: ADR-0001, ADR-0011。
