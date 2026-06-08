import { describe, it, expect } from "vitest";
import { NextDrupal } from "next-drupal";

import { getDrupalClient, DRUPAL_BASE_URL } from "@/lib/drupal";

/**
 * small (Vitest): JSON:API クライアントの生成。
 * `getDrupalClient()` は `NEXT_PUBLIC_DRUPAL_BASE_URL`（既定値）から
 * next-drupal クライアントを生成し、再利用（memoize）する。
 */
describe("getDrupalClient", () => {
  it("NextDrupal インスタンスを返す", () => {
    expect(getDrupalClient()).toBeInstanceOf(NextDrupal);
  });

  it("DRUPAL_BASE_URL で初期化される（/jsonapi サフィックスは付けない）", () => {
    expect(getDrupalClient().baseUrl).toBe(DRUPAL_BASE_URL);
  });

  it("呼び出すたびに同一インスタンスを返す（memoize）", () => {
    expect(getDrupalClient()).toBe(getDrupalClient());
  });

  it("read コレクション取得用の getResourceCollection を備える", () => {
    // lib/slack-api.ts はこのメソッドを deserialize:false で使う。
    expect(typeof getDrupalClient().getResourceCollection).toBe("function");
  });
});
