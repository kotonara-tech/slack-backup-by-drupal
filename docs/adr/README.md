# Architecture Decision Records (ADR)

このディレクトリは MADR（Markdown ADR）形式で意思決定を記録する。`documentor` エージェントが管理する。

## ルール
- ファイル名：`NNNN-kebab-case.md`（4 桁ゼロ詰め・連番・再利用しない）。テンプレは `template.md`。
- 1 ADR は **< 200 行**。1 決定 = 1 ADR。
- Status：`proposed` → `accepted` → `superseded` / `deprecated`。
- **承認済み ADR は書き換えない。** 変更時は新 ADR を起こし `Supersedes: ADR-NNNN`、旧を `superseded` にして `Superseded by: ADR-XXXX` を追記。
- ADR には出典 URL を明記する。

## 索引

| # | タイトル | Status |
|---|---------|--------|
| [0001](0001-tdd-bdd-test-pyramid-methodology.md) | TDD/BDD と Google テストピラミッドを開発方法論として採用 | proposed |
| [0002](0002-monorepo-layout-and-ddev-runtime.md) | モノレポ配置（slack_portal ＋ frontend）と DDEV ランタイム | proposed |
| [0003](0003-slack-acquisition-php-native.md) | Slack 取得を PHP/Drupal ネイティブで実装 | proposed |
| [0004](0004-storage-canonical-json-and-mariadb.md) | 保存形式＝canonical JSON ＋ MariaDB（別 raw DB 不採用） | proposed |
| [0005](0005-headless-drupal-jsonapi-search.md) | ヘッドレス Drupal API（JSON:API read-only ＋ Search API DB） | proposed |
| [0006](0006-slack-to-drupal-data-model-and-migrate.md) | Slack→Drupal データモデルと Migrate 取込 | proposed |
| [0007](0007-nextjs-next-drupal-react-frontend.md) | Next.js + next-drupal + React フロントエンド | proposed |
| [0008](0008-internal-portal-auth-and-cors.md) | 社内ポータルの auth / CORS | proposed |
| [0009](0009-secrets-and-pii-handling.md) | secrets / PII の取扱 | proposed |
| [0010](0010-ci-pyramid-and-local-pr-gates.md) | CI（ピラミッド順）と local-ci / PR ゲート | proposed |
| [0011](0011-drupal-api-test-toolchain.md) | Drupal API テストツールチェーン（PHPUnit/PHPStan/PHPCS） | proposed |
