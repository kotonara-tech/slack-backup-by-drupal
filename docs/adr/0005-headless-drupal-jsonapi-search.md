---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0005: ヘッドレス Drupal API（JSON:API read-only ＋ Search API DB）

## Context and Problem Statement
別の TypeScript フロントから閲覧・全文検索できるよう、Drupal をヘッドレス backend として API 公開する。読み取り中心・件数は無料90日分で小規模。

## Decision Drivers
- 標準・キャッシュ容易・権限尊重の API。ローカルで追加依存の少ない検索。

## Considered Options
1. GraphQL（contrib）
2. カスタム REST
3. **コア JSON:API（read-only）＋ Search API DB backend ＋ jsonapi_search_api ＋ facets ＋ jsonapi_extras ＋ CORS**

## Decision Outcome
採用: **Option 3**。JSON:API はコア同梱・HTTP キャッシュ・エンティティ権限尊重。`/admin/config/services/jsonapi` で **read-only** 化、`jsonapi_extras` で不要リソース無効化・hardening。全文検索/facet は **Search API DB backend** を `jsonapi_search_api` で公開（`/jsonapi/index/slack_messages`）。CORS は frontend origin のみ許可。

## Consequences
### Positive
- 追加サービス不要（Solr 等なし）。フロントから標準的に消費可能。
### Negative
- 大規模化時は DB backend の性能限界（将来 Solr/Typesense へ superseding ADR）。

## Confirmation
- [x] Functional: JSON:API コレクション 200（published のみ）、write 405、`jsonapi_search_api` で全文/ facet ヒット（M3 実装・`SlackJsonApiReadOnlyTest` 他で検証済み）。

## More Information
- JSON:API security considerations / jsonapi_search_api docs。関連: ADR-0006, ADR-0008, ADR-0011。
