---
status: accepted
date: 2026-06-08
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0014: canonical アーカイブを private:// に移行し Web 直配信を排除

## Context and Problem Statement

ADR-0004 では canonical アーカイブの保存先を `public://slack_archive/latest/` と定めた。`public://` は Drupal
のパブリックファイルシステム（通常 `web/sites/<site>/files/`）に解決され、nginx / Apache が当該ディレクトリを**直接配信**する。

アーカイブには PII（メッセージ本文・実名・ユーザ ID・DM 内容）と添付ファイルが含まれる。ADR-0013
（Milestone 2）はノードレベルの `status` gating を確立したが、**「既知のギャップ」として添付ファイルの
Web 直配信問題を M3 への申し送り**とした。具体的には:

- `public://slack_archive/latest/files/` 内の添付は nginx が URL を知っていれば直接配信できる。
- `public://slack_archive/latest/channels/<id>.json` は private チャンネルの本文を含む JSON ファイルであり、
  同様に直接アクセス可能。
- nginx の `location` ルールで特定サブディレクトリを選択遮断する方法はあるが、設定漏れや DDEV 環境の
  デフォルト設定変更に対して脆い。

## Decision Drivers

- PII（private チャンネル本文・DM・添付）を Web に露出させない（ADR-0009）。
- アクセス制御を Drupal のアクセス機構に乗せ、nginx 設定に依存しない（単一障害点の排除）。
- 実装を単純に保つ（過剰設計しない）。
- 将来の認証導入（ADR-0008 superseding）時にも同じ仕組みが機能する。

## Considered Options

1. **nginx `location` ブロックで `slack_archive/` への直接アクセスを拒否する**
2. `public://` のまま維持し、`file--file` JSON:API リソースを無効化する
3. **canonical アーカイブを `private://slack_archive/latest/` に移行し、添付を `hook_file_download` で制御する**（本 ADR）

## Decision Outcome

採用: **Option 3**。`CanonicalArchive::BASE_DIR` を `private://slack_archive/latest` に変更し、すべての
書き込み・読み込みをこのパスに統一する。

- **`private://`** は Drupal のプライベートファイルシステム（`$settings['file_private_path']` = web root 外のディレクトリ）に解決され、nginx は直接配信できない。
- **`hook_file_download()` (`slack_portal_file_download`)**：`slack_archive/` 配下のファイルへのリクエストを
  インターセプトし、そのファイルを `field_attachments` で参照している `slack_message` node の中にリクエスト者が
  閲覧可能（匿名 = published の public_channel のみ）なものが 1 件以上あれば配信許可、なければ `-1`（403）を返す。
- **`hook_taxonomy_term_access()` (`slack_portal_taxonomy_term_access`)**：管理者以外は `slack_channels`
  vocabulary で `field_channel_type != public_channel` の term を閲覧不可（`AccessResult::forbidden()`）。
  JSON:API コレクション・include で private / im / mpim チャンネル名が匿名から隠蔽される（ADR-0013 の補完）。

**本 ADR は ADR-0004 の保存先（`public://` の箇所）を変更する**。保存形式が canonical JSON であること、
MariaDB が最終 DB であること、migrate_plus で冪等取込することは ADR-0004 の決定のまま不変。

Option 1 は nginx 設定の変更漏れ・DDEV デフォルトでの露出リスクがあり脆い。Option 2 は JSON:API を無効化
しても nginx 直 URL が残るため解決にならない。

## Consequences

### Positive

- アーカイブ（JSON・添付）が Web に直接露出しない（nginx 設定に無関係）。
- 添付配信のアクセス制御が Drupal の node アクセス権に連動し、監査・拡張が容易。
- `$settings['file_private_path']` の設定のみで動作し、追加サービス不要。

### Negative

- **`$settings['file_private_path']` の設定が必須**（未設定だと `private://` が解決できず export が失敗する）。
  ローカル / DDEV での設定手順は `docs/how-to/private-files-setup.md`。
- public チャンネルの添付も Drupal 経由での配信になるため、nginx の静的ファイル配信より若干遅い
  （小規模・社内用途では許容範囲）。
- migrate `slack_canonical_files` で登録する file entity の URI が `private://` に変わるため、
  既存の `public://` 登録済みエンティティは再 import / rollback が必要（M3 re-import スモーク
  テストで確認済み）。

## Confirmation

- [x] 匿名で旧 `public://slack_archive/` 直 URL にアクセス → 404（ファイルが存在しないか、nginx が配信しない）。
- [x] private チャンネルの添付ファイル URI（`private://slack_archive/…`）に匿名 GET → 403。
- [x] public チャンネルの published node に紐づく添付 URI に匿名 GET → 200（`hook_file_download` が許可）。
- [x] `/jsonapi/taxonomy_term/slack_channels` コレクションに private / im / mpim チャンネル名が含まれない
      （`hook_taxonomy_term_access` による隠蔽）。
- [x] PHPUnit Functional テスト（`SlackJsonApiReadOnlyTest` など）で上記を検証済み。

## More Information

- 保存形式の決定（変更される保存先）: [ADR-0004](0004-storage-canonical-json-and-mariadb.md)
  （本 ADR により保存先が `private://` に変更される。保存形式の決定自体は ADR-0004 が正典）
- 添付プライバシーの既知ギャップ（本 ADR で解決）: [ADR-0013](0013-anonymous-readability-and-channel-privacy.md) — Consequences §Negative 末尾
- ヘッドレス API: [ADR-0005](0005-headless-drupal-jsonapi-search.md)（JSON:API + Search API DB）
- auth / CORS: [ADR-0008](0008-internal-portal-auth-and-cors.md)
- PII 取扱: [ADR-0009](0009-secrets-and-pii-handling.md)
- 仕様: [docs/spec/jsonapi-search.md §6](../spec/jsonapi-search.md)（プライバシー・ファイルアクセス制御）
- 設定方法: [docs/how-to/private-files-setup.md](../how-to/private-files-setup.md)
