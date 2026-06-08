import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MantineProvider } from "@mantine/core";
import type { ReactNode } from "react";

import { ChannelList } from "@/components/ChannelList";
import { sampleChannels } from "../fixtures/jsonapi";

function renderUI(ui: ReactNode) {
  return render(<MantineProvider>{ui}</MantineProvider>);
}

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
