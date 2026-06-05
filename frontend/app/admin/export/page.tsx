"use client";

import { Container, Title } from "@mantine/core";

import { SlackExportPanel } from "@/components/SlackExportPanel";

/**
 * 管理者向け Slack エクスポート画面（/admin/export）。
 * トリガと状態表示は SlackExportPanel が担う。
 * Mantine / TanStack Query の provider は app/providers.tsx（layout）で供給される。
 */
export default function ExportAdminPage() {
  return (
    <Container size="md" py="xl">
      <Title order={1} mb="lg">
        Slack Portal 管理
      </Title>
      <SlackExportPanel />
    </Container>
  );
}
