import { describe, it, expect, vi, beforeEach } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { createElement } from "react";

import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import {
  useChannelMessages,
  channelMessagesQueryKey,
} from "@/lib/hooks/useChannelMessages";
import * as api from "@/lib/slack-api";
import { sampleChannels, sampleUsers, sampleMessages } from "../fixtures/jsonapi";

vi.mock("@/lib/slack-api", () => ({
  fetchChannels: vi.fn(),
  fetchUsers: vi.fn(),
  fetchChannelMessages: vi.fn(),
  CHANNEL_MESSAGES_PAGE_LIMIT: 100,
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
    vi.mocked(api.fetchChannelMessages).mockResolvedValue({
      messages: sampleMessages,
      hasNext: false,
    });
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useChannelMessages(1), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(api.fetchChannelMessages).toHaveBeenCalledWith(1, 0);
    expect(result.current.messages).toHaveLength(sampleMessages.length);
    expect(result.current.hasNextPage).toBe(false);
  });

  it("useChannelMessages はさらに読み込むと次ページを messages に結合する", async () => {
    vi.mocked(api.fetchChannelMessages).mockImplementation((_tid, offset = 0) =>
      offset === 0
        ? Promise.resolve({ messages: [sampleMessages[0]], hasNext: true })
        : Promise.resolve({ messages: [sampleMessages[1]], hasNext: false }),
    );
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useChannelMessages(1), { wrapper });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.messages).toHaveLength(1);
    expect(result.current.hasNextPage).toBe(true);

    await act(async () => {
      await result.current.fetchNextPage();
    });

    await waitFor(() => expect(result.current.messages).toHaveLength(2));
    expect(result.current.hasNextPage).toBe(false);
  });

  it("channelMessagesQueryKey は tid ごとに別キーになる（キャッシュ分離）", () => {
    expect(channelMessagesQueryKey(1)).not.toEqual(channelMessagesQueryKey(2));
    expect(channelMessagesQueryKey(1)).toContain(1);
  });

  it("useChannelMessages は取得失敗を isError で伝える", async () => {
    vi.mocked(api.fetchChannelMessages).mockRejectedValue(new Error("boom"));
    const { wrapper } = createWrapper();

    const { result } = renderHook(() => useChannelMessages(1), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
