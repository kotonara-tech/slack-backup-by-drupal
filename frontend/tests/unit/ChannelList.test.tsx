import { describe, it, expect, vi } from "vitest";
import { screen, fireEvent } from "@testing-library/react";

import { ChannelList } from "@/components/ChannelList";
import { sampleChannels } from "../fixtures/jsonapi";
import { renderWithMantine as renderUI } from "../helpers/render";

describe("ChannelList", () => {
  it("チャンネル名を一覧表示する", () => {
    renderUI(
      <ChannelList channels={sampleChannels} selectedTid={null} onSelect={vi.fn()} />,
    );
    expect(screen.getByText("general")).toBeInTheDocument();
    expect(screen.getByText("random")).toBeInTheDocument();
  });

  it("チャンネルをクリックすると tid で onSelect する", () => {
    const onSelect = vi.fn();
    renderUI(
      <ChannelList channels={sampleChannels} selectedTid={null} onSelect={onSelect} />,
    );
    fireEvent.click(screen.getByText("general"));
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it("選択中のチャンネルを active として示す", () => {
    renderUI(
      <ChannelList channels={sampleChannels} selectedTid={2} onSelect={vi.fn()} />,
    );
    const active = screen.getByTestId("channel-2");
    expect(active).toHaveAttribute("data-active", "true");
  });

  it("チャンネルが無いときは空のヒントを出す", () => {
    renderUI(<ChannelList channels={[]} selectedTid={null} onSelect={vi.fn()} />);
    expect(screen.getByTestId("channel-list-empty")).toBeInTheDocument();
  });
});
