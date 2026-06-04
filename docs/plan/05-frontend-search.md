# 05. フロント：全文検索

jsonapi_search_api を使った全文検索 UI。担当：`frontend-implementer`。ADR-0005, ADR-0007。

## ToDo
- [ ] (small/Vitest) 検索クエリ：入力 → `/jsonapi/index/slack_messages?filter[fulltext]=...` の querystring を生成
- [ ] (small/Vitest) facet UI：channel/日付/user の facet 表示と絞り込み state
- [ ] (small/Vitest) 結果リスト：ヒット描画・ページング・ハイライト
- [ ] (large/Playwright) E2E：語で検索 → channel 絞り込み → 結果表示（主要画面 screenshot）

## 完了の定義
- フロントで全文検索＋facet 絞り込みができ、E2E が緑。

## 以降
- 認証強化（Basic/Simple OAuth）、Solr/Typesense 移行、デプロイ（レンタルサーバ/IaaS）等は新規 ADR ＋ docs/plan を追加して進める。
