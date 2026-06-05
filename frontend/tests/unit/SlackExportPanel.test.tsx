import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MantineProvider } from "@mantine/core";
import type { ReactNode } from "react";

import { SlackExportPanel } from "@/components/SlackExportPanel";
import { useExportStatus } from "@/lib/hooks/useExportStatus";
import { useTriggerExport } from "@/lib/hooks/useTriggerExport";
import type { ExportStatus } from "@/lib/slack-portal";

vi.mock("@/lib/hooks/useExportStatus", () => ({ useExportStatus: vi.fn() }));
vi.mock("@/lib/hooks/useTriggerExport", () => ({ useTriggerExport: vi.fn() }));

function makeStatus(overrides: Partial<ExportStatus> = {}): ExportStatus {
  return {
    status: "idle",
    total: 0,
    processed: 0,
    messages: 0,
    users: 0,
    channels: [],
    started_at: null,
    finished_at: null,
    last_error: null,
    ...overrides,
  };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function stubStatus(status: ExportStatus | undefined) {
  vi.mocked(useExportStatus).mockReturnValue({ data: status } as any);
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function stubTrigger(overrides: Record<string, unknown> = {}) {
  const mutate = vi.fn();
  vi.mocked(useTriggerExport).mockReturnValue({
    mutate,
    isPending: false,
    isError: false,
    error: null,
    ...overrides,
  } as any);
  return mutate;
}

function renderPanel() {
  return render(<SlackExportPanel />, {
    wrapper: ({ children }: { children: ReactNode }) => (
      <MantineProvider>{children}</MantineProvider>
    ),
  });
}

describe("SlackExportPanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("idle 状態ではバッジが待機中で開始ボタンが押せる", () => {
    stubStatus(makeStatus({ status: "idle" }));
    stubTrigger();
    renderPanel();

    expect(screen.getByTestId("export-status-badge")).toHaveTextContent("待機中");
    expect(
      screen.getByRole("button", { name: /エクスポートを開始/ }),
    ).toBeEnabled();
  });

  it("running 状態では進捗とカウントを表示しボタンを無効化する", () => {
    stubStatus(
      makeStatus({ status: "running", total: 4, processed: 2, messages: 20, users: 5 }),
    );
    stubTrigger();
    renderPanel();

    expect(screen.getByTestId("export-status-badge")).toHaveTextContent("実行中");
    expect(screen.getByTestId("export-progress")).toBeInTheDocument();
    expect(screen.getByText(/チャンネル 2 \/ 4/)).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /エクスポートを開始/ }),
    ).toBeDisabled();
  });

  it("error 状態では last_error を Alert で表示する", () => {
    stubStatus(
      makeStatus({ status: "error", last_error: "channel C1 export failed" }),
    );
    stubTrigger();
    renderPanel();

    expect(screen.getByTestId("export-status-badge")).toHaveTextContent("エラー");
    expect(screen.getByText("channel C1 export failed")).toBeInTheDocument();
  });

  it("開始ボタン押下で trigger.mutate を呼ぶ", () => {
    stubStatus(makeStatus({ status: "idle" }));
    const mutate = stubTrigger();
    renderPanel();

    fireEvent.click(screen.getByRole("button", { name: /エクスポートを開始/ }));
    expect(mutate).toHaveBeenCalledTimes(1);
  });
});
