---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0008: 社内ポータルの auth / CORS

## Context and Problem Statement
社内（チーム内）閲覧・検索ポータル。ヘッドレス構成での認証と CORS の方針を決める。過剰な認証は運用負荷。

## Decision Drivers
- 社内ツールに見合う最小の認証。ヘッドレス（別 origin）での CORS。

## Considered Options
1. OAuth2（Simple OAuth）必須
2. **匿名 read（公開コンテンツ）＋ ネットワーク境界 or リバプロ Basic 認証、CORS は frontend origin のみ許可**
3. Cookie セッション

## Decision Outcome
採用: **Option 2**。ローカル/社内ネットワーク前提で **匿名 read**（published の `slack_message` のみ）。CORS は Drupal `services.yml`（または cors 設定）で frontend origin（`http://localhost:3000` 等）のみ許可。**JSON:API は read-only**（ADR-0005）。将来クラウド公開時は **リバプロ Basic 認証** または **Simple OAuth**（PKCE）を superseding ADR で追加。

## Consequences
### Positive
- 運用が単純。トークン管理不要。
### Negative
- ネットワーク境界に依存。公開時は認証追加が必要。

## Confirmation
- [x] Functional: 匿名で read 200（published のみ）、write 405。CORS preflight が frontend origin（`http://localhost:3000`）で通る（M3 `services.yml cors.config` 実装・Functional テストで検証済み）。

## More Information
- Lullabot Decoupled Drupal / JSON:API permission。関連: ADR-0005, ADR-0009。
