# 05. フロント：全文検索

jsonapi_search_api を使った全文検索 UI。担当：`frontend-implementer`。ADR-0005, ADR-0007。

## ToDo
- [x] (small/Vitest) 検索クエリ：入力 → `/jsonapi/index/slack_messages?filter[fulltext]=...` の querystring を生成
- [x] (small/Vitest) facet UI：channel/日付/user の facet 表示と絞り込み state
- [x] (small/Vitest) 結果リスト：ヒット描画・ページング・ハイライト
- [x] (large/Playwright) E2E：語で検索 → channel 絞り込み → 結果表示（主要画面 screenshot）

## 完了の定義
- フロントで全文検索＋facet 絞り込みができ、E2E が緑。 → **達成**（`SearchBar`＋`FacetSidebar`＋`SearchResultList` を `BrowsePanel` に統合、`tests/e2e/search.spec.ts` 緑）。

## 実装メモ
- データフロー仕様：[docs/spec/frontend-search.md](../../docs/spec/frontend-search.md)。決定：ADR-0005 / ADR-0007（accepted、範囲内・新規 ADR なし）。
- `buildSearchParams`（`filter[fulltext]`・facet フィルタ・`page[limit/offset]`・`sort=-posted_at`）／`mapFacets`（`empty_behavior:none` の facet 省略に耐性）／`toggleFacetValue`（同値クリックで解除・offset 0 リセット）。取得は `buildUrl("/jsonapi/index/slack_messages", ...)`＋`fetch`（`getResourceCollection` は index ルート非対応）。
- ハイライトはクライアント側（Mantine `Highlight`・`<mark>`・`dangerouslySetInnerHTML` 不使用）。適用中フィルタは state 起点の chip で 0 件 facet 省略時も解除可能。
- 既知の制約：CJK 2 文字クエリは `search_api_db` bigram（`min_chars:3`）で非対応（[jsonapi-search.md §9.1](../../docs/spec/jsonapi-search.md)、変更なし）。

## 以降
- 認証強化（Basic/Simple OAuth）、Solr/Typesense 移行、デプロイ（レンタルサーバ/IaaS）等は新規 ADR ＋ docs/plan を追加して進める。
