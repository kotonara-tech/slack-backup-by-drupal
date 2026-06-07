---
title: private:// ファイルシステムの設定方法
diataxis: how-to
related:
  - docs/adr/0014-canonical-archive-private-stream.md
  - docs/spec/jsonapi-search.md
---

# private:// ファイルシステムの設定方法

## 概要

canonical アーカイブ（[ADR-0014](../adr/0014-canonical-archive-private-stream.md)）は `private://slack_archive/latest/` に保存される。`private://` は Drupal の**プライベートファイルシステム**であり、web root 外のディレクトリに解決される。nginx / Apache がファイルを直接配信できないため、PII を含む JSON や添付ファイルが Web に露出しない。

## 設定手順

### 1. プライベートディレクトリを用意する

**web root の外**（例: `web/` の兄弟ディレクトリ）にディレクトリを作成する。

```bash
mkdir -p /path/to/project/private
```

DDEV 環境では `/var/www/html/private` がよく使われる（`/var/www/html/web` が docroot）。

### 2. `settings.local.php` に設定を追加する

```php
// settings.local.php（gitignore 済み）
$settings['file_private_path'] = '/var/www/html/private';
```

DDEV の場合、`.ddev/config.yaml` に環境変数として設定するか、`settings.local.php` へ直接記述する。

```bash
# DDEV の場合（コンテナ内パス）
$settings['file_private_path'] = '/var/www/html/private';
```

> `settings.local.php` は gitignore 済み（シークレット・環境固有設定を含むため）。`$settings['file_private_path']` を `settings.php` に書かないこと。

### 3. キャッシュをクリアして確認する

```bash
ddev drush cr
ddev drush php-eval "echo \Drupal::service('file_system')->realpath('private://') . PHP_EOL;"
```

`/var/www/html/private` のようなパスが返れば設定済み。

## なぜ private:// が必要か

- `public://` は Drupal のパブリックファイルシステム（通常 `web/sites/<site>/files/`）に解決される。nginx はこのディレクトリのファイルを**直接配信**する。
- `slack_archive/` には PII（メッセージ本文・実名・ユーザ ID）と添付ファイルが含まれる。nginx の `location` ルールで特定サブディレクトリを選択遮断することも可能だが、nginx 設定変更漏れによる露出リスクがある（[ADR-0014](../adr/0014-canonical-archive-private-stream.md) 参照）。
- `private://` に置くことで、**すべてのアクセスが Drupal の `hook_file_download()` を経由**する。添付ファイルは参照している `slack_message` node の閲覧権限に基づき配信される（詳細は [docs/spec/jsonapi-search.md §6.3](../spec/jsonapi-search.md)）。

## git 管理

`private/` ディレクトリは `.gitignore` に追加する（canonical アーカイブは commit 禁止）。DDEV の場合、`web/sites/*/files` と `private/` がそれぞれ gitignore 済みであることを確認する。

## 関連

- [ADR-0014 — canonical アーカイブの private:// 移行](../adr/0014-canonical-archive-private-stream.md)
- [docs/spec/jsonapi-search.md §6.4](../spec/jsonapi-search.md) — ファイルアクセス制御
- `.claude/rules/secrets-and-pii.md` — PII / secrets の取扱規約
