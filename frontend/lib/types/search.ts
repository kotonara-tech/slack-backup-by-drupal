/**
 * M5 全文検索の型（`/jsonapi/index/slack_messages`）。契約は docs/spec/jsonapi-search.md。
 *
 * facet の欠落（`empty_behavior: none` により 0 件 facet は配列ごと省略される、
 * `meta.facets` 自体が無いこともある）に耐性を持つように、常に配列を返す
 * mapper（`mapFacets`）と組み合わせて使う。
 */
import type { SlackMessage } from "@/lib/types/slack";

/** facet の値 1 つ（`meta.facets[].terms[].values`）。 */
export interface FacetTerm {
  value: string;
  count: number;
  active: boolean;
}

/** facet 1 つ（`meta.facets[]`）。id は 'channel' | 'slack_user' | 'posted_at'。 */
export interface Facet {
  id: string;
  label: string;
  terms: FacetTerm[];
}

/** 検索フィルタ状態（UI の単一の真実源）。 */
export interface SearchFilters {
  fulltext: string;
  channel?: string;
  slackUser?: string;
  postedAt?: string;
  offset: number;
}

/** 検索結果 1 ページ。`totalCount` は `meta.count` 不在に備え null を許容する。 */
export interface SearchResultPage {
  messages: SlackMessage[];
  facets: Facet[];
  totalCount: number | null;
  hasNext: boolean;
}
