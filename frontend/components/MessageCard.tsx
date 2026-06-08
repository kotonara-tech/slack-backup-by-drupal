"use client";

/**
 * メッセージ 1 件のカード。
 *
 * author 名は呼び出し側が lookup マップで解決した User を props で受け取り、
 * displayName→realName→slackUserId→Unknown の順でフォールバックする。
 * 本文は format=plain_text の processed 済みプレーンテキストを `white-space: pre-wrap`
 * で描画する（XSS 面ゼロ。dangerouslySetInnerHTML は使わない）。
 */
import { Anchor, Badge, Group, Paper, Stack, Text } from "@mantine/core";

import { formatPostedAt } from "@/lib/format";
import type { SlackMessage, User } from "@/lib/types/slack";

interface MessageCardProps {
  message: SlackMessage;
  author?: User;
}

function authorName(author?: User): string {
  if (!author) return "Unknown";
  return (
    author.displayName || author.realName || author.slackUserId || "Unknown"
  );
}

export function MessageCard({ message, author }: MessageCardProps) {
  return (
    <Paper p="sm" withBorder data-testid={`message-${message.id}`}>
      <Stack gap={4}>
        <Group gap="xs" align="baseline">
          <Text fw={600} size="sm">
            {authorName(author)}
          </Text>
          {author?.isBot && (
            <Badge size="xs" variant="light" color="gray">
              bot
            </Badge>
          )}
          <Text size="xs" c="dimmed">
            {formatPostedAt(message.postedAt)}
          </Text>
          {message.edited && (
            <Text size="xs" c="dimmed" data-testid="edited-marker">
              （編集済み）
            </Text>
          )}
        </Group>

        <Text size="sm" data-testid="message-body" style={{ whiteSpace: "pre-wrap" }}>
          {message.body}
        </Text>

        {message.reactions.length > 0 && (
          <Group gap={4}>
            {message.reactions.map((reaction) => (
              <Badge
                key={reaction.name}
                size="sm"
                variant="light"
                data-testid={`reaction-${reaction.name}`}
              >
                {reaction.name} {reaction.count}
              </Badge>
            ))}
          </Group>
        )}

        {message.attachments.length > 0 && (
          <Group gap={6}>
            {message.attachments.map((attachment) => (
              <Group gap={4} key={attachment.id}>
                <Text span size="xs" aria-hidden>
                  📎
                </Text>
                <Anchor
                  href={attachment.url}
                  size="xs"
                  data-testid={`attachment-${attachment.id}`}
                >
                  {attachment.filename}
                </Anchor>
              </Group>
            ))}
          </Group>
        )}
      </Stack>
    </Paper>
  );
}
