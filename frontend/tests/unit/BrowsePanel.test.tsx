import { describe, it, expect, vi, beforeEach } from "vitest";
import { screen, fireEvent } from "@testing-library/react";

import { BrowsePanel } from "@/components/BrowsePanel";
import HomePage from "@/app/page";
import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import { useSearch } from "@/lib/hooks/useSearch";
import { sampleChannels, sampleUsers, sampleMessages } from "../fixtures/jsonapi";
import { renderWithMantine as renderUI } from "../helpers/render";

vi.mock("@/lib/hooks/useChannels", () => ({ useChannels: vi.fn() }));
vi.mock("@/lib/hooks/useUsers", () => ({ useUsers: vi.fn() }));
vi.mock("@/lib/hooks/useChannelMessages", () => ({
  useChannelMessages: vi.fn(),
}));
vi.mock("@/lib/hooks/useSearch", () => ({ useSearch: vi.fn() }));

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const asResult = (data: unknown, extra: Record<string, unknown> = {}) =>
  ({ data, isLoading: false, isSuccess: true, ...extra }) as any;

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const asMessagesResult = (
  messages: unknown,
  extra: Record<string, unknown> = {},
) =>
  ({
    messages,
    isLoading: false,
    isError: false,
    hasNextPage: false,
    isFetchingNextPage: false,
    fetchNextPage: vi.fn(),
    ...extra,
  }) as any;

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const asSearchResult = (data: unknown, extra: Record<string, unknown> = {}) =>
  ({ data, isLoading: false, isError: false, ...extra }) as any;

const emptySearchPage = {
  messages: [],
  facets: [],
  totalCount: 0,
  hasNext: false,
};

describe("BrowsePanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useChannels).mockReturnValue(asResult(sampleChannels));
    vi.mocked(useUsers).mockReturnValue(asResult(sampleUsers));
    vi.mocked(useChannelMessages).mockReturnValue(
      asMessagesResult(sampleMessages),
    );
    vi.mocked(useSearch).mockReturnValue(asSearchResult(emptySearchPage));
  });

  it("チャンネル一覧を出し、初期はチャンネル未選択の案内を出す", () => {
    renderUI(<BrowsePanel />);
    expect(screen.getByText("general")).toBeInTheDocument();
    expect(screen.getByText("random")).toBeInTheDocument();
    expect(screen.getByTestId("no-channel")).toBeInTheDocument();
  });

  it("チャンネルを選ぶとその tid でメッセージを取得し描画する", () => {
    renderUI(<BrowsePanel />);
    fireEvent.click(screen.getByText("general"));

    expect(useChannelMessages).toHaveBeenCalledWith(1);
    expect(screen.queryByTestId("no-channel")).toBeNull();
    expect(screen.getByTestId("thread-m-parent")).toBeInTheDocument();
  });

  it("選択中のチャンネル名を見出しに出す", () => {
    renderUI(<BrowsePanel />);
    fireEvent.click(screen.getByText("general"));
    expect(screen.getByTestId("browse-heading")).toHaveTextContent("general");
  });

  it("トップページ（/）は BrowsePanel を描画する", () => {
    renderUI(<HomePage />);
    expect(screen.getByText("general")).toBeInTheDocument();
    expect(screen.getByTestId("browse-heading")).toBeInTheDocument();
  });

  it("メッセージ取得失敗時はエラー表示を出す", () => {
    vi.mocked(useChannelMessages).mockReturnValue(
      asMessagesResult([], { isError: true }),
    );
    renderUI(<BrowsePanel />);
    fireEvent.click(screen.getByText("general"));
    expect(screen.getByTestId("messages-error")).toBeInTheDocument();
  });

  it("hasNextPage のとき「さらに読み込む」ボタンを描画し押下で fetchNextPage を呼ぶ", () => {
    const fetchNextPage = vi.fn();
    vi.mocked(useChannelMessages).mockReturnValue(
      asMessagesResult(sampleMessages, { hasNextPage: true, fetchNextPage }),
    );
    renderUI(<BrowsePanel />);
    fireEvent.click(screen.getByText("general"));

    fireEvent.click(screen.getByTestId("load-more"));
    expect(fetchNextPage).toHaveBeenCalled();
  });

  it("主見出しはページの h1 である（見出し階層）", () => {
    renderUI(<BrowsePanel />);
    const h1 = screen.getByRole("heading", { level: 1 });
    expect(h1).toHaveAttribute("data-testid", "browse-heading");
  });

  it("モバイル用にナビ開閉の Burger を備える", () => {
    renderUI(<BrowsePanel />);
    const burger = screen.getByLabelText("ナビゲーションを切り替え");
    expect(burger).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(burger);
    expect(burger).toHaveAttribute("aria-expanded", "true");
    // 再クリックで閉じる（完全なトグル）。
    fireEvent.click(burger);
    expect(burger).toHaveAttribute("aria-expanded", "false");
  });

  describe("検索モード", () => {
    function submitSearch(value: string) {
      fireEvent.change(screen.getByRole("searchbox"), { target: { value } });
      fireEvent.submit(screen.getByRole("search"));
    }

    it("検索を実行すると検索モードになり FacetSidebar と SearchResultList を表示し、ChannelList は隠す", () => {
      vi.mocked(useSearch).mockReturnValue(
        asSearchResult({
          messages: [sampleMessages[0]],
          facets: [],
          totalCount: 1,
          hasNext: false,
        }),
      );
      renderUI(<BrowsePanel />);

      submitSearch("hello");

      expect(useSearch).toHaveBeenCalledWith({ fulltext: "hello", offset: 0 });
      expect(screen.getByTestId("search-result-list")).toBeInTheDocument();
      expect(screen.queryByTestId("channel-list")).not.toBeInTheDocument();
    });

    it("検索をクリアすると閲覧モードに戻る（選択中チャンネルは保持）", () => {
      vi.mocked(useSearch).mockReturnValue(
        asSearchResult({
          messages: [sampleMessages[0]],
          facets: [],
          totalCount: 1,
          hasNext: false,
        }),
      );
      renderUI(<BrowsePanel />);
      fireEvent.click(screen.getByText("general"));

      submitSearch("hello");
      expect(screen.getByTestId("search-result-list")).toBeInTheDocument();
      expect(screen.queryByTestId("channel-list")).not.toBeInTheDocument();

      submitSearch("");

      expect(screen.queryByTestId("search-result-list")).not.toBeInTheDocument();
      expect(screen.getByTestId("channel-list")).toBeInTheDocument();
      expect(screen.getByTestId("browse-heading")).toHaveTextContent("general");
    });

    it("facet クリックで絞り込み state を更新し useSearch に反映する", () => {
      vi.mocked(useSearch).mockReturnValue(
        asSearchResult({
          messages: [sampleMessages[0]],
          facets: [
            {
              id: "channel",
              label: "Channel",
              terms: [{ value: "general", count: 2, active: false }],
            },
          ],
          totalCount: 1,
          hasNext: false,
        }),
      );
      renderUI(<BrowsePanel />);

      submitSearch("hello");
      fireEvent.click(screen.getByText("general (2)"));

      expect(useSearch).toHaveBeenLastCalledWith({
        fulltext: "hello",
        offset: 0,
        channel: "general",
      });
    });
  });
});
