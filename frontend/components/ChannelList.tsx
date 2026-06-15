"use client";

/**
 * チャンネルサイドバー（presentational）。
 * public チャンネルのみを受け取り（匿名で取得できるのは public のみ）、
 * クリックで tid を onSelect する。選択中は active 表示。
 */
import { NavLink, Stack, Text } from "@mantine/core";

import type { Channel } from "@/lib/types/slack";

interface ChannelListProps {
  channels: Channel[];
  selectedTid: number | null;
  onSelect: (tid: number) => void;
}

export function ChannelList({ channels, selectedTid, onSelect }: ChannelListProps) {
  if (channels.length === 0) {
    return (
      <Text size="sm" c="dimmed" data-testid="channel-list-empty">
        チャンネルがありません
      </Text>
    );
  }

  return (
    <Stack gap={2} data-testid="channel-list">
      {channels.map((channel) => (
        <NavLink
          key={channel.id}
          data-testid={`channel-${channel.tid}`}
          label={channel.name}
          leftSection={<Text span>#</Text>}
          active={channel.tid === selectedTid}
          onClick={() => onSelect(channel.tid)}
        />
      ))}
    </Stack>
  );
}
