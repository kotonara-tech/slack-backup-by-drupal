import { test, expect } from "@playwright/test";

/**
 * large (Playwright): /admin/export のハッピーパス。
 *
 * backend は page.route で全モック（Drupal/DDEV 不要）。
 * 観測する永続状態は idle → running → done（"queued" は POST 応答のみ）。
 * 実スタック検証は PLAYWRIGHT_BASE_URL を実サイトへ向け、route モックを外して行う。
 */
type Phase = "idle" | "running" | "done";

function statusBody(phase: Phase) {
  const channels = [
    { id: "C1", name: "general", type: "public_channel", file: "channels/C1.json" },
    { id: "C2", name: "random", type: "public_channel", file: "channels/C2.json" },
  ];
  switch (phase) {
    case "idle":
      return {
        status: "idle",
        total: 0,
        processed: 0,
        messages: 0,
        users: 0,
        channels: [],
        started_at: null,
        finished_at: null,
        last_error: null,
      };
    case "running":
      return {
        status: "running",
        total: 2,
        processed: 1,
        messages: 10,
        users: 3,
        channels: channels.slice(0, 1),
        started_at: 1717545600,
        finished_at: null,
        last_error: null,
      };
    case "done":
      return {
        status: "done",
        total: 2,
        processed: 2,
        messages: 20,
        users: 3,
        channels,
        started_at: 1717545600,
        finished_at: 1717549200,
        last_error: null,
      };
  }
}

test("管理者がトリガすると状態が idle→running→done に進む", async ({ page }) => {
  let phase: Phase = "idle";

  await page.route("**/session/token", (route) =>
    route.fulfill({ status: 200, contentType: "text/plain", body: "csrf-e2e" }),
  );
  await page.route("**/api/slack-portal/status", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify(statusBody(phase)),
    }),
  );
  await page.route("**/api/slack-portal/export", (route) => {
    // POST 応答は一回限りの ack。永続状態は同時に running へ。
    phase = "running";
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ status: "queued", queued: 2, users: 3 }),
    });
  });

  await page.goto("/admin/export");

  const badge = page.getByTestId("export-status-badge");
  await expect(badge).toHaveText("待機中");

  await page.getByRole("button", { name: /エクスポートを開始/ }).click();
  await expect(badge).toHaveText("実行中");

  // running 中の poll が次に done を拾うように phase を進める。
  phase = "done";
  await expect(badge).toHaveText("完了");
  await expect(page.getByText(/チャンネル 2 \/ 2/)).toBeVisible();

  await page.screenshot({ path: "test-results/export-done.png", fullPage: true });
});
