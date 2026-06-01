# 00. Bootstrap（土台＋骨組み）— 本フェーズ

エージェント自走開発の土台と空のプロジェクト骨組みを用意する。**機能コードは書かない**（最初の Red は Milestone 01）。

## 完了の定義（土台）
- [x] 統治：`CLAUDE.md`、`.claude/{settings.json, agents/*, rules/*, skills/*}`
- [x] dev-env：`Makefile`、`docker-compose.yml`（frontend）、`.pre-commit-config.yaml`、`.github/workflows/ci.yml`、`.github/PULL_REQUEST_TEMPLATE.md`、`.env.example`、`.tool-versions`、`.mcp.json.example`
- [x] ガード：`tools/{check_tdd.sh, check_no_archive_committed.sh}`
- [x] docs：`docs/adr`（template＋README＋0001–0011）、`docs/plan`（本ファイル＋01–05）、`docs/{tutorials,how-to,reference,explanation}`、`CHANGELOG.md`
- [x] Drupal 骨格：`.ddev/config.yaml`、`composer.json`、`phpstan.neon`、`phpcs.xml`、`web/phpunit.xml`、`web/modules/custom/slack_portal/`（info.yml/.module/.install/.services.yml/README＋空ディレクトリ）
- [x] frontend 骨格：`frontend/`（package.json/tsconfig/next.config/vitest/playwright/Dockerfile/app/lib/CLAUDE.md）

## セットアップで実体化（DDEV 必須）
```bash
make setup     # DDEV 導入(無ければ)→ddev start→ddev composer install→cp phpunit.xml→frontend npm ci→pre-commit install
```

## 検証（機能ゼロで健全性確認）
```bash
pre-commit run --all-files
ddev start && ddev drush status
ddev exec vendor/bin/phpunit -c web/phpunit.xml --list-suites
ddev exec vendor/bin/phpstan analyze web/modules/custom/slack_portal -l 5
ddev exec vendor/bin/phpcs --standard=phpcs.xml web/modules/custom/slack_portal
ddev composer validate --no-check-publish
cd frontend && npm run typecheck && npm run lint && npm run build
docker compose config
```

## 次
→ **01-real-slack-export.md（最優先）**
