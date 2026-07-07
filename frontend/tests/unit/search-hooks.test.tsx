import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { createElement } from "react";

import { useSearch } from "@/lib/hooks/useSearch";
import * as api from "@/lib/search-api";
import type { SearchResultPage } from "@/lib/types/search";

vi.mock("@/lib/search-api", async () => {
  const actual = await vi.importActual<typeof import("@/lib/search-api")>(
    "@/lib/search-api",
  );
  return { ...actual, fetchSearchResults: vi.fn() };
});

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client: queryClient }, children);
  return { wrapper };
}

const emptyPage: SearchResultPage = {
  messages: [],
  facets: [],
  totalCount: 0,
  hasNext: false,
};

/**
 * small (Vitest): 検索結果を取得する useSearch フック。
 * fulltext が空のときはフェッチせず、非空になると filters をキーにフェッチする。
 */
describe("useSearch", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("fulltext が空のときはフェッチしない", () => {
    const { wrapper } = createWrapper();

    const { result } = renderHook(
      () => useSearch({ fulltext: "", offset: 0 }),
      { wrapper },
    );

    expect(api.fetchSearchResults).not.toHaveBeenCalled();
    expect(result.current.isLoading).toBe(false);
  });

  it("fulltext が非空のときフェッチし結果を返す", async () => {
    vi.mocked(api.fetchSearchResults).mockResolvedValue(emptyPage);
    const { wrapper } = createWrapper();

    const { result } = renderHook(
      () => useSearch({ fulltext: "hello", offset: 0 }),
      { wrapper },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(api.fetchSearchResults).toHaveBeenCalledWith({
      fulltext: "hello",
      offset: 0,
    });
    expect(result.current.data).toEqual(emptyPage);
  });

  it("取得失敗を isError で伝える", async () => {
    vi.mocked(api.fetchSearchResults).mockRejectedValue(new Error("boom"));
    const { wrapper } = createWrapper();

    const { result } = renderHook(
      () => useSearch({ fulltext: "hello", offset: 0 }),
      { wrapper },
    );

    await waitFor(() => expect(result.current.isError).toBe(true));
  });

  it("filters 変更中は前回データを保持しつつ isPlaceholderData/isFetching を true にする", async () => {
    const firstPage: SearchResultPage = { ...emptyPage, totalCount: 1 };
    let resolveSecond: (page: SearchResultPage) => void = () => {};
    const secondPagePromise = new Promise<SearchResultPage>((resolve) => {
      resolveSecond = resolve;
    });
    vi.mocked(api.fetchSearchResults)
      .mockResolvedValueOnce(firstPage)
      .mockReturnValueOnce(secondPagePromise);
    const { wrapper } = createWrapper();

    const { result, rerender } = renderHook(
      ({ filters }) => useSearch(filters),
      { wrapper, initialProps: { filters: { fulltext: "hello", offset: 0 } } },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.isPlaceholderData).toBe(false);

    rerender({ filters: { fulltext: "hello", offset: 20 } });

    await waitFor(() => expect(result.current.isFetching).toBe(true));
    expect(result.current.isPlaceholderData).toBe(true);
    expect(result.current.data).toEqual(firstPage); // 前回データを保持

    resolveSecond({ ...emptyPage, totalCount: 2 });

    await waitFor(() => expect(result.current.isPlaceholderData).toBe(false));
    expect(result.current.data).toEqual({ ...emptyPage, totalCount: 2 });
  });

  it("filters が変わると再フェッチする", async () => {
    vi.mocked(api.fetchSearchResults).mockResolvedValue(emptyPage);
    const { wrapper } = createWrapper();

    const { result, rerender } = renderHook(
      ({ filters }) => useSearch(filters),
      { wrapper, initialProps: { filters: { fulltext: "hello", offset: 0 } } },
    );

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(api.fetchSearchResults).toHaveBeenCalledTimes(1);

    rerender({ filters: { fulltext: "hello", offset: 20 } });

    await waitFor(() =>
      expect(api.fetchSearchResults).toHaveBeenCalledTimes(2),
    );
    expect(api.fetchSearchResults).toHaveBeenLastCalledWith({
      fulltext: "hello",
      offset: 20,
    });
  });
});
