import { test, expect } from "@playwright/test";

/**
 * large (Playwright): チャンネル閲覧のハッピーパス。
 *
 * backend は page.route で全モック（Drupal/DDEV 不要）。read-only JSON:API の
 * raw 応答（`{ data, included }`）を返し、チャンネル選択→スレッド閲覧→展開を辿る。
 * 実スタック検証は PLAYWRIGHT_BASE_URL を実サイトへ向け、route モックを外す。
 *
 * 注: 当環境では Chromium の OS ライブラリ（libnspr4/libnss3 等）が未導入のため
 * 実行できない（`make ci-local` の frontend ゲートにも含まれない）。M1 の
 * export.spec.ts と同様、「書く＋レビュー」で done 扱いとする。
 */
const JSONAPI = "application/vnd.api+json";

const channelsResponse = {
  data: [
    {
      type: "taxonomy_term--slack_channels",
      id: "ch-general",
      attributes: {
        drupal_internal__tid: 1,
        name: "general",
        field_slack_channel_id: "C100",
        field_channel_type: "public_channel",
      },
    },
    {
      type: "taxonomy_term--slack_channels",
      id: "ch-random",
      attributes: {
        drupal_internal__tid: 2,
        name: "random",
        field_slack_channel_id: "C200",
        field_channel_type: "public_channel",
      },
    },
  ],
};

const usersResponse = {
  data: [
    {
      type: "taxonomy_term--slack_users",
      id: "u-taro",
      attributes: {
        name: "U1",
        field_slack_user_id: "U1",
        field_real_name: "Taro Yamada",
        field_display_name: "taro",
        field_is_bot: false,
        field_avatar: "https://x/taro.png",
      },
    },
    {
      type: "taxonomy_term--slack_users",
      id: "u-hanako",
      attributes: {
        name: "U2",
        field_slack_user_id: "U2",
        field_real_name: "Hanako Sato",
        field_display_name: null,
        field_is_bot: false,
        field_avatar: "https://x/hanako.png",
      },
    },
  ],
};

function message(
  id: string,
  slackTs: string,
  threadTs: string | null,
  body: string,
  author: string | null,
  replyCount = 0,
) {
  return {
    type: "node--slack_message",
    id,
    attributes: {
      drupal_internal__nid: Number(slackTs.split(".")[0]),
      title: body.slice(0, 20),
      status: true,
      field_body: { value: body, format: "plain_text", processed: body },
      field_slack_ts: slackTs,
      field_thread_ts: threadTs,
      field_posted_at: `2026-05-0${slackTs[0]}T10:0${slackTs[5]}:00+09:00`,
      field_subtype: null,
      field_edited: false,
      field_reply_count: replyCount,
      field_reactions: "[]",
      field_slack_user_id: author,
    },
    relationships: {
      field_channel: {
        data: {
          type: "taxonomy_term--slack_channels",
          id: "ch-general",
          meta: { drupal_internal__target_id: 1 },
        },
      },
      field_slack_user: {
        data: author
          ? { type: "taxonomy_term--slack_users", id: author }
          : null,
      },
      field_attachments: { data: [] },
    },
  };
}

const messagesResponse = {
  data: [
    message("m-reply2", "100.0003", "100.0001", "ブロードキャスト返信", "u-taro"),
    message("m-reply1", "100.0002", "100.0001", "最初の返信", "u-hanako"),
    message("m-parent", "100.0001", "100.0001", "スレッドの親メッセージ", "u-taro", 2),
  ],
};

test("チャンネルを選ぶとスレッドが見え、返信を展開できる", async ({ page }) => {
  await page.route("**/jsonapi/taxonomy_term/slack_channels*", (route) =>
    route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(channelsResponse) }),
  );
  await page.route("**/jsonapi/taxonomy_term/slack_users*", (route) =>
    route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(usersResponse) }),
  );
  await page.route("**/jsonapi/node/slack_message*", (route) =>
    route.fulfill({ status: 200, contentType: JSONAPI, body: JSON.stringify(messagesResponse) }),
  );

  await page.goto("/");

  // public チャンネルが並び、初期はチャンネル未選択の案内。
  await expect(page.getByText("general")).toBeVisible();
  await expect(page.getByText("random")).toBeVisible();
  await expect(page.getByTestId("no-channel")).toBeVisible();

  // チャンネルを選ぶと親スレッドが見える。
  await page.getByText("general").click();
  await expect(page.getByTestId("browse-heading")).toContainText("general");
  await expect(page.getByText("スレッドの親メッセージ")).toBeVisible();

  // 返信トグルで展開。
  await page.getByTestId("thread-toggle-m-parent").click();
  await expect(page.getByText("最初の返信")).toBeVisible();
  await expect(page.getByText("ブロードキャスト返信")).toBeVisible();

  await page.screenshot({ path: "test-results/browse-thread.png", fullPage: true });
});
