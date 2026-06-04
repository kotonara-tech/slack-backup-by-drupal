---
status: proposed
date: 2026-06-01
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
- [ ] Vitest: fixture からカード/一覧描画・query 文字列生成。Playwright: 閲覧＋検索 E2E。

## リスク
- `next-drupal v2 × Next15 × React19` の整合が崩れる場合は Next14/React18 に pin（その際は superseding ADR か本 ADR の Confirmation 注記で版を確定）。

## More Information
- next-drupal.org / chapter-three/next-drupal。関連: ADR-0005, ADR-0008。
