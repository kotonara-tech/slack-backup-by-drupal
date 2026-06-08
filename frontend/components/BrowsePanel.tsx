"use client";

/**
 * 閲覧パネル（M4 のトップ）。
 *
 * サイドバーで public チャンネルを選び、本文側に新しい順のスレッドを描画する。
 * データ取得は client-side TanStack Query（M1 と統一）。author 名は useUsers の
 * lookup マップで解決し（include 非依存）、スレッド再構成は groupIntoThreads（純関数）。
 */
import { useMemo, useState } from "react";
import { AppShell, ScrollArea, Stack, Title } from "@mantine/core";

import { ChannelList } from "@/components/ChannelList";
import { MessageList } from "@/components/MessageList";
import { useChannels } from "@/lib/hooks/useChannels";
import { useUsers } from "@/lib/hooks/useUsers";
import { useChannelMessages } from "@/lib/hooks/useChannelMessages";
import { buildUserMap } from "@/lib/slack-api";
import { groupIntoThreads } from "@/lib/threads";

export function BrowsePanel() {
  const [selectedTid, setSelectedTid] = useState<number | null>(null);

  const channels = useChannels();
  const users = useUsers();
  const messages = useChannelMessages(selectedTid);

  const userMap = useMemo(() => buildUserMap(users.data ?? []), [users.data]);
  const threads = useMemo(
    () => groupIntoThreads(messages.data ?? []),
    [messages.data],
  );

  const selectedChannel = channels.data?.find((c) => c.tid === selectedTid);

  return (
    <AppShell navbar={{ width: 280, breakpoint: "sm" }} padding="md">
      <AppShell.Navbar p="sm">
        <ScrollArea type="hover">
          <ChannelList
            channels={channels.data ?? []}
            selectedTid={selectedTid}
            onSelect={setSelectedTid}
          />
        </ScrollArea>
      </AppShell.Navbar>

      <AppShell.Main>
        <Stack gap="md">
          <Title order={3} data-testid="browse-heading">
            {selectedChannel ? `# ${selectedChannel.name}` : "Slack Portal"}
          </Title>
          <MessageList
            threads={threads}
            userMap={userMap}
            channelSelected={selectedTid != null}
            isLoading={messages.isLoading}
            isError={messages.isError}
          />
        </Stack>
      </AppShell.Main>
    </AppShell>
  );
}
