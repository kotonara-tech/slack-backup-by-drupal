import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MantineProvider } from "@mantine/core";
import type { ReactNode } from "react";

import { MessageCard } from "@/components/MessageCard";
import { sampleMessages, sampleUserMap } from "../fixtures/jsonapi";

function renderUI(ui: ReactNode) {
  return render(<MantineProvider>{ui}</MantineProvider>);
}

const byId = (id: string) => sampleMessages.find((m) => m.id === id)!;

describe("MessageCard", () => {
  it("author 名・投稿時刻・本文を描画する", () => {
    const parent = byId("m-parent"); // author u-taro (displayName=taro)
    renderUI(<MessageCard message={parent} author={sampleUserMap["u-taro"]} />);
    expect(screen.getByText("taro")).toBeInTheDocument();
    expect(screen.getByText("2026/05/01 10:00")).toBeInTheDocument();
    expect(screen.getByText("スレッドの親メッセージ")).toBeInTheDocument();
  });

  it("displayName が空なら realName へフォールバックする", () => {
    const standalone = byId("m-standalone"); // u-hanako (displayName="")
    renderUI(
      <MessageCard message={standalone} author={sampleUserMap["u-hanako"]} />,
    );
    expect(screen.getByText("Hanako Sato")).toBeInTheDocument();
  });

  it("author 不明（bot 等）は Unknown と表示する", () => {
    const bot = byId("m-bot"); // authorUuid=null → author undefined
    renderUI(<MessageCard message={bot} author={undefined} />);
    expect(screen.getByText("Unknown")).toBeInTheDocument();
  });

  it("reactions を名前＋件数で描画する", () => {
    const parent = byId("m-parent"); // thumbsup x3
    renderUI(<MessageCard message={parent} author={sampleUserMap["u-taro"]} />);
    const reaction = screen.getByTestId("reaction-thumbsup");
    expect(reaction).toHaveTextContent("thumbsup");
    expect(reaction).toHaveTextContent("3");
  });

  it("編集済みのメッセージに編集マーカーを出す", () => {
    const bot = byId("m-bot"); // edited=true
    renderUI(<MessageCard message={bot} author={undefined} />);
    expect(screen.getByTestId("edited-marker")).toBeInTheDocument();
  });

  it("添付ファイル名を描画する", () => {
    const standalone = byId("m-standalone"); // attachment log.txt
    renderUI(
      <MessageCard message={standalone} author={sampleUserMap["u-hanako"]} />,
    );
    expect(screen.getByText("log.txt")).toBeInTheDocument();
  });

  it("本文はプレーン（pre-wrap）で描画し dangerouslySetInnerHTML を使わない", () => {
    const parent = byId("m-parent");
    renderUI(<MessageCard message={parent} author={sampleUserMap["u-taro"]} />);
    expect(screen.getByTestId("message-body")).toHaveStyle({
      whiteSpace: "pre-wrap",
    });
  });

  it("reactions/添付が無いメッセージは余分な要素を出さない", () => {
    const reply = byId("m-reply1"); // reactions [], attachments []
    renderUI(<MessageCard message={reply} author={sampleUserMap["u-hanako"]} />);
    const card = screen.getByTestId("message-m-reply1");
    expect(within(card).queryByTestId("reaction-thumbsup")).toBeNull();
    expect(within(card).queryByText("log.txt")).toBeNull();
  });
});
