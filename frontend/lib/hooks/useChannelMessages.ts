/**
 * 選択中チャンネル（tid）のメッセージを取得する依存クエリ。
 * tid が null（未選択）の間は無効化してフェッチしない（enabled）。
 */
import { useQuery } from "@tanstack/react-query";

import { fetchChannelMessages } from "@/lib/slack-api";
import type { SlackMessage } from "@/lib/types/slack";

export function channelMessagesQueryKey(tid: number | null) {
  return ["slack", "channel-messages", tid] as const;
}

export function useChannelMessages(tid: number | null) {
  return useQuery<SlackMessage[]>({
    queryKey: channelMessagesQueryKey(tid),
    queryFn: () => fetchChannelMessages(tid as number),
    enabled: tid != null,
  });
}
