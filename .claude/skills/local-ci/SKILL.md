---
name: local-ci
description: CI を手元で再現する。push 前に `all` を必ず通す。サブコマンド pre-commit | lint | drupal | frontend | e2e | all。Drupal は PHPStan+PHPCS+PHPUnit(Unit+Kernel)、frontend は tsc+eslint+vitest+build。
---

# /local-ci

GitHub Actions の CI をローカルで再現する。**push 前に `/local-ci all`（= `make ci-local`）を必ず通す**こと。

## 使い方
`/local-ci [job]`：
- `pre-commit` … `pre-commit run --all-files`
- `lint` … Drupal: `ddev exec vendor/bin/phpcs --standard=phpcs.xml web/modules/custom/slack_portal` ＋ frontend: `cd frontend && npm run typecheck && npm run lint`
- `drupal` … `ddev exec vendor/bin/phpstan analyze web/modules/custom/slack_portal -l 5` ＋ PHPCS ＋ `ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Unit,Kernel`
- `frontend` … `cd frontend && npm run typecheck && npm run lint && npx vitest run && npm run build`
- `e2e` … （変更がある層のみ）`ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Functional` ／ `cd frontend && npx playwright test`
- `all`（既定） … 上を CI と同順で実行：pre-commit → lint → drupal → frontend →（変更があれば）e2e

## 出力
- 各 job を Markdown 表で要約（job / status / duration / 件数）。失敗時は stderr 末尾 200 行。
- `--down` 指定時、最後に frontend compose を停止（`docker compose down`）。

## 禁止
- force-push / commit / `--no-verify`。DDEV 未導入なら導入を促す（`make setup`）。
