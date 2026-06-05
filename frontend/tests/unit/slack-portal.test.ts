import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  getCsrfToken,
  triggerExport,
  fetchExportStatus,
} from "@/lib/slack-portal";
import { DRUPAL_BASE_URL } from "@/lib/drupal";

/**
 * small (Vitest): Slack Portal の API クライアント関数。
 * backend 契約は docs/spec/portal-api.md を正典とする。
 * global fetch をモックして CSRF→POST/GET の挙動を検証する。
 */
describe("slack-portal API client", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it("getCsrfToken は credentials 付きで Drupal session token を取得する", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response("csrf-abc", { status: 200 }));
    vi.stubGlobal("fetch", fetchMock);

    const token = await getCsrfToken();

    expect(token).toBe("csrf-abc");
    expect(fetchMock).toHaveBeenCalledWith(
      `${DRUPAL_BASE_URL}/session/token`,
      expect.objectContaining({ credentials: "include" }),
    );
  });

  it("getCsrfToken は session token 取得が失敗したら throw する", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response("", { status: 403 }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(getCsrfToken()).rejects.toThrow();
  });

  it("triggerExport は CSRF ヘッダ付きで POST し queued 結果を返す", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response("csrf-xyz", { status: 200 }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({ status: "queued", queued: 3, users: 5 }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    const result = await triggerExport();

    expect(result).toEqual({ status: "queued", queued: 3, users: 5 });
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toBe(`${DRUPAL_BASE_URL}/api/slack-portal/export`);
    expect(init.method).toBe("POST");
    expect(init.credentials).toBe("include");
    expect(
      (init.headers as Record<string, string>)["X-CSRF-Token"],
    ).toBe("csrf-xyz");
  });

  it("triggerExport は 500 のときサニタイズ済みメッセージで throw する", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response("csrf-xyz", { status: 200 }))
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            status: "error",
            message: "Slack user token is not configured.",
          }),
          { status: 500, headers: { "Content-Type": "application/json" } },
        ),
      );
    vi.stubGlobal("fetch", fetchMock);

    await expect(triggerExport()).rejects.toThrow(
      "Slack user token is not configured.",
    );
  });

  it("fetchExportStatus は status JSON を credentials 付きで取得する", async () => {
    const status = {
      status: "running",
      total: 4,
      processed: 1,
      messages: 10,
      users: 5,
      channels: [],
      started_at: 1717545600,
      finished_at: null,
      last_error: null,
    };
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify(status), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
      );
    vi.stubGlobal("fetch", fetchMock);

    const result = await fetchExportStatus();

    expect(result).toEqual(status);
    expect(fetchMock).toHaveBeenCalledWith(
      `${DRUPAL_BASE_URL}/api/slack-portal/status`,
      expect.objectContaining({ credentials: "include" }),
    );
  });
});
