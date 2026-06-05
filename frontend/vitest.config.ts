import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import { fileURLToPath } from "node:url";

// small 層（Vitest + Testing Library）。E2E は Playwright（playwright.config.ts）。
export default defineConfig({
  plugins: [react()],
  resolve: {
    // tsconfig の `@/*` → プロジェクトルート。Vite/Vitest は tsconfig paths を
    // 自動採用しないため明示する。
    alias: {
      "@": fileURLToPath(new URL("./", import.meta.url)),
    },
  },
  test: {
    environment: "jsdom",
    globals: true,
    include: ["tests/unit/**/*.{test,spec}.{ts,tsx}", "app/**/*.{test,spec}.{ts,tsx}", "lib/**/*.{test,spec}.{ts,tsx}"],
    setupFiles: ["./tests/setup.ts"],
  },
});
