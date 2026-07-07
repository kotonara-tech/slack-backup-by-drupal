"use client";

/**
 * 検索結果リスト（M5）。loading/error/0件/結果ありを出し分け、ページングする。
 *
 * channel/author の表示名は呼び出し側（BrowsePanel）から uuid→エンティティの
 * lookup マップ（`channelMap`/`userMap`）で受け取り、include 非依存で解決する
 * （`lib/slack-api.ts` の author 名解決と同じ方針）。
 */
import { Button, Center, Group, Loader, Stack, Text } from "@mantine/core";

import { SearchResultItem } from "@/components/SearchResultItem";
import type { Channel, User } from "@/lib/types/slack";
import type { SearchFilters, SearchResultPage } from "@/lib/types/search";

interface SearchResultListProps {
  page: SearchResultPage | undefined;
  filters: SearchFilters;
  isLoading: boolean;
  isError: boolean;
  /**
   * `keepPreviousData` により前回データを保持したまま次のフェッチが進行中か
   * （facet/ページ切替の遷移中）。true の間は件数表示を「更新中」に切り替え、
   * ページングボタンを disabled にして連打による offset 暴走を防ぐ。
   */
  isPlaceholderData?: boolean;
  query: string;
  channelMap: Record<string, Channel>;
  userMap: Record<string, User>;
  onPrev: () => void;
  onNext: () => void;
}

function authorName(userMap: Record<string, User>, uuid: string | null): string | undefined {
  if (!uuid) return undefined;
  const user = userMap[uuid];
  if (!user) return undefined;
  return user.displayName || user.realName || user.slackUserId || undefined;
}

function channelName(channelMap: Record<string, Channel>, uuid: string | null): string | undefined {
  if (!uuid) return undefined;
  return channelMap[uuid]?.name;
}

export function SearchResultList({
  page,
  filters,
  isLoading,
  isError,
  isPlaceholderData = false,
  query,
  channelMap,
  userMap,
  onPrev,
  onNext,
}: SearchResultListProps) {
  if (isError) {
    return (
      <Center mih={160}>
        <Text c="red" role="alert" data-testid="search-error">
          検索に失敗しました
        </Text>
      </Center>
    );
  }

  if (isLoading || !page) {
    return (
      <Center mih={160}>
        <Loader data-testid="search-loading" aria-label="検索結果を読み込み中" />
      </Center>
    );
  }

  if (page.messages.length === 0) {
    return (
      <Stack gap="sm" align="center">
        <Center mih={160}>
          <Text c="dimmed" data-testid="search-no-results">
            検索結果がありません
          </Text>
        </Center>
        {filters.offset > 0 && (
          // 万一 hasNext の誤判定等で空ページに入っても「前へ」で戻れるようにする
          // （防御的フォールバック。offset>0 のときのみ描画）。
          <Button data-testid="search-prev" variant="default" onClick={onPrev}>
            前へ
          </Button>
        )}
      </Stack>
    );
  }

  const from = filters.offset + 1;
  const to = filters.offset + page.messages.length;

  return (
    <Stack gap="sm" data-testid="search-result-list">
      <Group gap="xs">
        <Text size="sm" c="dimmed" role="status">
          {isPlaceholderData ? (
            "更新中…"
          ) : (
            <>
              {from}–{to} 件目
              {page.totalCount != null && <> / 全 {page.totalCount} 件</>}
            </>
          )}
        </Text>
        {isPlaceholderData && (
          <Loader size="xs" data-testid="search-updating" aria-hidden="true" />
        )}
      </Group>

      <Stack gap="sm" style={{ opacity: isPlaceholderData ? 0.6 : 1 }}>
        {page.messages.map((message) => (
          <SearchResultItem
            key={message.id}
            message={message}
            query={query}
            channelName={channelName(channelMap, message.channelUuid)}
            authorName={authorName(userMap, message.authorUuid)}
          />
        ))}
      </Stack>

      <Group justify="center" gap="sm">
        <Button
          data-testid="search-prev"
          variant="default"
          disabled={filters.offset <= 0 || isPlaceholderData}
          onClick={onPrev}
        >
          前へ
        </Button>
        <Button
          data-testid="search-next"
          variant="default"
          disabled={!page.hasNext || isPlaceholderData}
          onClick={onNext}
        >
          次へ
        </Button>
      </Group>
    </Stack>
  );
}
