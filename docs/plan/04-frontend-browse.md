# 04. フロント：閲覧

Next.js + next-drupal + React で channel/message を閲覧する。担当：`frontend-implementer`。ADR-0007。

## ToDo
- [x] (small/Vitest) DrupalClient：`NEXT_PUBLIC_DRUPAL_BASE_URL` から next-drupal クライアントを生成（`getDrupalClient` memoized）
- [x] (small/Vitest) query builder：`drupal-jsonapi-params` で channel フィルタの querystring を生成（`field_channel.meta.drupal_internal__target_id`）
- [x] (small/Vitest) MessageCard / ChannelList：fixture の JSON:API ペイロードから描画（＋ThreadView/MessageList/BrowsePanel・mapper・groupIntoThreads・formatPostedAt・hooks）
- [x] (large/Playwright) channel 一覧 → thread 閲覧の happy path（`tests/e2e/browse.spec.ts`）。local（libnss3/libnspr4 導入後）＋CI(ubuntu) で緑（main run 27562781222）。

## 完了の定義
- フロントで channel を選び、メッセージ/スレッドを閲覧できる。 → **達成**（`/` に AppShell、public チャンネル選択→新しい順スレッド→返信展開、author/時刻/reaction/添付/編集を描画）。

## 実装メモ
- データフロー仕様：[docs/spec/frontend-browse.md](../../docs/spec/frontend-browse.md)。決定：ADR-0007（accepted）。
- raw JSON:API（`deserialize:false`）＋ pure mapper、include 非依存の author lookup マップ、orphan 救済スレッド再構成、固定 JST 表示、本文プレーン描画（XSS なし）。
- 多エージェントレビュー指摘 10 件対応（a11y: aria-expanded/loader 名/h1、モバイル Burger、エラー表示、テスト堅牢化）。Vitest 92 緑、tsc/eslint/build 緑。
- 申し送り解消（M5 で対応）：ページング（追加読み込み）を実装。`useChannelMessages` を `useInfiniteQuery` 化、`buildChannelMessagesParams(tid, offset)` に `page[offset]`、`MessageList` に「さらに読み込む」ボタン、`browse.spec.ts` に load-more シナリオ追加（[docs/spec/frontend-browse.md §9](../../docs/spec/frontend-browse.md)）。

## 次
→ 05-frontend-search.md
