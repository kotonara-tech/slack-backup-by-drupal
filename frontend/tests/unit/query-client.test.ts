import { describe, it, expect } from "vitest";
import { createQueryClient } from "@/lib/query-client";

/**
 * small (Vitest): the shared QueryClient must disable refetch-on-focus and set
 * a staleTime so idle/done status is not re-fetched on every tab focus.
 */
describe("createQueryClient", () => {
  it("disables refetchOnWindowFocus and sets a non-zero staleTime", () => {
    const queries = createQueryClient().getDefaultOptions().queries;
    expect(queries?.refetchOnWindowFocus).toBe(false);
    expect(queries?.staleTime).toBeGreaterThan(0);
  });

  it("returns a fresh client instance each call", () => {
    expect(createQueryClient()).not.toBe(createQueryClient());
  });
});
