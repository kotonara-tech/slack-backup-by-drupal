import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { createElement } from "react";

import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import * as api from "@/lib/slack-api";
import { sampleChannels, sampleUsers, sampleMessages } from "../fixtures/jsonapi";

vi.mock("@/lib/slack-api", () => ({
  fetchChannels: vi.fn(),
  fetchUsers: vi.fn(),
  fetchChannelMessages: vi.fn(),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client: queryClient }, children);
  return { wrapper };
}

describe("browse hooks", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("useChannels は public チャンネル一覧を返す", async () => {
    vi.mocked(api.fetchChannels).mockResolvedValue(sampleChannels);
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useChannels(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual(sampleChannels);
  });

  it("useUsers は全ユーザを返す", async () => {
    vi.mocked(api.fetchUsers).mockResolvedValue(sampleUsers);
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useUsers(), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(3);
  });

  it("useChannelMessages は tid=null のとき無効（フェッチしない）", () => {
    const { wrapper } = createWrapper();

    renderHook(() => useChannelMessages(null), { wrapper });

    expect(api.fetchChannelMessages).not.toHaveBeenCalled();
  });

  it("useChannelMessages は tid 指定でその channel のメッセージを取得する", async () => {
    vi.mocked(api.fetchChannelMessages).mockResolvedValue(sampleMessages);
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useChannelMessages(1), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(api.fetchChannelMessages).toHaveBeenCalledWith(1);
    expect(result.current.data).toHaveLength(sampleMessages.length);
  });
});
