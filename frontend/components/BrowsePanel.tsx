"use client";

/**
 * 閲覧パネル（M4 のトップ）。
 *
 * サイドバーで public チャンネルを選び、本文側に新しい順のスレッドを描画する。
 * データ取得は client-side TanStack Query（M1 と統一）。author 名は useUsers の
 * lookup マップで解決し（include 非依存）、スレッド再構成は groupIntoThreads（純関数）。
 */
import { useMemo, useState } from "react";
import { AppShell, Burger, Group, ScrollArea, Stack, Title } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";

import { ChannelList } from "@/components/ChannelList";
import { MessageList } from "@/components/MessageList";
import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import { buildUserMap } from "@/lib/slack-api";
import { groupIntoThreads } from "@/lib/threads";

export function BrowsePanel() {
  const [selectedTid, setSelectedTid] = useState<number | null>(null);
  const [navOpened, { toggle: toggleNav, close: closeNav }] = useDisclosure(false);

  const channels = useChannels();
  const users = useUsers();
  const messages = useChannelMessages(selectedTid);

  const userMap = useMemo(() => buildUserMap(users.data ?? []), [users.data]);
  const threads = useMemo(
    () => groupIntoThreads(messages.messages),
    [messages.messages],
  );

  const selectedChannel = channels.data?.find((c) => c.tid === selectedTid);

  const handleSelect = (tid: number) => {
    setSelectedTid(tid);
    closeNav(); // モバイルでは選択後にナビを閉じる。
  };

  return (
    <AppShell
      header={{ height: 48 }}
      navbar={{ width: 280, breakpoint: "sm", collapsed: { mobile: !navOpened } }}
      padding="md"
    >
      <AppShell.Header>
        <Group h="100%" px="sm" gap="sm">
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
        </Group>
      </AppShell.Header>

      <AppShell.Navbar p="sm">
        <ScrollArea type="hover">
          <ChannelList
            channels={channels.data ?? []}
            selectedTid={selectedTid}
            onSelect={handleSelect}
          />
        </ScrollArea>
      </AppShell.Navbar>

      <AppShell.Main>
        <Stack gap="md">
          <Title order={1} size="h3" data-testid="browse-heading">
            {selectedChannel ? `# ${selectedChannel.name}` : "Slack Portal"}
          </Title>
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
        </Stack>
      </AppShell.Main>
    </AppShell>
  );
}
