---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0011: Drupal API テストツールチェーン（PHPUnit/PHPStan/PHPCS）

## Context and Problem Statement
Drupal で構築する API のテスト/静的解析の道具立てを定める。他のチームリポは FastAPI(Python/pytest) だが、本プロジェクトは PHP/Drupal であり混同してはならない。

## Decision Drivers
- Drupal ネイティブのベストプラクティス。JSON:API/検索の検証。Python ツールを持ち込まない。

## Considered Options
1. pytest 等 Python ツール（誤り：別言語）
2. レガシー `run-tests.sh`
3. **PHPUnit 10+（Unit/Kernel/Functional）＋ JSON:API は JsonApiFunctionalTestBase ＋ 任意 DTT ＋ PHPStan(mglaman) ＋ PHPCS(coder)**

## Decision Outcome
採用: **Option 3**。
- ランナー＝**PHPUnit 10+**（`run-tests.sh` 不使用）。`web/phpunit.xml`（core dist 複製、testsuites=Unit/Kernel/Functional を slack_portal に限定）。実行は `ddev exec vendor/bin/phpunit`。
- 基底クラス：`UnitTestCase`=small / `KernelTestBase`=medium / `BrowserTestBase`・`\Drupal\Tests\jsonapi\Functional\JsonApiFunctionalTestBase`=large。外部 HTTP は Guzzle `MockHandler`。任意で DTT `ExistingSiteBase`（nightly スモーク）。`WebDriverTestBase` は不使用。
- 静的解析：**PHPStan**（`mglaman/phpstan-drupal`, level5, `phpstan.neon`）＋ **PHPCS**（`drupal/coder`: Drupal/DrupalPractice, `phpcs.xml`）。**Behat は不採用**。
- **pytest / ruff / mypy / FastAPI を Drupal に持ち込まない。**

## Consequences
### Positive
- Drupal 標準に準拠。JSON:API/検索を実 HTTP で検証。型安全/規約を機械担保。
### Negative
- Functional は重い（CI では条件発火: ADR-0010）。

## Confirmation
- [x] `ddev exec vendor/bin/phpunit -c web/phpunit.xml --list-suites` が Unit 80 / Kernel 76 / Functional 15 を認識。`make phpstan`（level5・71 ファイル）／`make phpcs`（138 ファイル）ともに 0 error（2026-06-16）。

## More Information
- Drupal「Types of tests」/ PHPUnit in Drupal / mglaman/phpstan-drupal / drupal/coder / weitzman/drupal-test-traits。関連: ADR-0001, ADR-0005, ADR-0010。
