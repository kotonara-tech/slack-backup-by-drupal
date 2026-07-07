---
title: フロントエンド全文検索（M5）データフロー仕様
status: confirmed
audience: 開発者（M5 検索実装者・フロント保守）
diataxis: reference
related:
  - docs/spec/jsonapi-search.md
  - docs/spec/frontend-browse.md
  - docs/adr/0005-headless-drupal-jsonapi-search.md
  - docs/adr/0007-nextjs-next-drupal-react-frontend.md
---

# フロントエンド全文検索（M5）データフロー仕様（確定仕様）

本書は M5「全文検索」（語で検索し facet で絞り込む）の **フロント側データフローと描画契約**（what）である。バックエンド契約（エンドポイント・facets 形状・既知の制約）は [jsonapi-search.md](./jsonapi-search.md)、閲覧側は [frontend-browse.md](./frontend-browse.md)。決定経緯は [ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md)（JSON:API＋Search API DB）と [ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)（フロント技術選定）。**新規 ADR なし**（両 ADR の範囲内）。

スタック・取得方針は M4 と共通（client-side TanStack Query＋raw JSON:API＋pure mapper）。本 M5 は `/jsonapi/index/slack_messages`（`jsonapi_search_api`）と `meta.facets` を消費する点が M4 と異なる。

---

## 1. レイヤ構成

| レイヤ | ファイル | 役割 |
|---|---|---|
| 型 | `lib/types/search.ts` | `SearchFilters`／`Facet`／`FacetTerm`／`SearchResultPage` |
| データアクセス | `lib/search-api.ts` | クエリビルダ＋fetcher＋facet mapper＋toggle |
| フック | `lib/hooks/useSearch.ts` | TanStack Query（検索結果＋facets） |
| UI | `components/{SearchBar,FacetSidebar,SearchResultItem,SearchResultList}.tsx` | Mantine 描画 |
| 統合 | `components/BrowsePanel.tsx` | 閲覧⇄検索のモード切替 |

### 型（`lib/types/search.ts`）

- `SearchFilters = { fulltext: string; channel?: string; slackUser?: string; postedAt?: string; offset: number }`。
- `Facet = { id; label; path; terms: FacetTerm[] }`、`FacetTerm = { value; count; active }`。
- `SearchResultPage = { messages: SlackMessage[]; facets: Facet[]; totalCount: number | null; hasNext: boolean }`。

---

## 2. クエリ生成（`buildSearchParams`）

`SearchFilters` を `drupal-jsonapi-params` の querystring へ変換する。ページサイズは `SEARCH_PAGE_SIZE = 20`。

| filter | 生成されるパラメータ | 備考 |
|---|---|---|
| `fulltext` | `filter[fulltext]=<語>` | 必須（非空のときのみ検索実行） |
| `channel` | `filter[channel]=<name>` | facet 値（`channel_name`） |
| `slackUser` | `filter[slack_user]=<name>` | facet 値（`slack_user_name`） |
| `postedAt` | `filter[posted_at][value]=<ISO>`＋`filter[posted_at][operator]=>=` | 以降を絞る |
| （ページング） | `page[limit]=20`・`page[offset]=<offset>` | §5 |
| （並び） | `sort=-posted_at` | 新しい順で固定 |

各 facet フィルタは省略可（未指定なら当該パラメータを出さない）。パラメータ名はバックエンド契約（[jsonapi-search.md §3](./jsonapi-search.md)）に一致させる。

---

## 3. 取得（`fetchSearchResults`）

- next-drupal client の `buildUrl("/jsonapi/index/slack_messages", params.getQueryObject())` で URL を組み、`fetch` で取得する。
- **`getResourceCollection` は index ルート（`/jsonapi/index/*`）に非対応**のため使わない（通常の JSON:API コレクションではなく `jsonapi_search_api` の検索ルートのため）。
- レスポンスの `data` を M4 と同じ `mapMessage` でドメイン型へ整形（attributes ＋ relationship の `data.id` のみ・include 非依存）。author 名・channel 名の解決は M4 の lookup マップ方針を踏襲。
- `hasNext` は `links.next` の有無を優先し、無ければフォールバックで `data.length === 20`（= `SEARCH_PAGE_SIZE`）で判定する。
- `totalCount` は取得できれば数値、得られなければ `null`（UI は非 null 時のみ「全 N 件」を表示）。

---

## 4. facets の消費（`mapFacets` / `toggleFacetValue`）

- `mapFacets` は `meta.facets`（[jsonapi-search.md §4](./jsonapi-search.md)）を `Facet[]` へ整形する。**`meta.facets` 欠落に耐性**を持つ（`empty_behavior: none` によりヒット 0 の facet が配列から省略されても throw せず、欠落 facet は空扱い）。
- `toggleFacetValue(filters, facetId, value)` は現在の `SearchFilters` に facet 値を適用/解除する純関数：
  - 未適用の値をクリック → 当該 facet に値を設定。
  - **同じ値を再クリック → 解除**（トグル）。
  - いずれの場合も `offset` を **0 にリセット**（絞り込み変更で 1 ページ目へ戻す）。

### 適用中フィルタ chip（`FacetSidebar`）

`empty_behavior: none` の結果、絞り込みで 0 件になった facet は `meta.facets` から消える。これに対応するため、`FacetSidebar` は **facets レスポンスではなく state（`SearchFilters`）を起点**に「適用中フィルタ」を chip として描画する。facet が省略されても chip から解除できる（デッドロック回避）。facet 一覧は各 term の件数を表示し、active な値を強調する。

---

## 5. ページング

- 1 ページ `SEARCH_PAGE_SIZE = 20`。`page[offset]` を進めて前へ/次へで移動する（無限スクロールではなく明示ページング）。
- `SearchResultList` が「前へ／次へ」ボタンを出す（`hasNext` が false のとき「次へ」を無効化、`offset === 0` のとき「前へ」を無効化）。
- facet の適用/解除・クエリ変更時は `offset = 0` に戻す（§4）。

---

## 6. ハイライトと描画・セキュリティ

- **ハイライトはクライアント側**で行う。バックエンドは highlight プロセッサ未使用でハイライトを返さないため、Mantine `Highlight` が検索語に一致する箇所を `<mark>` で強調する。
- **`dangerouslySetInnerHTML` は使わない**（`Highlight` は React 要素としてマークするため XSS なし。本文・author 名等はすべて Slack 由来 untrusted → 標準エスケープ）。
- `SearchResultItem` は 1 ヒットを描画（M4 の `MessageCard` と整合する author/時刻/本文＋ハイライト）。
- `SearchResultList` の状態出し分け：loading（アクセシブル Loader）／error（`role="alert"`）／0 件／一覧。`totalCount` 非 null のときのみ「全 N 件」を表示。

---

## 7. フック（`useSearch`）

- TanStack Query。`SearchFilters` をキーに検索結果＋facets を取得。
- **`enabled: fulltext 非空`**（空クエリでは検索しない）。
- **`placeholderData: keepPreviousData`**（ページ送り・facet 変更中に前ページ結果を保持しちらつきを抑える）。

---

## 8. 画面統合（`BrowsePanel` の検索モード）

`BrowsePanel` が閲覧（M4）と検索（M5）を 1 画面で切り替える：

- `AppShell.Header` に `SearchBar`（submit 型：Enter またはボタンで確定、× でクリア）。
- **検索中**（`fulltext` 非空）：`AppShell.Navbar` ＝ `FacetSidebar`、`AppShell.Main` ＝ `SearchResultList`。
- **クリア**（× で `fulltext` を空に）：閲覧モードへ復帰し、選択中チャンネルは保持する。

---

## 9. テスト

- small（Vitest + Testing Library）：`buildSearchParams`/`fetchSearchResults`、`mapFacets`/`toggleFacetValue`、`SearchBar`、`FacetSidebar`、`useSearch`、`SearchResultList`、`BrowsePanel` の検索モード切替。JSON:API／facets 応答は fixture でモック。
- large（Playwright）：`tests/e2e/search.spec.ts`（`page.route` で全モック → 語で検索 → facet 絞り込み → 結果表示 → screenshot）。

---

## 10. 既知の制約

- **CJK 2 文字クエリ非対応**：`search_api_db` の bigram（`min_chars: 3`）により 2 文字の日本語単語は索引されず一致しない（[jsonapi-search.md §9.1](./jsonapi-search.md)）。フロント側の回避策はなく、Solr / Typesense 移行時に別 ADR で対処する（[ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md)）。
- ハイライトはクライアント側の単純一致（backend の tokenization とは独立）。
- facet は channel / slack_user / posted_at の 3 種（[jsonapi-search.md §4.1](./jsonapi-search.md)）。

---

## 11. 関連ドキュメント

- バックエンド契約: [jsonapi-search.md](./jsonapi-search.md)
- 閲覧側フロント: [frontend-browse.md](./frontend-browse.md)
- 決定: [ADR-0005](../adr/0005-headless-drupal-jsonapi-search.md)（JSON:API＋Search API DB）, [ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)（フロント技術選定）
