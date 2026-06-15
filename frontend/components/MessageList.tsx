"use client";

/**
 * スレッド一覧。チャンネル未選択 / 読み込み中 / 0 件 / 一覧 の各状態を出し分ける。
 */
import { Center, Loader, Stack, Text } from "@mantine/core";

import { ThreadView } from "@/components/ThreadView";
import type { Thread, User } from "@/lib/types/slack";

interface MessageListProps {
  threads: Thread[];
  userMap: Record<string, User>;
  channelSelected?: boolean;
  isLoading?: boolean;
  isError?: boolean;
}

export function MessageList({
  threads,
  userMap,
  channelSelected = false,
  isLoading = false,
  isError = false,
}: MessageListProps) {
  if (!channelSelected) {
    return (
      <Center mih={160}>
        <Text c="dimmed" data-testid="no-channel">
          チャンネルを選択してください
        </Text>
      </Center>
    );
  }

  if (isError) {
    return (
      <Center mih={160}>
        <Text c="red" role="alert" data-testid="messages-error">
          メッセージの取得に失敗しました
        </Text>
      </Center>
    );
  }

  if (isLoading) {
    return (
      <Center mih={160}>
        <Loader data-testid="messages-loading" aria-label="メッセージを読み込み中" />
      </Center>
    );
  }

  if (threads.length === 0) {
    return (
      <Center mih={160}>
        <Text c="dimmed" data-testid="no-messages">
          メッセージがありません
        </Text>
      </Center>
    );
  }

  return (
    <Stack gap="sm" data-testid="message-list">
      {threads.map((thread) => (
        <ThreadView key={thread.root.id} thread={thread} userMap={userMap} />
      ))}
    </Stack>
  );
}
