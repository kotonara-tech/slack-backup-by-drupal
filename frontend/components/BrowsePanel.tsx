"use client";

/**
 * 閲覧パネル（M4 のトップ＋M5 全文検索の統合）。
 *
 * サイドバーで public チャンネルを選び、本文側に新しい順のスレッドを描画する
 * （閲覧モード）。ヘッダの SearchBar で検索語を確定すると検索モードへ切り替わり、
 * ナビは FacetSidebar・本文は SearchResultList になる（ChannelList は隠す）。
 * クリア（空文字 submit）で閲覧モードへ戻り、選択中チャンネルは保持する。
 *
 * データ取得は client-side TanStack Query（M1 と統一）。author/channel 名は
 * useUsers/useChannels の lookup マップで解決し（include 非依存）、スレッド
 * 再構成は groupIntoThreads（純関数）。
 */
import { useMemo, useState } from "react";
import { AppShell, Burger, Group, ScrollArea, Stack, Title } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";

import { ChannelList } from "@/components/ChannelList";
import { FacetSidebar } from "@/components/FacetSidebar";
import { MessageList } from "@/components/MessageList";
import { SearchBar } from "@/components/SearchBar";
import { SearchResultList } from "@/components/SearchResultList";
import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import { useSearch } from "@/lib/hooks/useSearch";
import { toggleFacetValue } from "@/lib/search-api";
import { buildUserMap } from "@/lib/slack-api";
import { groupIntoThreads } from "@/lib/threads";
import type { SearchFilters } from "@/lib/types/search";
import type { Channel } from "@/lib/types/slack";

const EMPTY_SEARCH_FILTERS: SearchFilters = { fulltext: "", offset: 0 };

export function BrowsePanel() {
  const [selectedTid, setSelectedTid] = useState<number | null>(null);
  const [navOpened, { toggle: toggleNav, close: closeNav }] = useDisclosure(false);
  const [searchFilters, setSearchFilters] = useState<SearchFilters | null>(null);

  const channels = useChannels();
  const users = useUsers();
  const messages = useChannelMessages(selectedTid);
  const search = useSearch(searchFilters ?? EMPTY_SEARCH_FILTERS);

  const userMap = useMemo(() => buildUserMap(users.data ?? []), [users.data]);
  const channelMap = useMemo(
    () =>
      Object.fromEntries(
        (channels.data ?? []).map((c: Channel) => [c.id, c]),
      ),
    [channels.data],
  );
  const threads = useMemo(
    () => groupIntoThreads(messages.messages),
    [messages.messages],
  );

  const selectedChannel = channels.data?.find((c) => c.tid === selectedTid);
  const isSearchMode = searchFilters != null;

  const handleSelect = (tid: number) => {
    setSelectedTid(tid);
    closeNav(); // モバイルでは選択後にナビを閉じる。
  };

  const handleSearch = (value: string) => {
    setSearchFilters(value === "" ? null : { fulltext: value, offset: 0 });
  };

  const handleFacetToggle = (
    facetId: "channel" | "slack_user" | "posted_at",
    value: string,
  ) => {
    setSearchFilters((prev) =>
      prev ? toggleFacetValue(prev, facetId, value) : prev,
    );
  };

  const handleSearchPrev = () => {
    setSearchFilters((prev) =>
      prev ? { ...prev, offset: Math.max(0, prev.offset - 20) } : prev,
    );
  };

  const handleSearchNext = () => {
    setSearchFilters((prev) => (prev ? { ...prev, offset: prev.offset + 20 } : prev));
  };

  return (
    <AppShell
      header={{ height: 48 }}
      navbar={{ width: 280, breakpoint: "sm", collapsed: { mobile: !navOpened } }}
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="sm" gap="sm" wrap="nowrap">
          <Burger
            opened={navOpened}
            onClick={toggleNav}
            hiddenFrom="sm"
            size="sm"
            aria-label="ナビゲーションを切り替え"
            aria-expanded={navOpened}
          />
          <Title order={2} size="h5">
            Slack Portal
          </Title>
          <SearchBar initialValue={searchFilters?.fulltext} onSearch={handleSearch} />
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <ScrollArea type="hover">
          {isSearchMode ? (
            <FacetSidebar
              facets={search.data?.facets ?? []}
              filters={searchFilters ?? EMPTY_SEARCH_FILTERS}
              onToggle={handleFacetToggle}
            />
          ) : (
            <ChannelList
              channels={channels.data ?? []}
              selectedTid={selectedTid}
              onSelect={handleSelect}
            />
          )}
        </ScrollArea>
      </AppShell.Navbar>

      <AppShell.Main>
        <Stack gap="md">
          <Title order={1} size="h3" data-testid="browse-heading">
            {isSearchMode
              ? "検索結果"
              : selectedChannel
                ? `# ${selectedChannel.name}`
                : "Slack Portal"}
          </Title>
          {isSearchMode ? (
            <SearchResultList
              page={search.data}
              filters={searchFilters ?? EMPTY_SEARCH_FILTERS}
              isLoading={search.isLoading}
              isError={search.isError}
              query={searchFilters?.fulltext ?? ""}
              channelMap={channelMap}
              userMap={userMap}
              onPrev={handleSearchPrev}
              onNext={handleSearchNext}
            />
          ) : (
            <MessageList
              threads={threads}
              userMap={userMap}
              channelSelected={selectedTid != null}
              isLoading={messages.isLoading}
              isError={messages.isError}
              hasNextPage={messages.hasNextPage}
              isFetchingNextPage={messages.isFetchingNextPage}
              onLoadMore={() => messages.fetchNextPage()}
            />
          )}
        </Stack>
      </AppShell.Main>
    </AppShell>
  );
}
