/**
 * 全文検索データアクセス（M5、`/jsonapi/index/slack_messages`）。
 *
 * `jsonapi_search_api` の index ルートは JSON:API の通常リソースコレクションではない
 * （next-drupal の `getResourceCollection` はリソース型からルートを解決するため使えない）
 * ので、`NextDrupal` の低レベル API（`buildUrl` + `fetch`）で直接 GET する。
 * message の整形は `lib/slack-api.ts` の `mapMessage`/`buildFileMap` を再利用する
 * （node--slack_message resource object であることは backend Functional テストで確認済み）。
 */
import { DrupalJsonApiParams } from "drupal-jsonapi-params";
import type { NextDrupal } from "next-drupal";

import { getDrupalClient } from "@/lib/drupal";
import { buildFileMap, mapMessage } from "@/lib/slack-api";
import type { JsonApiResponse } from "@/lib/types/slack";
import type { Facet, FacetTerm, SearchFilters, SearchResultPage } from "@/lib/types/search";

/** 1 ページあたりの件数（`page[limit]`）。 */
export const SEARCH_PAGE_SIZE = 20;

/** `meta.facets[]` の生形状（docs/spec/jsonapi-search.md §4.2）。 */
interface RawFacetTerm {
  values: { value: string; count: number; active: boolean };
}
interface RawFacet {
  id: string;
  label: string;
  terms?: RawFacetTerm[];
}

/** facetId ⇔ SearchFilters のキー対応。 */
const FACET_FILTER_KEYS = {
  channel: "channel",
  slack_user: "slackUser",
  posted_at: "postedAt",
} as const;

type FacetId = keyof typeof FACET_FILTER_KEYS;

/**
 * 検索クエリを組み立てる。fulltext は必須で常に含み、facet フィルタは設定時のみ含める。
 * ページング・並び順（新しい順）は常に固定。
 */
export function buildSearchParams(filters: SearchFilters): DrupalJsonApiParams {
  const params = new DrupalJsonApiParams();
  params.addFilter("fulltext", filters.fulltext);
  if (filters.channel) params.addFilter("channel", filters.channel);
  if (filters.slackUser) params.addFilter("slack_user", filters.slackUser);
  // ">=" を使うと drupal-jsonapi-params が value/operator のネスト形（docs/spec
  // §3.2 の例と同形）を生成する。"posted at or after" の意味も自然。
  if (filters.postedAt) params.addFilter("posted_at", filters.postedAt, ">=");
  params.addSort("posted_at", "DESC");
  params.addPageLimit(SEARCH_PAGE_SIZE);
  params.addPageOffset(filters.offset);
  return params;
}

/** `meta.facets` を `Facet[]` へ整形する。欠落・空でも空配列を返す。 */
export function mapFacets(meta: Record<string, unknown> | undefined): Facet[] {
  const candidate = meta?.facets;
  const rawFacets = Array.isArray(candidate) ? (candidate as RawFacet[]) : [];
  return rawFacets.map((facet) => ({
    id: facet.id,
    label: facet.label,
    terms: (facet.terms ?? []).map(
      (term): FacetTerm => ({
        value: term.values.value,
        count: term.values.count,
        active: term.values.active,
      }),
    ),
  }));
}

/**
 * facet 値のトグル（純関数）。同値なら解除、別値なら置換し、必ず offset を 0 に戻す。
 */
export function toggleFacetValue(
  filters: SearchFilters,
  facetId: FacetId,
  value: string,
): SearchFilters {
  const key = FACET_FILTER_KEYS[facetId];
  const current = filters[key];
  const next = { ...filters, offset: 0 };
  if (current === value) {
    delete next[key];
  } else {
    next[key] = value;
  }
  return next;
}

/** raw index レスポンスの meta/links（存在保証なし）。 */
interface SearchIndexResponse extends JsonApiResponse {
  meta?: { facets?: RawFacet[]; count?: number };
  links?: { next?: unknown };
}

/** 検索結果 1 ページを取得する。 */
export async function fetchSearchResults(
  filters: SearchFilters,
  client: NextDrupal = getDrupalClient(),
): Promise<SearchResultPage> {
  const params = buildSearchParams(filters);
  const url = client.buildUrl("/jsonapi/index/slack_messages", params.getQueryObject());
  const response = await client.fetch(url.toString());
  const json = (await response.json()) as SearchIndexResponse;

  const fileMap = buildFileMap(json.included ?? []);
  const messages = (json.data ?? []).map((m) => mapMessage(m, fileMap));
  const facets = mapFacets(json.meta);
  const totalCount = typeof json.meta?.count === "number" ? json.meta.count : null;
  // backend（jsonapi_search_api IndexResource）は offset+count<total のときのみ
  // links.next を付与する信頼できる契約。meta.count が無いときのみ links.next を見る。
  // 件数が page size と同数という length heuristic は使わない（総件数が page size
  // の倍数の最終ページで偽陽性となり空ページへ誘導するため）。
  const hasNext =
    totalCount !== null
      ? filters.offset + messages.length < totalCount
      : Boolean(json.links?.next);

  return { messages, facets, totalCount, hasNext };
}
