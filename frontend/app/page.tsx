import { Container, Title, Text } from "@mantine/core";

/**
 * プレースホルダのトップページ。
 * channel 閲覧 (docs/plan/04) と全文検索 (docs/plan/05) を TDD で実装していく。
 */
export default function HomePage() {
  return (
    <Container size="md" py="xl">
      <Title order={1}>Slack Portal</Title>
      <Text mt="md" c="dimmed">
        社内 Slack アーカイブの閲覧・全文検索ポータル（骨組み）。機能は docs/plan の順に実装します。
      </Text>
    </Container>
  );
}
