---
status: accepted
date: 2026-06-01
decision-makers: [Ryuto]
consulted: []
informed: [dev team]
---

# ADR-0001: TDD/BDD と Google テストピラミッドを開発方法論として採用

## Context and Problem Statement
本リポジトリは Coding Agent（Claude Code）に自走開発させる。回帰の検知・設計駆動・エージェントの暴走防止のため、明文化された開発方法論が必要。

## Decision Drivers
- エージェントが「テスト先行」を守れるよう機械的に強制したい。
- 速いフィードバック（小さなテスト多数）と、E2E の最小化。
- チーム既存リポ（agentic-music-studio / engineer-coffee-nara）の規範との整合。

## Considered Options
1. TDD なし（実装中心、後追いテスト）
2. テスト後追い + カバレッジ目標のみ
3. **t-wada/Kent Beck 流 TDD（Red→Green→Refactor）＋ BDD ＋ Google テストピラミッド**

## Decision Outcome
採用: **Option 3**。Red→Green→Refactor を別コミットで実施し、Plan ファイルの `- [ ]` ToDo を真実源とする（1 ToDo=1 BDD=1 Red）。テストは Google SWE Book Ch.11 の **small≫medium>large（約 80/15/5）** で構成。

## Consequences
### Positive
- 設計が駆動され、回帰を素早く検知。エージェントのガードレールになる。
### Negative
- 各機能でテスト先行の手数が増える（が AI で低コスト化）。

## Confirmation
- [x] `.claude/rules/{tdd-enforcement,test-pyramid}.md` と `tools/check_tdd.sh`（存在・実行可）、CI のピラミッド順で担保。M1–M4 を Red→Green→Refactor で実装し、`phpunit --list-suites`＝Unit 80 / Kernel 76 / Functional 15（small≫medium>large）、`make ci-local` 緑で確認（2026-06-16）。

## More Information
- Google「Just Say No to More End-to-End Tests」/ SWE at Google Ch.11
- t-wada https://t-wada.hatenablog.jp/ ・ 関連: ADR-0010, ADR-0011
