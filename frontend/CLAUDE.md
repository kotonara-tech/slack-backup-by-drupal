# frontend（コンポーネント規約）

Next.js 15(App Router) + **React** + TypeScript + Mantine の社内ポータル UI。Drupal の JSON:API / jsonapi_search_api を消費する（閲覧・全文検索）。上位は ルート `CLAUDE.md` と `.claude/rules/`。担当エージェントは `frontend-implementer`。

## スタック
- React（`react`/`react-dom`）。UI は **Mantine**。データ取得は **next-drupal** ＋ **@tanstack/react-query** ＋ **drupal-jsonapi-params**。
- API ベース URL は `NEXT_PUBLIC_DRUPAL_BASE_URL`（`.env.local`）。直書きしない（`lib/drupal.ts`）。

## テスト（TDD・pytest は使わない）
- **small = Vitest + Testing Library**（`tests/unit/` または `app/`/`lib/` 同居の `*.test.tsx`）。JSON:API 応答は fixture でモック。
- **large = Playwright**（`tests/e2e/`）。seed 済み Drupal に対する閲覧・検索 E2E（主要画面 screenshot）。
- 静的解析：`npm run typecheck`（`tsc --noEmit`）＋ `npm run lint`（eslint）。
- 実行：`npx vitest run` / `npx playwright test` / `npm run build`。

## 規約
- App Router・Server/Client Components を適切に。Client 側プロバイダは `app/providers.tsx`。
- 新規コードにはテストを随伴（Red 先行）。ライブラリ選定・API 契約変更は設計判断 → ADR（`documentor`）。
- backend（`web/`・PHP・composer）には触れない。

## ローカル実行
- `cd frontend && npm run dev`（host）または `docker compose up frontend`。Drupal は DDEV（`ddev start`）。CORS は Drupal 側で frontend origin を許可。
