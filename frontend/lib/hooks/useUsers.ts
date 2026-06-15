/**
 * 全ユーザを取得する TanStack Query フック。
 * 一度取得してキャッシュし、author 名引きの lookup マップ（buildUserMap）に使う。
 */
import { useQuery } from "@tanstack/react-query";

import { fetchUsers } from "@/lib/slack-api";
import type { User } from "@/lib/types/slack";

export const USERS_QUERY_KEY = ["slack", "users"] as const;

export function useUsers() {
  return useQuery<User[]>({
    queryKey: USERS_QUERY_KEY,
    queryFn: () => fetchUsers(),
  });
}
