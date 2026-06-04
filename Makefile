# slack-backup-by-drupal — 開発タスク（すべて DDEV 前提）
# 使い方: make help
.DEFAULT_GOAL := help
SHELL := /bin/bash
MODULE := web/modules/custom/slack_portal

.PHONY: help setup ddev-up test test-fast phpunit phpunit-functional phpstan phpcs phpcbf \
        test-frontend frontend-build lint typecheck ci-local export migrate migrate-status clean

help: ## このヘルプを表示
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

setup: ## DDEV 導入(無ければ)→start→composer install→frontend npm ci→pre-commit install
	@command -v ddev >/dev/null 2>&1 || curl -fsSL https://ddev.com/install.sh | bash
	ddev start
	ddev composer install
	@test -f web/phpunit.xml || cp web/core/phpunit.xml.dist web/phpunit.xml
	cd frontend && (npm ci || npm install)   # 初回(lock 無し)は install で lock を生成
	pre-commit install --hook-type pre-commit --hook-type commit-msg --hook-type pre-push

ddev-up: ## DDEV を起動
	ddev start

test: phpunit test-frontend ## PHPUnit(Unit+Kernel) + Vitest

test-fast: ## PHPUnit Unit のみ（最速ゲート）
	ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Unit

phpunit: ## PHPUnit Unit+Kernel
	ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Unit,Kernel

phpunit-functional: ## PHPUnit Functional（large / JSON:API・検索 E2E）
	ddev exec vendor/bin/phpunit -c web/phpunit.xml --testsuite Functional

phpstan: ## PHPStan（level 5）
	ddev exec vendor/bin/phpstan analyze $(MODULE) -l 5

phpcs: ## PHPCS（Drupal / DrupalPractice）
	ddev exec vendor/bin/phpcs --standard=phpcs.xml $(MODULE)

phpcbf: ## PHPCS 自動修正
	ddev exec vendor/bin/phpcbf --standard=phpcs.xml $(MODULE)

test-frontend: ## Vitest
	cd frontend && npm test

frontend-build: ## Next.js ビルド
	cd frontend && npm run build

lint: phpcs ## 静的解析: PHPCS + frontend(tsc/eslint)
	cd frontend && npm run typecheck && npm run lint

typecheck: ## frontend 型チェック
	cd frontend && npm run typecheck

ci-local: ## CI 相当をローカルで実行（push 前に必須） = /local-ci all
	pre-commit run --all-files
	$(MAKE) phpstan phpcs phpunit
	cd frontend && npm run typecheck && npm run lint && npm test && npm run build

export: ## ★ 実 Slack 取得（90日分 → canonical JSON）
	ddev drush slack:export --since=$${SLACK_EXPORT_SINCE_DAYS:-90}

migrate: ## canonical JSON → Drupal エンティティ
	ddev drush migrate:import --group=slack_portal

migrate-status: ## マイグレーション状況
	ddev drush migrate:status --group=slack_portal

clean: ## キャッシュ類を削除
	rm -rf .phpunit.cache frontend/.next frontend/node_modules/.cache
