import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { createElement } from "react";

import { useExportStatus } from "@/lib/hooks/useExportStatus";
import { useTriggerExport } from "@/lib/hooks/useTriggerExport";
import * as api from "@/lib/slack-portal";
import type { ExportStatus } from "@/lib/slack-portal";

vi.mock("@/lib/slack-portal", () => ({
  fetchExportStatus: vi.fn(),
  triggerExport: vi.fn(),
}));

const RUNNING: ExportStatus = {
  status: "running",
  total: 4,
  processed: 2,
  messages: 20,
  users: 5,
  channels: [],
  started_at: 1717545600,
  finished_at: null,
  last_error: null,
};

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client: queryClient }, children);
  return { wrapper, queryClient };
}

describe("Slack Portal hooks", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("useExportStatus は fetchExportStatus の結果を返す", async () => {
    vi.mocked(api.fetchExportStatus).mockResolvedValue(RUNNING);
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useExportStatus(), { wrapper });

    await waitFor(() => expect(result.current.data).toEqual(RUNNING));
    expect(api.fetchExportStatus).toHaveBeenCalled();
  });

  it("useTriggerExport は mutate で triggerExport を呼ぶ", async () => {
    vi.mocked(api.triggerExport).mockResolvedValue({
      status: "queued",
      queued: 4,
      users: 5,
    });
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useTriggerExport(), { wrapper });

    act(() => {
      result.current.mutate();
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(api.triggerExport).toHaveBeenCalledTimes(1);
  });
});
