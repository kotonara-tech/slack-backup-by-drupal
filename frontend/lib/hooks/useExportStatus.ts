/**
 * エクスポート状態を取得する TanStack Query フック。
 * 実行中（running）の間だけポーリングする（refetchInterval）。
 */
import { useQuery } from "@tanstack/react-query";
import { fetchExportStatus, type ExportStatus } from "@/lib/slack-portal";

/** status クエリのキー（trigger 成功時の invalidate と共有）。 */
export const EXPORT_STATUS_QUERY_KEY = ["slack-portal", "export-status"] as const;

/** running 中のポーリング間隔（ミリ秒）。 */
const RUNNING_POLL_INTERVAL_MS = 2000;

export function useExportStatus() {
  return useQuery<ExportStatus>({
    queryKey: EXPORT_STATUS_QUERY_KEY,
    queryFn: fetchExportStatus,
    // running の間だけ自動再取得し、idle/done/error では止める。
    refetchInterval: (query) =>
      query.state.data?.status === "running" ? RUNNING_POLL_INTERVAL_MS : false,
  });
}
