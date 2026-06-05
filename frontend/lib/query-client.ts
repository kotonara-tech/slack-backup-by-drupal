import { QueryClient } from "@tanstack/react-query";

/**
 * Builds the shared TanStack Query client.
 *
 * Status polling is driven explicitly by useExportStatus' refetchInterval while
 * an export is running, so the global defaults disable refetch-on-focus and set
 * a small staleTime to avoid redundant authenticated GET /status calls when the
 * panel is idle/done and the tab regains focus.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
      },
    },
  });
}
