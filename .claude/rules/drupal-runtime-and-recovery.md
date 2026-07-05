# Rule: Drupal ランタイム運用と復旧（DDEV）

本ルールは TDD/CI ゲート（`.claude/rules/test-pyramid.md` / `/local-ci`）を**置き換えない**。それらに**加えて**、稼働中サイトのランタイム健全性を扱う。すべてのコマンドは DDEV 前提。

## 1. 検証済み環境（verified environment）

| 項目 | 値 |
|------|----|
| Drupal | 11 |
| PHP | 8.3 |
| DB | MariaDB 10.11 |
| Drush | ^13 |
| Node | 22 |
| webserver | nginx-fpm |
| PHPUnit | 10+ |
| PHPStan | level 5 |
| PHPCS | `Drupal` + `DrupalPractice` |

**これらは制約値。実測はコンテナで確定せよ**：

```bash
ddev drush status                          # Drupal/PHP/DB/Drush/docroot/URI/bootstrap
ddev exec vendor/bin/phpunit --version
ddev exec vendor/bin/phpcs --version
ddev exec vendor/bin/phpstan --version
```

**主要設定ファイル**：
- `phpcs.xml`（PHPCS 標準：`Drupal`/`DrupalPractice`、対象 `web/modules/custom/slack_portal`）
- `phpstan.neon`（level 5・解析パスは `web/modules/custom/slack_portal`）
- `.tool-versions`（Node ピン、asdf 任意）
- `.ddev/config.yaml`（php/db/node バージョン）
- `docker-compose.yml`（frontend のみ; Drupal/DB は DDEV）

**サイトを開く**：
- `ddev launch`（`https://slack-backup-by-drupal.ddev.site`）
- `ddev launch /jsonapi`（JSON:API ルート）
- 管理ログインは `ddev drush uli`（ワンタイム URL。ローカル限定・共有／貼付しない）

## 2. 日常運用コマンド（day-to-day ops）

```bash
ddev drush status                                    # 稼働・bootstrap 確認
ddev drush cr                                         # キャッシュ再構築
ddev drush watchdog:show --count=20                   # 直近ログ20件
ddev drush watchdog:show --severity=Error --count=20   # エラーのみ
ddev drush config:status                              # config 差分
ddev drush pm:list --status=enabled                    # 有効モジュール一覧
ddev drush updatedb:status                             # 未適用の DB 更新
ddev drush route --path=/jsonapi                       # ルート解決確認
ddev drush sql:query "SELECT …"                         # 参照系のみ; 破壊的 SQL 禁止; 結果は PII を含み得る
ddev logs                                              # web コンテナログ
ddev logs -s db                                        # DB コンテナログ
```

`watchdog:show` は dblog 有効時のみ。無効なら `ddev logs`。

> ログ／watchdog 出力は PII（DM 本文・実名・user ID）や token を含み得る。PR・issue・スクショに貼らない（必要ならマスク）。

## 3. キャッシュ再構築の規律（cache rebuild）

以下を変更したら**必ず `ddev drush cr`**：
- PHP クラスの新規・移動・改名（サービス/プラグイン/コントローラ/QueueWorker/migrate plugin）
- `*.services.yml` / `*.routing.yml` / `*.permissions.yml` / `*.links.menu.yml`
- プラグイン定義（PHP 属性）・Twig・`*.libraries.yml`
- `config/install/*.yml` 編集後（反映にはモジュール再インストール `ddev drush pm:install slack_portal`。§4 参照。`config:import` は sync 用で config/install は読まない）

テストが緑でも実サイトは古いキャッシュで壊れて見えることがある。**構造変更＝cr**。

## 4. config ワークフロー

```bash
ddev drush config:status   # active config と sync ディレクトリの差分
ddev drush config:import   # sync ディレクトリから active へ取込
ddev drush config:export   # active を sync へ書き出し（秘匿混入に注意・下記）
```

- 本モジュールは ~60 の config を `web/modules/custom/slack_portal/config/install/` に**コード定義**（content type `slack_message`・taxonomy `slack_channels`/`slack_users`・Search API index・facets・JSON:API 設定）。
- **マイグレーション定義も `config/install` にある**（`migrate_plus.migration.slack_{channels,users,messages,files}` と group `migrate_plus.migration_group.slack_portal`）。`migrations/` ディレクトリは空（予約）。
- 実行は `ddev drush migrate:import --group=slack_portal` / `migrate:status`。
- **本モジュールの config は `config/install/` にあり、モジュールインストール時（`ddev drush pm:install slack_portal`）に active へ取り込まれる**。`config:import`／`config:export` はサイトの sync ディレクトリを対象とし、config/install は読まない。
- `config:export` は `key`／`encrypt`／`real_aes` 等の秘匿 config を書き出し得る。差分を commit 前に必ずレビューし、鍵・token を混入／コミットしない（ADR-0009・`secrets-and-pii.md`）。

## 5. 復旧プレイブック（サイトが壊れたときの直し方）

画面が真っ白 / HTTP 500 のときの手順：

1. **観測**：`ddev logs`（PHP 致命エラー・stack trace）→ 可能なら `ddev drush watchdog:show --severity=Error --count=20`。
2. **原因特定**：`git status` / `git diff` で**最後に触ったファイル**（`.module`/`*.services.yml`/`config/install/*.yml`/PHP クラス）を割り出す。
3. **最小復旧**：`git checkout -- <path>` で戻す → `ddev drush cr`（`git checkout` は当該ファイルの未コミット変更を破棄する。不安なら先に `git diff <path>`／`git stash`）。
4. **キャッシュ由来**：`ddev drush cr`。
5. **bootstrap 自体が落ちて `drush` が動かない**（`.module`/`.install` の構文致命）：該当ファイルを一時退避 → 修正 → 復帰。単一カスタムモジュールは `slack_portal`。**repo 固有の注意**：`ddev drush pm:uninstall slack_portal` は**その config（content type・index・facets）を削除する**ため安易に uninstall しない。必要時は uninstall 後 `ddev drush pm:install slack_portal` で config/install を再取込（`config:import` は sync 用で config/install を復元しない）。優先は**コード修正＋`cr`**。
6. **DB/更新起因**：`ddev drush updatedb:status` → 必要なら `ddev drush updatedb`。
7. **contrib 起因の疑い**：`ddev drush pm:list --status=enabled` で最近有効化したものを疑い切り分け（依存は `slack_portal.info.yml` の `dependencies`）。

**破壊的操作は復旧でも禁止**（`drush sql-drop`／`ddev delete`／force-push／`git reset --hard`）— `CLAUDE.md §13` と `.claude/settings.json` deny。

## 6. 完了前ランタイム検証チェックリスト

- [ ] `ddev drush status` が bootstrap 成功
- [ ] 構造変更後 `ddev drush cr` 実行済
- [ ] `ddev drush watchdog:show --severity=Error` に新規エラーなし（または `ddev logs` に致命なし）
- [ ] `ddev drush config:status` が想定どおり
- [ ] `ddev drush updatedb:status` に未適用なし
- [ ] （API 変更時）`ddev launch /jsonapi` かコレクション URL が期待レスポンス

これは `/local-ci all`（`test-pyramid.md`）の**代替ではない**。緑を確認した上で実行時健全性も見る。

## 7. Drupal 11 コーディング作法（phpcs で強制・明文化）

- マシン名 lower_snake / namespace `Drupal\slack_portal\…`（PSR-4, `src/`）
- コンストラクタ DI（`\Drupal::service()` 直呼びを避ける）
- 出力はレンダー配列＋`#cache`（contexts/tags/max-age）
- プラグイン・フック発見は PHP 属性 `#[...]` を優先（アノテーション非推奨）
- 準拠検証は `ddev exec vendor/bin/phpcs --standard=phpcs.xml web/modules/custom/slack_portal`

## 8. contrib 優先（車輪の再発明をしない）

- 新機能着手前に、まず **drupal.org の公開 contrib モジュール**および既存 `composer.json` 依存で要件を満たせないか評価する。保守された contrib があれば**それを採用**し、カスタム実装（`slack_portal`）は contrib で賄えない／要件不一致の場合に限る。
- 追加は `ddev composer require drupal/<module>`。採用時は**保守状況・セキュリティ勧告(SA)対応・Drupal 11 互換・ライセンス(GPL)**を確認。有効化は `ddev drush pm:install <module>` → `ddev drush cr`。
- 本repo は既に contrib を厚く採用（`migrate_plus`・`migrate_tools`・`search_api`・`jsonapi_search_api`・`jsonapi_extras`・`facets`・`key`・`encrypt`・`real_aes`・`decoupled_router`）。カスタムコードは Slack 取得・正規化・移行の**本repo 固有ロジックに限定**する。

## 関連

- `../../CLAUDE.md`
- `test-pyramid.md`
- `../skills/local-ci/SKILL.md`
- `../../web/modules/custom/slack_portal/README.md`
