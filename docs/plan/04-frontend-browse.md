# 04. フロント：閲覧

Next.js + next-drupal + React で channel/message を閲覧する。担当：`frontend-implementer`。ADR-0007。

## ToDo
- [ ] (small/Vitest) DrupalClient：`NEXT_PUBLIC_DRUPAL_BASE_URL` から next-drupal クライアントを生成
- [ ] (small/Vitest) query builder：`drupal-jsonapi-params` で channel/日付フィルタの querystring を生成
- [ ] (small/Vitest) MessageCard / ChannelList：fixture の JSON:API ペイロードから描画
- [ ] (large/Playwright) channel 一覧 → thread 閲覧の happy path（主要画面 screenshot）

## 完了の定義
- フロントで channel を選び、メッセージ/スレッドを閲覧できる。

## 次
→ 05-frontend-search.md
