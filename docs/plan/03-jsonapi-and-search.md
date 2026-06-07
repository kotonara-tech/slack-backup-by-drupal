# 03. JSON:API ＋ Search API ＋ jsonapi_search_api

ヘッドレス API と全文検索を公開する。担当：`drupal-backend-implementer`。ADR-0005, ADR-0008。

## ToDo
- [ ] (large) JSON:API read-only：`/jsonapi/node/slack_message` が published を返し、POST/PATCH/DELETE が 403/405
- [ ] (medium) Search API DB index：`slack_message`（body・title・channel・user・posted_at）を索引化し、クエリでヒット
- [ ] (large) jsonapi_search_api：`/jsonapi/index/slack_messages?filter[fulltext]=...` が全文検索結果を返す
- [ ] (large) facets：channel / posted_at / user で facet が返る
- [ ] (medium) jsonapi_extras：不要リソース無効化・read-only hardening
- [ ] (medium) **ファイル添付のプライバシー（ADR-0013 既知ギャップの追随）**：private / im / mpim の添付（`public://slack_archive/latest/files/`）を匿名に列挙/直接 DL させない（`private://` へ移送 ／ `file--file` JSON:API リソース無効化 ／ `slack_archive/` URI のファイルアクセス制御 のいずれか）。published node 経由の添付のみ露出させる。
- [ ] (large) CORS：frontend origin（http://localhost:3000）からの preflight/GET が通る
- [ ] ADR-0005 / ADR-0008 を `accepted` に（documentor）

## 完了の定義
- フロントから JSON:API と検索エンドポイントを匿名 read で叩け、write は拒否される。

## 次
→ 04-frontend-browse.md
