import type { NextConfig } from "next";
import { fileURLToPath } from "node:url";
import { dirname } from "node:path";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // このディレクトリをワークスペースルートに固定（親や $HOME の lockfile を誤検出させない）。
  outputFileTracingRoot: dirname(fileURLToPath(import.meta.url)),
  // 画像など Drupal 由来のリモートは必要に応じて images.remotePatterns で許可する。
};

export default nextConfig;
