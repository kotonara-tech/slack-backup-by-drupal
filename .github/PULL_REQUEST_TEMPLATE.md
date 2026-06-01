## サマリ

<!-- 何を・なぜ。関連する docs/plan の ToDo を引用 -->

## ToDo 達成状況

<!-- Plan ファイル（~/.claude/plans/<slug>.md）の該当 ToDo を貼る -->
- [ ]

## 追加したテスト層（テストピラミッド）

- small（Unit / Vitest）:
- medium（Kernel）:
- large（Functional / Playwright）:

## 影響する ADR

<!-- 新規 / 改訂(supersede) / なし -->

## チェックリスト

- [ ] Red→Green→Refactor を別コミットで実施した
- [ ] `/local-ci all`（= `make ci-local`）が green
- [ ] `/review` を実行し結果を反映/添付した
- [ ] `/security-review` を実行した（token/PII 漏洩・JSON:API 露出面・CORS/権限）
- [ ] canonical アーカイブ / token をコミットしていない

🤖 Generated with [Claude Code](https://claude.com/claude-code)
