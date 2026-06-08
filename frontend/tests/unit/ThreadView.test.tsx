import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MantineProvider } from "@mantine/core";
import type { ReactNode } from "react";

import { ThreadView } from "@/components/ThreadView";
import { MessageList } from "@/components/MessageList";
import { groupIntoThreads } from "@/lib/threads";
import { sampleMessages, sampleUserMap } from "../fixtures/jsonapi";

function renderUI(ui: ReactNode) {
  return render(<MantineProvider>{ui}</MantineProvider>);
}

const threads = groupIntoThreads(sampleMessages);
const parentThread = threads.find((t) => t.root.id === "m-parent")!;
const standaloneThread = threads.find((t) => t.root.id === "m-bot")!;

describe("ThreadView", () => {
  it("親メッセージを描画する", () => {
    renderUI(<ThreadView thread={parentThread} userMap={sampleUserMap} />);
    expect(screen.getByText("スレッドの親メッセージ")).toBeInTheDocument();
  });

  it("返信は既定で畳まれ、件数つきの開閉トグルを出す", () => {
    renderUI(<ThreadView thread={parentThread} userMap={sampleUserMap} />);
    expect(screen.getByTestId("thread-toggle-m-parent")).toHaveTextContent(
      "2件の返信",
    );
    expect(screen.queryByTestId("thread-replies-m-parent")).toBeNull();
  });

  it("トグルで返信を展開する", () => {
    renderUI(<ThreadView thread={parentThread} userMap={sampleUserMap} />);
    fireEvent.click(screen.getByTestId("thread-toggle-m-parent"));
    expect(screen.getByTestId("thread-replies-m-parent")).toBeInTheDocument();
    expect(screen.getByText("最初の返信")).toBeInTheDocument();
    expect(screen.getByText("ブロードキャスト返信")).toBeInTheDocument();
  });

  it("返信のないスレッドはトグルを出さない", () => {
    renderUI(<ThreadView thread={standaloneThread} userMap={sampleUserMap} />);
    expect(screen.queryByTestId("thread-toggle-m-bot")).toBeNull();
  });

  it("userMap から root の author 名を解決する", () => {
    renderUI(<ThreadView thread={parentThread} userMap={sampleUserMap} />);
    // m-parent の authorUuid=u-taro → displayName "taro"。
    expect(screen.getByText("taro")).toBeInTheDocument();
  });
});

describe("MessageList", () => {
  it("チャンネル未選択のときは案内を出す", () => {
    renderUI(
      <MessageList threads={[]} userMap={sampleUserMap} channelSelected={false} />,
    );
    expect(screen.getByTestId("no-channel")).toBeInTheDocument();
  });

  it("読み込み中はローダを出す", () => {
    renderUI(
      <MessageList
        threads={[]}
        userMap={sampleUserMap}
        channelSelected
        isLoading
      />,
    );
    expect(screen.getByTestId("messages-loading")).toBeInTheDocument();
  });

  it("メッセージ 0 件のときは空表示", () => {
    renderUI(
      <MessageList threads={[]} userMap={sampleUserMap} channelSelected />,
    );
    expect(screen.getByTestId("no-messages")).toBeInTheDocument();
  });

  it("スレッド配列を ThreadView として描画する", () => {
    renderUI(
      <MessageList threads={threads} userMap={sampleUserMap} channelSelected />,
    );
    expect(screen.getByTestId("thread-m-parent")).toBeInTheDocument();
    expect(screen.getByTestId("thread-m-orphan")).toBeInTheDocument();
  });
});
