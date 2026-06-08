import { describe, it, expect, vi, beforeEach } from "vitest";
import { screen, fireEvent } from "@testing-library/react";

import { BrowsePanel } from "@/components/BrowsePanel";
import HomePage from "@/app/page";
import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import { sampleChannels, sampleUsers, sampleMessages } from "../fixtures/jsonapi";
import { renderWithMantine as renderUI } from "../helpers/render";

vi.mock("@/lib/hooks/useChannels", () => ({ useChannels: vi.fn() }));
vi.mock("@/lib/hooks/useUsers", () => ({ useUsers: vi.fn() }));
vi.mock("@/lib/hooks/useChannelMessages", () => ({
  useChannelMessages: vi.fn(),
}));

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const asResult = (data: unknown, extra: Record<string, unknown> = {}) =>
  ({ data, isLoading: false, isSuccess: true, ...extra }) as any;

describe("BrowsePanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useChannels).mockReturnValue(asResult(sampleChannels));
    vi.mocked(useUsers).mockReturnValue(asResult(sampleUsers));
    vi.mocked(useChannelMessages).mockReturnValue(asResult(sampleMessages));
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
      asResult(undefined, { isError: true, isSuccess: false }),
    );
    renderUI(<BrowsePanel />);
    fireEvent.click(screen.getByText("general"));
    expect(screen.getByTestId("messages-error")).toBeInTheDocument();
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
});
