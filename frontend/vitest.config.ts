import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

// small 層（Vitest + Testing Library）。E2E は Playwright（playwright.config.ts）。
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    include: ["tests/unit/**/*.{test,spec}.{ts,tsx}", "app/**/*.{test,spec}.{ts,tsx}", "lib/**/*.{test,spec}.{ts,tsx}"],
    setupFiles: [],
  },
});
