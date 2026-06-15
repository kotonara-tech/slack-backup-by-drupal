---
status: accepted
date: 2026-06-09
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0007: Next.js + next-drupal + React フロントエンド

## Context and Problem Statement
ヘッドレス Drupal を消費する社内ポータル UI を作る。チームは React 採用を希望し、next-drupal を基盤にしたい。

## Decision Drivers
- React 採用。Drupal JSON:API との一級統合。社内ツールに見合う複雑さ。

## Considered Options
1. React + Vite SPA（純 SPA）
2. **Next.js (App Router) + next-drupal（Chapter Three）+ React + TypeScript + Mantine + TanStack Query + drupal-jsonapi-params**
3. Nuxt/Vue

## Decision Outcome
採用: **Option 2**。Next.js は React のメタフレームワークで、`next-drupal` も Next.js(=React) 製＝「React 採用」を満たす。UI は Mantine（React）、データ取得は `@tanstack/react-query` ＋ `drupal-jsonapi-params`。`/jsonapi` と `/jsonapi/index/slack_messages`（jsonapi_search_api）を消費。

## Consequences
### Positive
- React の知見を活用。Drupal 統合が容易。SSR/ISR も選択肢。
### Negative
- Node ランタイムが必要（ローカルは compose/host、将来ホスティング考慮）。

## Confirmation
- [x] Vitest: fixture からカード/一覧描画・query 文字列生成（M4 閲覧で達成。`next build` 緑）。
- [x] Playwright: 閲覧 E2E（`tests/e2e/browse.spec.ts`）は local＋CI(ubuntu) で緑（main run 27562781222）。WSL2 local は Chromium の OS ライブラリ（`libnspr4`/`libnss3` 等）導入後に実行可。検索 E2E は M5。

## 確定事項（M4 で確認、2026-06-09）
- 版の整合（下記リスク）は **崩れず**：`next-drupal v2.0.1 × Next 15 × React 19 × Mantine 7` で `next build` まで緑。版 pin は不要だった。
- **JSON:API の消費方法**：`NextDrupal.getResourceCollection(type, { deserialize: false, params })` で **raw JSON:API（`{ data, included }`）** を取得し、pure な mapper でドメイン型へ整形する（決定的・テスト容易・jsona の暗黙整形に依存しない）。query は `drupal-jsonapi-params`。詳細は [docs/spec/frontend-browse.md](../spec/frontend-browse.md)。
- **include 非依存設計**：author 名は `useUsers` の lookup マップ（uuid→User）で解決し、per-message include に依存しない（堅牢性のため。`filter`＋`include` 自体は実 API で解決可と確認済）。

## リスク
- `next-drupal v2 × Next15 × React19` の整合が崩れる場合は Next14/React18 に pin（その際は superseding ADR か本 ADR の Confirmation 注記で版を確定）。→ **M4 時点では崩れず**（上記）。

## More Information
- next-drupal.org / chapter-three/next-drupal。関連: ADR-0005, ADR-0008。
