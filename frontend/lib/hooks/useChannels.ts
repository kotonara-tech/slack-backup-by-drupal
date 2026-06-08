/**
 * public チャンネル一覧を取得する TanStack Query フック。
 * サイドバー表示と、メッセージの channel 名引きに使う。
 */
import { useQuery } from "@tanstack/react-query";

import { fetchChannels } from "@/lib/slack-api";
import type { Channel } from "@/lib/types/slack";

export const CHANNELS_QUERY_KEY = ["slack", "channels"] as const;

export function useChannels() {
  return useQuery<Channel[]>({
    queryKey: CHANNELS_QUERY_KEY,
    queryFn: () => fetchChannels(),
  });
}
