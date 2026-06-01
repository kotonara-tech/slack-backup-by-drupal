import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // 画像など Drupal 由来のリモートは必要に応じて images.remotePatterns で許可する。
};

export default nextConfig;
