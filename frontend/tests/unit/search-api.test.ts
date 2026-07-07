import { afterEach, describe, expect, it, vi } from "vitest";

import { getDrupalClient } from "@/lib/drupal";
import {
  SEARCH_PAGE_SIZE,
  buildSearchParams,
  fetchSearchResults,
  mapFacets,
  toggleFacetValue,
} from "@/lib/search-api";
import type { SearchFilters } from "@/lib/types/search";
import { rawMessages } from "../fixtures/jsonapi";
import {
  rawFacetsMeta,
  searchResultsResponse,
  searchResultsResponseFullPageNoLinks,
  searchResultsResponseNoNext,
} from "../fixtures/search";

/**
 * small (Vitest): 検索クエリビルダ（drupal-jsonapi-params）。
 * 契約は docs/spec/jsonapi-search.md §3。
 */
describe("buildSearchParams", () => {
  it("常に filter[fulltext]・page・sort=-posted_at を含む", () => {
    const qs = buildSearchParams({ fulltext: "hello", offset: 0 }).getQueryString({
      encode: false,
    });
    expect(qs).toContain("filter[fulltext]=hello");
    expect(qs).toContain(`page[limit]=${SEARCH_PAGE_SIZE}`);
    expect(qs).toContain("page[offset]=0");
    expect(qs).toContain("sort=-posted_at");
  });

  it("channel が設定されているときのみ filter[channel] を含める", () => {
    const withChannel = buildSearchParams({
      fulltext: "x",
      channel: "general",
      offset: 0,
    }).getQueryString({ encode: false });
    expect(withChannel).toContain("filter[channel]=general");

    const without = buildSearchParams({ fulltext: "x", offset: 0 }).getQueryString({
      encode: false,
    });
    expect(without).not.toContain("filter[channel]");
  });

  it("slackUser が設定されているときのみ filter[slack_user] を含める", () => {
    const qs = buildSearchParams({
      fulltext: "x",
      slackUser: "taro",
      offset: 0,
    }).getQueryString({ encode: false });
    expect(qs).toContain("filter[slack_user]=taro");
  });

  it("postedAt が設定されているときのみ filter[posted_at] を含める", () => {
    const qs = buildSearchParams({
      fulltext: "x",
      postedAt: "2026-01-01",
      offset: 0,
    }).getQueryString({ encode: false });
    expect(qs).toContain("filter[posted_at][value]=2026-01-01");
  });

  it("offset を page[offset] に反映する", () => {
    const qs = buildSearchParams({ fulltext: "x", offset: 40 }).getQueryString({
      encode: false,
    });
    expect(qs).toContain("page[offset]=40");
  });
});

/**
 * small (Vitest): `meta.facets` → `Facet[]` mapper。
 * `empty_behavior: none` により 0 件 facet は配列ごと省略され、`meta.facets` 自体が
 * 無いこともある（docs/spec/jsonapi-search.md §4）。
 */
describe("mapFacets", () => {
  it("meta.facets を Facet[] へ整形する", () => {
    expect(mapFacets(rawFacetsMeta)).toEqual([
      {
        id: "channel",
        label: "Channel",
        terms: [
          { value: "general", count: 42, active: false },
          { value: "random", count: 7, active: true },
        ],
      },
      {
        id: "slack_user",
        label: "Slack User",
        terms: [{ value: "taro", count: 3, active: false }],
      },
    ]);
  });

  it("meta.facets が無くても空配列を返す", () => {
    expect(mapFacets(undefined)).toEqual([]);
    expect(mapFacets({})).toEqual([]);
  });
});

/**
 * small (Vitest): facet トグルの純関数。同値なら解除・別値なら置換し、常に offset を 0 に戻す。
 */
describe("toggleFacetValue", () => {
  const base: SearchFilters = { fulltext: "hello", offset: 40 };

  it("未設定の facet 値をセットし offset を 0 に戻す", () => {
    expect(toggleFacetValue(base, "channel", "general")).toEqual({
      fulltext: "hello",
      offset: 0,
      channel: "general",
    });
  });

  it("同じ値をトグルすると解除する", () => {
    const withChannel: SearchFilters = { ...base, channel: "general" };
    const next = toggleFacetValue(withChannel, "channel", "general");
    expect(next.channel).toBeUndefined();
    expect(next.offset).toBe(0);
  });

  it("別の値なら置換する", () => {
    const withChannel: SearchFilters = { ...base, channel: "general" };
    const next = toggleFacetValue(withChannel, "channel", "random");
    expect(next.channel).toBe("random");
    expect(next.offset).toBe(0);
  });

  it("slack_user / posted_at facetId も同様に扱う", () => {
    expect(toggleFacetValue(base, "slack_user", "taro").slackUser).toBe("taro");
    expect(toggleFacetValue(base, "posted_at", "2026-01-01").postedAt).toBe(
      "2026-01-01",
    );
  });
});

/**
 * small (Vitest): `fetchSearchResults`。実 `NextDrupal` クライアントの `buildUrl` は
 * そのまま使い、`fetch` のみ差し替えて生成 URL のクエリ文字列を厳密に検証する。
 */
describe("fetchSearchResults", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  function stubFetch(jsonBody: unknown) {
    const client = getDrupalClient();
    const fetchMock = vi.spyOn(client, "fetch").mockResolvedValue({
      json: () => Promise.resolve(jsonBody),
    } as unknown as Response);
    return { client, fetchMock };
  }

  it("index ルートへ filter/page/sort を含む URL で GET する", async () => {
    const { client, fetchMock } = stubFetch(searchResultsResponseNoNext);

    await fetchSearchResults({ fulltext: "hello", channel: "general", offset: 0 }, client);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const calledUrl = new URL(String(fetchMock.mock.calls[0][0]));
    expect(calledUrl.pathname).toBe("/jsonapi/index/slack_messages");
    expect(calledUrl.searchParams.get("filter[fulltext]")).toBe("hello");
    expect(calledUrl.searchParams.get("filter[channel]")).toBe("general");
    expect(calledUrl.searchParams.get("page[limit]")).toBe(String(SEARCH_PAGE_SIZE));
    expect(calledUrl.searchParams.get("page[offset]")).toBe("0");
    expect(calledUrl.searchParams.get("sort")).toBe("-posted_at");
  });

  it("data を mapMessage で整形し、included の file から添付を解決する", async () => {
    const { client } = stubFetch(searchResultsResponse);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.messages).toHaveLength(2);
    expect(result.messages[0].id).toBe(rawMessages[0].id);
  });

  it("meta.facets を facets へ整形する", async () => {
    const { client } = stubFetch(searchResultsResponse);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.facets).toHaveLength(2);
    expect(result.facets[0].id).toBe("channel");
  });

  it("meta.count が無ければ totalCount は null", async () => {
    const { client } = stubFetch(searchResultsResponseNoNext);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.totalCount).toBeNull();
  });

  it("links.next があれば hasNext=true", async () => {
    const { client } = stubFetch(searchResultsResponse);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.hasNext).toBe(true);
  });

  it("links.next が無くページ未満のヒットなら hasNext=false", async () => {
    const { client } = stubFetch(searchResultsResponseNoNext);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.hasNext).toBe(false);
  });

  it("links.next が無くても SEARCH_PAGE_SIZE 件ヒットしていれば hasNext=true", async () => {
    const { client } = stubFetch(searchResultsResponseFullPageNoLinks);

    const result = await fetchSearchResults({ fulltext: "hello", offset: 0 }, client);

    expect(result.messages).toHaveLength(SEARCH_PAGE_SIZE);
    expect(result.hasNext).toBe(true);
  });
});
