# 03. JSON:API ＋ Search API ＋ jsonapi_search_api

ヘッドレス API と全文検索を公開する。担当：`drupal-backend-implementer`。ADR-0005, ADR-0008。

## ToDo
- [x] (large) JSON:API read-only：`/jsonapi/node/slack_message` が published を返し、POST/PATCH/DELETE が 403/405
- [x] (medium) Search API DB index：`slack_message`（body・title・channel・user・posted_at）を索引化し、クエリでヒット（published-only; `entity_status` + `content_access` プロセッサ）
- [x] (large) jsonapi_search_api：`/jsonapi/index/slack_messages?filter[fulltext]=...` が全文検索結果を返す
- [x] (large) facets：channel / posted_at / user で facet が返る（`meta.facets`; `empty_behavior: none`）
- [x] (medium) jsonapi_extras：不要リソース無効化・read-only hardening（`default_disabled: true`; ホワイトリスト 4 リソース）
- [x] (medium) **ファイル添付のプライバシー（ADR-0013 既知ギャップの追随）**：canonical アーカイブを `private://slack_archive/latest/` へ移行（ADR-0014）。`hook_file_download` で参照 node のアクセス権に基づき配信制御。`hook_taxonomy_term_access` で private チャンネル term を匿名から隠蔽。
- [x] (large) CORS：frontend origin（http://localhost:3000）からの preflight/GET が通る（`services.yml cors.config`）
- [x] ADR-0005 / ADR-0008 を `accepted` に（documentor）

## 完了の定義
- フロントから JSON:API と検索エンドポイントを匿名 read で叩け、write は拒否される。

## 補足・既知の制約

### CJK（日本語）全文検索
`search_api_db` backend は bigram（`min_chars: 3`、`overlap_cjk: 1`）。2 文字以下の日本語クエリはヒットしない場合がある。将来 Solr 等への移行は superseding ADR で対処（ADR-0005 参照）。

### テスト結果（M3 完了時点）
PHPUnit small（Unit）≫ medium（Kernel）＋ large（Functional）合計約 168 テスト緑。PHPStan L5・PHPCS clean。

## 次
→ 04-frontend-browse.md
