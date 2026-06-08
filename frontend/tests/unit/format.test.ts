import { describe, it, expect } from "vitest";

import { formatPostedAt } from "@/lib/format";

/**
 * small (Vitest): 投稿時刻の整形。
 * JSON:API の field_posted_at は offset 付き ISO（例 `...+09:00`）。表示は固定の
 * Asia/Tokyo・固定書式にして、CI マシンの TZ/ロケールに依存しないことを保証する。
 */
describe("formatPostedAt", () => {
  it("offset 付き ISO を YYYY/MM/DD HH:mm（JST）で整形する", () => {
    expect(formatPostedAt("2026-05-17T15:38:27+09:00")).toBe("2026/05/17 15:38");
  });

  it("UTC 入力でも JST に変換する（マシン TZ 非依存）", () => {
    expect(formatPostedAt("2026-05-01T01:00:00+00:00")).toBe("2026/05/01 10:00");
  });

  it("不正な日付文字列はそのまま返す（フォールバック）", () => {
    expect(formatPostedAt("not-a-date")).toBe("not-a-date");
  });

  it("空文字は空文字", () => {
    expect(formatPostedAt("")).toBe("");
  });
});
