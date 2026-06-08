import { describe, it, expect } from "vitest";

import { groupIntoThreads } from "@/lib/threads";
import { sampleMessages } from "../fixtures/jsonapi";

/**
 * small (Vitest): メッセージ配列をスレッドへ再構成する純関数。
 * 親（thread_ts===slack_ts または thread_ts=null）を root に、返信を thread_ts で束ね、
 * 親不在の orphan reply も単独 thread として救済する（データ欠落ゼロ）。
 */
describe("groupIntoThreads", () => {
  it("親＋返信を thread_ts でまとめ、返信は時系列昇順", () => {
    const threads = groupIntoThreads(sampleMessages);
    const parent = threads.find((t) => t.root.id === "m-parent");
    expect(parent?.replies.map((r) => r.id)).toEqual(["m-reply1", "m-reply2"]);
    expect(parent?.replyCount).toBe(2);
  });

  it("thread_broadcast も親スレッドの返信に含める", () => {
    const parent = groupIntoThreads(sampleMessages).find(
      (t) => t.root.id === "m-parent",
    );
    expect(parent?.replies.some((r) => r.subtype === "thread_broadcast")).toBe(
      true,
    );
  });

  it("standalone は返信なしの単独スレッドになる", () => {
    const bot = groupIntoThreads(sampleMessages).find(
      (t) => t.root.id === "m-bot",
    );
    expect(bot?.replies).toEqual([]);
  });

  it("orphan reply（親不在）は単独スレッドとして救済する（欠落ゼロ）", () => {
    const threads = groupIntoThreads(sampleMessages);
    const orphan = threads.find((t) => t.root.id === "m-orphan");
    expect(orphan).toBeDefined();
    expect(orphan?.replies).toEqual([]);
    const total = threads.reduce((n, t) => n + 1 + t.replies.length, 0);
    expect(total).toBe(sampleMessages.length);
  });

  it("root は新しい順（postedAt 降順）に並ぶ", () => {
    const threads = groupIntoThreads(sampleMessages);
    expect(threads.map((t) => t.root.id)).toEqual([
      "m-orphan",
      "m-bot",
      "m-standalone",
      "m-parent",
    ]);
  });

  it("空配列は空配列", () => {
    expect(groupIntoThreads([])).toEqual([]);
  });
});
