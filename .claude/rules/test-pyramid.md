# Rule: テストピラミッド（Google SWE Book Ch.11）

目標比率（プロジェクト平均）：**small ≫ medium > large（約 80 / 15 / 5）**。層をまたぐ重複を作らない。

## size 定義
| size | 制約 | 目安時間 |
|------|------|----------|
| **small** | 単一プロセス・I/O / network / sleep / subprocess なし | < 1s |
| **medium** | localhost / DB / filesystem / subprocess 可 | < 5s |
| **large** | フルシステム・実 HTTP・E2E | < 60s |

## Backend = PHPUnit（★ pytest / FastAPI / ruff / mypy は使わない。本プロジェクトに Python は無い）
| size | 基底クラス | 用途 |
|------|-----------|------|
| small | `\Drupal\Tests\UnitTestCase` | 純ロジック（cursor 解析・canonical JSON 整形・ts→datetime・slug・429 backoff 計算）。Slack 等は Guzzle `MockHandler` でモック |
| medium | `\Drupal\KernelTests\KernelTestBase` | サービス・QueueWorker・migrate_plus・Search API 索引（DB 必要、フル install なし） |
| large | `\Drupal\Tests\BrowserTestBase` / `\Drupal\Tests\jsonapi\Functional\JsonApiFunctionalTestBase` | JSON:API read-only コレクション・`jsonapi_search_api` faceted 検索・write が 403/405 |
| large(任意) | DTT `weitzman/drupal-test-traits` `ExistingSiteBase` | 起動済みサイトへのスモーク（nightly/手動） |

- `WebDriverTestBase`（FunctionalJavascript）は**使わない**（ヘッドレス API に JS 不要）。
- ランナーは **PHPUnit 10+**（`ddev exec vendor/bin/phpunit -c web/phpunit.xml`）。レガシー `run-tests.sh` は使わない。属性は PHPUnit 10+（`#[CoversClass(...)]`）。
- 配置：`web/modules/custom/slack_portal/tests/src/{Unit,Kernel,Functional}`（任意 `ExistingSite`）。testsuites=`web/phpunit.xml`。
- 静的解析：**PHPStan**（level5, `mglaman/phpstan-drupal`）＋ **PHPCS**（`Drupal`/`DrupalPractice`）。

## Frontend = Vitest / Playwright
| size | ツール | 用途 |
|------|--------|------|
| small | Vitest + Testing Library | 関数・React コンポーネント（JSON:API 応答は fixture でモック） |
| large | Playwright | 閲覧・全文検索の E2E（主要画面 screenshot、1 happy path + 重要分岐） |

- 静的解析：`tsc --noEmit`（型）＋ eslint。

## CI 順序
- 常時：pre-commit → lint → small → medium → drupal-static / frontend-build。
- large（Functional / Playwright）は paths-filter で条件発火（backend / frontend 変更時）、main push / 手動では無条件。
