"use client";

/**
 * Slack エクスポートのトリガ＋状態表示パネル。
 *
 * - 開始ボタン: useTriggerExport().mutate() を呼ぶ（実行中は無効化）。
 * - バッジ: 永続状態 idle/running/done/error を色分け表示。
 * - 進捗: running/done のとき processed/total を Progress で表示。
 * - エラー: 状態 error の last_error と、トリガ失敗（mutation error）を Alert 表示。
 *
 * secret（token）は frontend を通さない。状態は backend の State から取得する。
 */
import {
  Alert,
  Badge,
  Button,
  Group,
  Paper,
  Progress,
  Stack,
  Text,
  Title,
} from "@mantine/core";

import { useExportStatus } from "@/lib/hooks/useExportStatus";
import { useTriggerExport } from "@/lib/hooks/useTriggerExport";
import type { ExportStatusState } from "@/lib/slack-portal";

const STATUS_LABEL: Record<ExportStatusState, string> = {
  idle: "待機中",
  running: "実行中",
  done: "完了",
  error: "エラー",
};

const STATUS_COLOR: Record<ExportStatusState, string> = {
  idle: "gray",
  running: "blue",
  done: "green",
  error: "red",
};

export function SlackExportPanel() {
  const statusQuery = useExportStatus();
  const trigger = useTriggerExport();

  const status = statusQuery.data;
  const state: ExportStatusState = status?.status ?? "idle";
  const isRunning = state === "running";
  const total = status?.total ?? 0;
  const processed = status?.processed ?? 0;
  const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

  return (
    <Paper p="lg" withBorder>
      <Stack gap="md">
        <Group justify="space-between">
          <Title order={2}>Slack エクスポート</Title>
          <Badge color={STATUS_COLOR[state]} data-testid="export-status-badge">
            {STATUS_LABEL[state]}
          </Badge>
        </Group>

        <Text size="sm" c="dimmed">
          ワークスペースの直近約 90 日を取得し canonical JSON に保存します。
          認証情報は Drupal 管理画面で設定します。
        </Text>

        <Group>
          <Button
            onClick={() => trigger.mutate()}
            loading={trigger.isPending}
            disabled={isRunning || trigger.isPending}
          >
            エクスポートを開始
          </Button>
        </Group>

        {(isRunning || state === "done") && (
          <Stack gap="xs">
            <Progress
              value={percent}
              data-testid="export-progress"
              aria-label="export progress"
            />
            <Text size="sm" c="dimmed">
              チャンネル {processed} / {total}・メッセージ {status?.messages ?? 0}
              ・ユーザ {status?.users ?? 0}
            </Text>
          </Stack>
        )}

        {state === "error" && status?.last_error && (
          <Alert color="red" title="エクスポートに失敗しました">
            {status.last_error}
          </Alert>
        )}

        {trigger.isError && trigger.error && (
          <Alert color="red" title="トリガに失敗しました">
            {trigger.error.message}
          </Alert>
        )}
      </Stack>
    </Paper>
  );
}
