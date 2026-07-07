import { describe, it, expect, vi } from "vitest";
import { fireEvent, screen } from "@testing-library/react";

import { SearchResultList } from "@/components/SearchResultList";
import type { SearchResultPage, Facet } from "@/lib/types/search";
import { sampleChannels, sampleMessages, sampleUserMap } from "../fixtures/jsonapi";
import { renderWithMantine as renderUI } from "../helpers/render";

const channelMap = Object.fromEntries(sampleChannels.map((c) => [c.id, c]));

const facets: Facet[] = [];

function makePage(overrides: Partial<SearchResultPage> = {}): SearchResultPage {
  return {
    messages: sampleMessages.slice(0, 2),
    facets,
    totalCount: 2,
    hasNext: false,
    ...overrides,
  };
}

/**
 * small (Vitest): 検索結果リスト。
 * loading/error/0件/結果あり の出し分け、本文ハイライト、ページング活性制御を検証する。
 */
describe("SearchResultList", () => {
  it("読み込み中はローダーを出す", () => {
    renderUI(
      <SearchResultList
        page={undefined}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-loading")).toBeInTheDocument();
  });

  it("失敗時はエラー表示を出す", () => {
    renderUI(
      <SearchResultList
        page={undefined}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-error")).toBeInTheDocument();
  });

  it("0 件のときは 0 件表示を出す", () => {
    renderUI(
      <SearchResultList
        page={makePage({ messages: [], totalCount: 0 })}
        filters={{ fulltext: "zzz", offset: 0 }}
        isLoading={false}
        isError={false}
        query="zzz"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-no-results")).toBeInTheDocument();
  });

  it("0 件でも offset>0 なら「前へ」を描画し押下で onPrev を呼ぶ（空ページからの復帰）", () => {
    const onPrev = vi.fn();
    renderUI(
      <SearchResultList
        page={makePage({ messages: [], totalCount: 20 })}
        filters={{ fulltext: "hello", offset: 20 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={onPrev}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-no-results")).toBeInTheDocument();
    const prevButton = screen.getByTestId("search-prev");
    expect(prevButton).toBeEnabled();
    fireEvent.click(prevButton);
    expect(onPrev).toHaveBeenCalled();
  });

  it("結果ありのとき検索語をハイライトして描画する", () => {
    const threadMessage = sampleMessages.find((m) => m.id === "m-parent")!;
    const { container } = renderUI(
      <SearchResultList
        page={makePage({ messages: [threadMessage], totalCount: 1 })}
        filters={{ fulltext: "スレッド", offset: 0 }}
        isLoading={false}
        isError={false}
        query="スレッド"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-result-list")).toBeInTheDocument();
    const marks = container.querySelectorAll("mark");
    expect(marks.length).toBeGreaterThan(0);
    expect(marks[0]).toHaveTextContent("スレッド");
  });

  it("件数表示は role=status の live region になっている", () => {
    renderUI(
      <SearchResultList
        page={makePage({ totalCount: 42 })}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByRole("status")).toHaveTextContent(/全 42 件/);
  });

  it("totalCount が非 null のとき「全 N 件」を表示する", () => {
    renderUI(
      <SearchResultList
        page={makePage({ totalCount: 42 })}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByText(/全 42 件/)).toBeInTheDocument();
  });

  it("totalCount が null のときは「全 N 件」を表示しない", () => {
    renderUI(
      <SearchResultList
        page={makePage({ totalCount: null })}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.queryByText(/全 .* 件/)).not.toBeInTheDocument();
  });

  it("offset=0 のとき「前へ」は無効", () => {
    renderUI(
      <SearchResultList
        page={makePage()}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-prev")).toBeDisabled();
  });

  it("offset>0 のとき「前へ」は有効で押下で onPrev を呼ぶ", () => {
    const onPrev = vi.fn();
    renderUI(
      <SearchResultList
        page={makePage()}
        filters={{ fulltext: "hello", offset: 20 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={onPrev}
        onNext={vi.fn()}
      />,
    );

    const prevButton = screen.getByTestId("search-prev");
    expect(prevButton).toBeEnabled();
    fireEvent.click(prevButton);
    expect(onPrev).toHaveBeenCalled();
  });

  it("hasNext=false のとき「次へ」は無効", () => {
    renderUI(
      <SearchResultList
        page={makePage({ hasNext: false })}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={vi.fn()}
      />,
    );

    expect(screen.getByTestId("search-next")).toBeDisabled();
  });

  it("hasNext=true のとき「次へ」は有効で押下で onNext を呼ぶ", () => {
    const onNext = vi.fn();
    renderUI(
      <SearchResultList
        page={makePage({ hasNext: true })}
        filters={{ fulltext: "hello", offset: 0 }}
        isLoading={false}
        isError={false}
        query="hello"
        channelMap={channelMap}
        userMap={sampleUserMap}
        onPrev={vi.fn()}
        onNext={onNext}
      />,
    );

    const nextButton = screen.getByTestId("search-next");
    expect(nextButton).toBeEnabled();
    fireEvent.click(nextButton);
    expect(onNext).toHaveBeenCalled();
  });
});
