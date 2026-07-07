import { defineConfig, devices } from "@playwright/test";

// large 層（E2E）。seed 済み Drupal + frontend に対して実行。
export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? "github" : "list",
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "http://localhost:3000",
    trace: "on-first-retry",
    // opt-in: サンドボックス等で `playwright install` の期待 rev が無い環境向けに、
    // 実際に導入済みの Chromium バイナリを指す。未設定なら従来どおり playwright
    // 管理下のバイナリを使う（CI の挙動は変えない）。
    launchOptions: {
      executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
    },
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
  // frontend dev サーバを自動起動（既存があれば再利用）。E2E はモック（page.route）で
  // backend を差し替えるため Drupal は不要。実スタック検証時は PLAYWRIGHT_BASE_URL を上書きする。
  webServer: {
    command: "npm run dev",
    url: "http://localhost:3000",
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
