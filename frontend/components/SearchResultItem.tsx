"use client";

/**
 * 検索結果 1 件のカード（M5）。
 *
 * 本文のハイライトは Mantine `Highlight`（`highlight` に検索語をスペース区切りで
 * 渡す）で行う。内部で `<mark>` を使う安全な実装のため `dangerouslySetInnerHTML`
 * は使わない。channel 名・投稿者名は呼び出し側が lookup 済みの表示名を渡す
 * （include 非依存の方針は `lib/slack-api.ts` と同じ）。
 */
import { Anchor, Badge, Group, Highlight, Paper, Stack, Text } from "@mantine/core";

import { formatPostedAt } from "@/lib/format";
import type { SlackMessage } from "@/lib/types/slack";

interface SearchResultItemProps {
  message: SlackMessage;
  query: string;
  channelName?: string;
  authorName?: string;
}

export function SearchResultItem({
  message,
  query,
  channelName,
  authorName,
}: SearchResultItemProps) {
  const terms = query.split(/\s+/).filter(Boolean);

  return (
    <Paper p="sm" withBorder data-testid={`search-result-${message.id}`}>
      <Stack gap={4}>
        <Group gap="xs" align="baseline">
          {channelName && (
            <Anchor component="span" size="sm" fw={600} data-testid="result-channel">
              # {channelName}
            </Anchor>
          )}
          <Text fw={600} size="sm">
            {authorName || "Unknown"}
          </Text>
          <Text size="xs" c="dimmed">
            {formatPostedAt(message.postedAt)}
          </Text>
          {message.edited && (
            <Badge size="xs" variant="light" color="gray">
              編集済み
            </Badge>
          )}
        </Group>

        <Highlight
          size="sm"
          highlight={terms}
          data-testid="search-result-body"
          style={{ whiteSpace: "pre-wrap" }}
        >
          {message.body}
        </Highlight>
      </Stack>
    </Paper>
  );
}
