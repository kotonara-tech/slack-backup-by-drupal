/**
 * 選択中チャンネル（tid）のメッセージを取得する依存クエリ。
 * tid が null（未選択）の間は無効化してフェッチしない（enabled）。
 *
 * `page[limit]=100` 単位の追加読み込み（M4 ページング）に対応するため
 * `useInfiniteQuery` で offset ページを積み上げ、呼び出し側にはフラット化
 * した messages と hasNextPage/fetchNextPage/isFetchingNextPage を返す。
 */
import { useInfiniteQuery } from "@tanstack/react-query";
import { useMemo } from "react";

import {
  CHANNEL_MESSAGES_PAGE_LIMIT,
  fetchChannelMessages,
} from "@/lib/slack-api";

export function channelMessagesQueryKey(tid: number | null) {
  return ["slack", "channel-messages", tid] as const;
}

export function useChannelMessages(tid: number | null) {
  const query = useInfiniteQuery({
    queryKey: channelMessagesQueryKey(tid),
    queryFn: ({ pageParam }) => fetchChannelMessages(tid as number, pageParam),
    enabled: tid != null,
    initialPageParam: 0,
    getNextPageParam: (lastPage, allPages) =>
      lastPage.hasNext
        ? allPages.length * CHANNEL_MESSAGES_PAGE_LIMIT
        : undefined,
  });

  const messages = useMemo(
    () => query.data?.pages.flatMap((page) => page.messages) ?? [],
    [query.data],
  );

  return {
    messages,
    isLoading: query.isLoading,
    isError: query.isError,
    hasNextPage: query.hasNextPage,
    isFetchingNextPage: query.isFetchingNextPage,
    fetchNextPage: query.fetchNextPage,
  };
}
