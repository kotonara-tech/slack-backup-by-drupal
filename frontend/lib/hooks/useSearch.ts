/**
 * 全文検索結果を取得する TanStack Query フック（M5）。
 *
 * `filters.fulltext` が空（トリム後）の間はフェッチしない（`enabled`）。
 * facet/ページ切替時のちらつきを防ぐため、前回データを保持したまま
 * 新しいフェッチを走らせる（`placeholderData: keepPreviousData`）。
 */
import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { fetchSearchResults } from "@/lib/search-api";
import type { SearchFilters, SearchResultPage } from "@/lib/types/search";

export function searchQueryKey(filters: SearchFilters) {
  return ["slack", "search", filters] as const;
}

export function useSearch(filters: SearchFilters) {
  return useQuery<SearchResultPage>({
    queryKey: searchQueryKey(filters),
    queryFn: () => fetchSearchResults(filters),
    enabled: filters.fulltext.trim() !== "",
    placeholderData: keepPreviousData,
  });
}
