/**
 * M5 全文検索のテスト fixture（`/jsonapi/index/slack_messages`、docs/spec/jsonapi-search.md）。
 *
 * facet 応答は `meta.facets`。`empty_behavior: none` により 0 件 facet は配列ごと
 * 省略されうるため、facet が 1 つも無い（`meta` 自体が空）ケースも用意する。
 */
import { SEARCH_PAGE_SIZE } from "@/lib/search-api";
import type { JsonApiResource, JsonApiResponse } from "@/lib/types/slack";

import { rawFiles, rawMessages } from "./jsonapi";

/** `meta.facets` の生形状（channel facet に active な値を含む）。 */
export const rawFacetsMeta = {
  facets: [
    {
      id: "channel",
      label: "Channel",
      path: "filter[channel]",
      terms: [
        {
          url: "/jsonapi/index/slack_messages?filter[fulltext]=hello&filter[channel]=general",
          values: { value: "general", count: 42, active: false },
        },
        {
          url: "/jsonapi/index/slack_messages?filter[fulltext]=hello&filter[channel]=random",
          values: { value: "random", count: 7, active: true },
        },
      ],
    },
    {
      id: "slack_user",
      label: "Slack User",
      path: "filter[slack_user]",
      terms: [
        {
          url: "/jsonapi/index/slack_messages?filter[fulltext]=hello&filter[slack_user]=taro",
          values: { value: "taro", count: 3, active: false },
        },
      ],
    },
  ],
};

/** 2 件ヒット・facet あり・次ページありのレスポンス。 */
export const searchResultsResponse: JsonApiResponse = {
  data: rawMessages.slice(0, 2),
  included: rawFiles,
  meta: rawFacetsMeta,
  links: { next: { href: "https://example.com/jsonapi/index/slack_messages?page[offset]=20" } },
};

/** 1 件ヒット・facet 無し（`meta` が空）・次ページ無しのレスポンス。 */
export const searchResultsResponseNoNext: JsonApiResponse = {
  data: rawMessages.slice(0, 1),
  meta: {},
};

/** ちょうど 1 ページ分（`SEARCH_PAGE_SIZE` 件）ヒットし、`links.next` を返さないレスポンス。 */
function clonedMessage(index: number): JsonApiResource {
  const base = rawMessages[0];
  return {
    ...base,
    id: `${base.id}-${index}`,
    attributes: { ...base.attributes, drupal_internal__nid: 1000 + index },
  };
}

/**
 * ちょうど最終ページ（`meta.count` が offset+件数と一致）で `links.next` を
 * 返さないレスポンス。旧 length heuristic では偽陽性で hasNext=true になっていたが、
 * `meta.count` 基準では正しく hasNext=false になるべきケース。
 */
export const searchResultsResponseFullPageNoLinks: JsonApiResponse = {
  data: Array.from({ length: SEARCH_PAGE_SIZE }, (_, i) => clonedMessage(i)),
  meta: { count: SEARCH_PAGE_SIZE },
};

/** `meta.count` が offset+件数より大きい（まだ後続ページがある）レスポンス。`links.next` は無い。 */
export const searchResultsResponseWithCountHasMore: JsonApiResponse = {
  data: rawMessages.slice(0, 2),
  meta: { count: 50 },
};

/** `meta.count` が offset+件数と一致（後続ページ無し）レスポンス。`links.next` も無い。 */
export const searchResultsResponseWithCountExhausted: JsonApiResponse = {
  data: rawMessages.slice(0, 2),
  meta: { count: 2 },
};
