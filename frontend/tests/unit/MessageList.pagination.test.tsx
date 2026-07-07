import { describe, it, expect, vi } from "vitest";
import { screen, fireEvent } from "@testing-library/react";

import { MessageList } from "@/components/MessageList";
import { groupIntoThreads } from "@/lib/threads";
import { sampleMessages, sampleUserMap } from "../fixtures/jsonapi";
import { renderWithMantine as renderUI } from "../helpers/render";

const threads = groupIntoThreads(sampleMessages);

/**
 * small (Vitest): 「さらに読み込む」ボタンの出し分け（チャンネル閲覧のページング）。
 * 契約は docs/spec/frontend-browse.md（M4 追加読み込み）。
 */
describe("MessageList の追加読み込みボタン", () => {
  it("hasNextPage が真のときボタンを表示する", () => {
    renderUI(
      <MessageList
        threads={threads}
        userMap={sampleUserMap}
        channelSelected
        hasNextPage
        onLoadMore={vi.fn()}
      />,
    );
    expect(screen.getByTestId("load-more")).toBeInTheDocument();
  });

  it("hasNextPage が偽のときボタンを表示しない", () => {
    renderUI(
      <MessageList
        threads={threads}
        userMap={sampleUserMap}
        channelSelected
        hasNextPage={false}
      />,
    );
    expect(screen.queryByTestId("load-more")).toBeNull();
  });

  it("ボタン押下で onLoadMore を呼ぶ", () => {
    const onLoadMore = vi.fn();
    renderUI(
      <MessageList
        threads={threads}
        userMap={sampleUserMap}
        channelSelected
        hasNextPage
        onLoadMore={onLoadMore}
      />,
    );
    fireEvent.click(screen.getByTestId("load-more"));
    expect(onLoadMore).toHaveBeenCalledTimes(1);
  });

  it("読込中はボタンを disabled にする", () => {
    renderUI(
      <MessageList
        threads={threads}
        userMap={sampleUserMap}
        channelSelected
        hasNextPage
        isFetchingNextPage
        onLoadMore={vi.fn()}
      />,
    );
    expect(screen.getByTestId("load-more")).toBeDisabled();
  });

  it("チャンネル未選択のときはボタンを出さない", () => {
    renderUI(
      <MessageList
        threads={[]}
        userMap={sampleUserMap}
        channelSelected={false}
        hasNextPage
        onLoadMore={vi.fn()}
      />,
    );
    expect(screen.queryByTestId("load-more")).toBeNull();
  });
});
