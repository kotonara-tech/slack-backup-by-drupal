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
      <Center mih={160}>
        <Text c="dimmed" data-testid="search-no-results">
          検索結果がありません
        </Text>
      </Center>
    );
  }

  const from = filters.offset + 1;
  const to = filters.offset + page.messages.length;

  return (
    <Stack gap="sm" data-testid="search-result-list">
      <Text size="sm" c="dimmed">
        {from}–{to} 件目
        {page.totalCount != null && <> / 全 {page.totalCount} 件</>}
      </Text>

      {page.messages.map((message) => (
        <SearchResultItem
          key={message.id}
          message={message}
          query={query}
          channelName={channelName(channelMap, message.channelUuid)}
          authorName={authorName(userMap, message.authorUuid)}
        />
      ))}

      <Group justify="center" gap="sm">
        <Button
          data-testid="search-prev"
          variant="default"
          disabled={filters.offset <= 0}
          onClick={onPrev}
        >
          前へ
        </Button>
        <Button
          data-testid="search-next"
          variant="default"
          disabled={!page.hasNext}
          onClick={onNext}
        >
          次へ
        </Button>
      </Group>
    </Stack>
  );
}
