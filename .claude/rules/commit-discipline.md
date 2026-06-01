# Rule: コミット / PR 規律

## コミット
- **Conventional Commits**：`test:` / `feat:` / `fix:` / `refactor:` / `tidy:` / `docs:` / `chore:`
  - scope 例：`ingest`, `migrate`, `jsonapi`, `search`, `frontend`, `ci`, `adr`, `docs`
- **Red / Green / Refactor は別コミット**（`.claude/rules/tdd-enforcement.md`）。
- **1 commit ≤ 200 行**目安。超えるなら分割。
- ファイルは**個別指定**（`git add <path>`）。**`git add -A` / `git add .` 禁止**。
- pre-commit を**バイパスしない**（`--no-verify` 禁止）。失敗は根本原因を直す。
- 失敗コミットを `--amend` で書き換えない（線形に積む）。
- 末尾トレーラ（必須）：
  ```
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  ```

## ブランチ / push
- `main` への**直 push 禁止**。必ずブランチ → PR。
- **force-push 禁止**（`git push --force*` / `-f`）。`git reset --hard` / `git clean -fd` 禁止。
- **push 前に `/local-ci all`（= `make ci-local`）を通すこと（必須）。**

## 絶対にステージしない
- `.env` / `**/.env.*`、Slack token（`xoxp-`）、`**/slack_archive/**`、`settings.local.php`。
- `tools/check_no_archive_committed.sh` と detect-secrets が pre-commit で検出する。

## PR
- **作成時に `/review` と `/security-review` を必ず実行**し、結果を PR 本文/コメントに添付。
- PR 本文（`.github/PULL_REQUEST_TEMPLATE.md`）に：ToDo 達成状況 / 追加したテスト層（small・medium・large） / 影響 ADR（新規・改訂・なし）。
