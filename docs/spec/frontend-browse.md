---
title: フロントエンド閲覧（M4）データフロー仕様
status: confirmed
audience: 開発者（M4 閲覧実装者・M5 検索実装者・フロント保守）
diataxis: reference
related:
  - docs/spec/jsonapi-search.md
  - docs/spec/data-model.md
  - docs/adr/0007-nextjs-next-drupal-react-frontend.md
  - docs/adr/0013-anonymous-readability-and-channel-privacy.md
---

# フロントエンド閲覧（M4）データフロー仕様（確定仕様）

本書は M4「閲覧」（チャンネルを選びメッセージ/スレッドを閲覧する）の **フロント側データフローと描画契約**（what）である。バックエンド契約は [jsonapi-search.md](./jsonapi-search.md)、決定経緯は [ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)。公開範囲は [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)。

スタック：Next.js 15(App Router) ＋ React 19 ＋ Mantine 7 ＋ TanStack Query 5 ＋ next-drupal 2 ＋ drupal-jsonapi-params 2。データ取得は **client-side TanStack Query**（M1 と統一）。

---

## 1. レイヤ構成

| レイヤ | ファイル | 役割 |
|---|---|---|
| クライアント | `lib/drupal.ts` | `getDrupalClient()`＝memoized `NextDrupal`（baseUrl は `NEXT_PUBLIC_DRUPAL_BASE_URL`、`/jsonapi` は付けない） |
| 型 | `lib/types/slack.ts` | ドメイン型（`Channel`/`User`/`SlackMessage`/`Reaction`/`Attachment`/`Thread`）＋ raw JSON:API 型 |
| データアクセス | `lib/slack-api.ts` | クエリビルダ＋fetcher＋pure mapper |
| ロジック | `lib/threads.ts`／`lib/format.ts` | `groupIntoThreads`（純関数）／`formatPostedAt` |
| フック | `lib/hooks/useChannels.ts`／`useUsers.ts`／`useChannelMessages.ts` | TanStack Query |
| UI | `components/{ChannelList,MessageCard,ThreadView,MessageList,BrowsePanel}.tsx`／`app/page.tsx` | Mantine 描画 |

---

## 2. データ取得方針（raw JSON:API ＋ mapper）

`NextDrupal.getResourceCollection(type, { deserialize: false, params })` で **raw JSON:API（`{ data, included }`）** を取得し、pure な mapper でドメイン型へ整形する。jsona の暗黙整形に依存せず、決定的でテスト容易（[ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)）。`params` は `drupal-jsonapi-params` の `getQueryObject()` を渡す。

| 取得 | type | params（`lib/slack-api.ts`） |
|---|---|---|
| `fetchChannels` | `taxonomy_term--slack_channels` | `name` 昇順・`page[limit]=200`（匿名→public のみ） |
| `fetchUsers` | `taxonomy_term--slack_users` | `page[limit]=200`（全件・1 フェッチ） |
| `fetchChannelMessages(tid, offset)` | `node--slack_message` | `filter[CHANNEL_FILTER_PATH]=<tid>`・`include=field_attachments`・`sort=-field_posted_at`・`page[limit]=100`・`page[offset]=<offset>` |

- **`CHANNEL_FILTER_PATH = "field_channel.meta.drupal_internal__target_id"`**（1 箇所に固定。実 API 検証済）。
- 1 ページ `CHANNEL_MESSAGES_PAGE_LIMIT = 100`。`buildChannelMessagesParams(tid, offset)` が `page[offset]` を付け、追加読み込みで offset を進める（§9）。
- `fetchChannelMessages` は `{ messages, hasNext }` を返す（`hasNext` は `links.next` 有無を優先、無ければ `length === 100` でフォールバック）。

### mapper の要点

- `mapMessage` は **attributes ＋ relationship の `data.id`（UUID）だけ**を使う（include 非依存）。`field_slack_user.data` が `null`（bot 等）でも throw せず `authorUuid=null`。
- `field_reactions` は **JSON 文字列** → `parseReactions` が try/catch で `Reaction[]` 化（壊れた入力は `[]`）。
- 添付は `field_attachments` の参照 UUID を `included` の `file--file`（`buildFileMap`）で解決。`fileMap` に無い添付（private＝`hook_file_download` で遮断）は黙って除外。

---

## 3. include 非依存の名前解決（lookup マップ）

per-message include に依存せず、**lookup マップ**で名前を解決する：

- **author 名**：`useUsers()` で全 `User` を 1 回取得 → `buildUserMap`（uuid→User）で `MessageCard` の表示名を解決。
  フォールバック順 `displayName → realName → slackUserId → "Unknown"`。
- **channel 名**：選択中チャンネル（`useChannels` の結果）から解決。

> `filter`＋`include` は実 API で解決可能と確認済だが、堅牢性（include の不確実性回避・ユーザ 1 フェッチのキャッシュ効率）のため lookup マップを採用（[ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)）。

---

## 4. スレッド再構成（`groupIntoThreads`、純関数）

取得順に依存しないスレッド再構成（`lib/threads.ts`）：

- **root**：`thread_ts === slack_ts`（親）または `thread_ts == null`（standalone）。
- **返信**：`thread_ts` が親の `slack_ts` のものを親 root に束ねる（`thread_broadcast` も返信扱い）。
- **orphan**：親が取得範囲外（100 件外など）の返信は **単独 root として救済**（データ欠落ゼロ）。
- 整列：root は新しい順（`slack_ts` 降順）、返信は時系列昇順。

`Thread = { root, replies, replyCount }`。`ThreadView` が親を常時描画し、返信を件数トグル（`useDisclosure`＋`Collapse`、`aria-expanded`/`aria-controls`）で展開する。

---

## 5. 投稿時刻の表示（`formatPostedAt`）

JSON:API の `field_posted_at` は **offset 付き ISO**（例 `2026-05-17T15:38:27+09:00`）。表示は固定の **Asia/Tokyo**・固定書式 `YYYY/MM/DD HH:mm` に正規化する（`Intl.DateTimeFormat` ＋ `formatToParts` で組み立て、`hourCycle:"h23"`）。CI マシンの TZ/ロケールに非依存（`DISPLAY_TIME_ZONE` を 1 箇所に固定）。

---

## 6. 本文描画とセキュリティ

- 本文は `field_body.value`（`format=plain_text` の processed 済みプレーンテキスト）を **`white-space: pre-wrap`** で描画する。
- **`dangerouslySetInnerHTML` は使わない**（author 名・reaction 名・チャンネル名・添付名はすべて Slack 由来＝untrusted のため、React の標準エスケープで描画）。

---

## 7. 画面構成と状態

- `app/page.tsx`（トップ＝閲覧画面）→ `BrowsePanel`（`"use client"`）。プロバイダ（Mantine／TanStack Query）は `app/layout.tsx` の `Providers` が wrap 済。
- `BrowsePanel`＝Mantine `AppShell`：`AppShell.Navbar`＝`ChannelList`、`AppShell.Main`＝`MessageList`、`AppShell.Header`＝`Burger`（`hiddenFrom="sm"`、モバイルで navbar 開閉）。主見出しは `h1`。
- `useChannelMessages(tid)` は `enabled: tid != null` の依存クエリ（tid ごとにキー分離）。ページングは `useInfiniteQuery`（各ページの `messages` を平坦化して返す。§9）。
- `MessageList` の状態出し分け：未選択（案内）／読込中（アクセシブル Loader）／取得失敗（`role="alert"`）／0 件／一覧。末尾に「さらに読み込む」ボタン（`hasNextPage` 時のみ・読込中は loader）。

---

## 8. テスト

- small（Vitest + Testing Library）：builder/mapper/`groupIntoThreads`/`formatPostedAt`/hooks/各コンポーネント。JSON:API 応答は `tests/fixtures/jsonapi.ts`（raw＋ドメインの 2 形態）でモック。
- large（Playwright）：`tests/e2e/browse.spec.ts`（`page.route` で全モック→チャンネル選択→スレッド閲覧→返信展開→screenshot）。local＋CI(ubuntu) で green 確認済（main run 27562781222）。e2e は `make ci-local` には含まれず `/local-ci e2e` で実行する。

---

## 9. ページング（追加読み込み）・後続

- チャンネル閲覧は `useInfiniteQuery`（`useChannelMessages`）でページングする。`buildChannelMessagesParams(tid, offset)` が `page[offset]` を進め、`fetchChannelMessages` が返す `{ messages, hasNext }` の `hasNext`（`links.next` 有無を優先、無ければ `length === 100`）で次ページ有無を判定する。
- `MessageList` は末尾に「さらに読み込む」ボタンを出す（`hasNextPage` 時のみ・読込中は loader）。取得済み全ページを平坦化し `groupIntoThreads` にかける（orphan 救済は取得済み範囲に対して働く）。
- 全文検索 UI は [docs/spec/frontend-search.md](./frontend-search.md)（M5）。`/jsonapi/index/slack_messages` と facets を消費する。検索側は明示ページング（`SEARCH_PAGE_SIZE=20`）。
- 添付は public メッセージにはほぼ存在しない（private は backend で遮断）。

---

## 10. 関連ドキュメント

- バックエンド契約: [jsonapi-search.md](./jsonapi-search.md)
- データモデル: [data-model.md](./data-model.md)
- 決定: [ADR-0007](../adr/0007-nextjs-next-drupal-react-frontend.md)（フロント技術選定）, [ADR-0013](../adr/0013-anonymous-readability-and-channel-privacy.md)（公開範囲）
