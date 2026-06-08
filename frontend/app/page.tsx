import { BrowsePanel } from "@/components/BrowsePanel";

/**
 * トップページ＝閲覧画面（docs/plan/04）。
 * クライアント側の BrowsePanel を描画する薄い層。プロバイダ（Mantine /
 * TanStack Query）は app/layout.tsx の Providers が全体を wrap 済み。
 * 全文検索 UI は docs/plan/05（M5）で追加する。
 */
export default function HomePage() {
  return <BrowsePanel />;
}
