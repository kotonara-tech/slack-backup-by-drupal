/**
 * エクスポートをトリガする TanStack Query mutation フック。
 * 成功したら status クエリを invalidate して即時に再取得させる。
 */
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { triggerExport, type TriggerResult } from "@/lib/slack-portal";
import { EXPORT_STATUS_QUERY_KEY } from "@/lib/hooks/useExportStatus";

export function useTriggerExport() {
  const queryClient = useQueryClient();
  return useMutation<TriggerResult, Error>({
    mutationFn: triggerExport,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: EXPORT_STATUS_QUERY_KEY });
    },
  });
}
