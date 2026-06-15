"use client";

/**
 * スレッド 1 件（親＋返信）。
 * 親メッセージを常に描画し、返信があれば件数つきトグルで Collapse 展開する。
 */
import { Box, Collapse, Stack, Text, UnstyledButton } from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";

import { MessageCard } from "@/components/MessageCard";
import type { Thread, User } from "@/lib/types/slack";

interface ThreadViewProps {
  thread: Thread;
  userMap: Record<string, User>;
}

function authorOf(uuid: string | null, userMap: Record<string, User>) {
  return uuid ? userMap[uuid] : undefined;
}

export function ThreadView({ thread, userMap }: ThreadViewProps) {
  const [opened, { toggle }] = useDisclosure(false);
  const { root, replies, replyCount } = thread;

  return (
    <Stack gap={4} data-testid={`thread-${root.id}`}>
      <MessageCard message={root} author={authorOf(root.authorUuid, userMap)} />

      {replyCount > 0 && (
        <Box pl="md">
          <UnstyledButton
            onClick={toggle}
            data-testid={`thread-toggle-${root.id}`}
            aria-expanded={opened}
            aria-controls={`thread-replies-${root.id}`}
          >
            <Text size="xs" c="blue">
              {opened ? "返信を隠す" : `${replyCount}件の返信を表示`}
            </Text>
          </UnstyledButton>

          <Collapse in={opened}>
            {opened && (
              <Stack
                gap={4}
                mt={4}
                id={`thread-replies-${root.id}`}
                data-testid={`thread-replies-${root.id}`}
              >
                {replies.map((reply) => (
                  <MessageCard
                    key={reply.id}
                    message={reply}
                    author={authorOf(reply.authorUuid, userMap)}
                  />
                ))}
              </Stack>
            )}
          </Collapse>
        </Box>
      )}
    </Stack>
  );
}
