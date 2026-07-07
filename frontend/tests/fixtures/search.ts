/**
 * M5 全文検索のテスト fixture（`/jsonapi/index/slack_messages`、docs/spec/jsonapi-search.md）。
 *
 * facet 応答は `meta.facets`。`empty_behavior: none` により 0 件 facet は配列ごと
 * 省略されうるため、facet が 1 つも無い（`meta` 自体が空）ケースも用意する。
 */
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

/** ちょうど 1 ページ分（20 件）ヒットし、`links.next` を返さないレスポンス。 */
function clonedMessage(index: number): JsonApiResource {
  const base = rawMessages[0];
  return {
    ...base,
    id: `${base.id}-${index}`,
    attributes: { ...base.attributes, drupal_internal__nid: 1000 + index },
  };
}

export const searchResultsResponseFullPageNoLinks: JsonApiResponse = {
  data: Array.from({ length: 20 }, (_, i) => clonedMessage(i)),
  meta: {},
};
