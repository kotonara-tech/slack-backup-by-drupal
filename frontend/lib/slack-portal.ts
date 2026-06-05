/**
 * Slack Portal の trigger / status HTTP API クライアント。
 *
 * backend 契約の正典は docs/spec/portal-api.md:
 *  - GET  {DRUPAL}/session/token            → CSRF トークン（プレーンテキスト）
 *  - POST {DRUPAL}/api/slack-portal/export  → 200 {status:'queued',queued,users} | 500 {status:'error',message}
 *  - GET  {DRUPAL}/api/slack-portal/status  → ExportStatus（永続状態 idle/running/done/error）
 *
 * いずれも Drupal セッション Cookie 認証のため credentials:'include' を付ける。
 * secret（Slack token）は frontend を通らない。
 */
import { DRUPAL_BASE_URL } from "@/lib/drupal";

/** 永続化されるエクスポート状態（GET /status の `status` 値）。 */
export type ExportStatusState = "idle" | "running" | "done" | "error";

/** status.channels[] の各エントリ。 */
export interface ChannelIndexEntry {
  id: string;
  name: string;
  type: string;
  file: string;
}

/** GET /api/slack-portal/status のレスポンス（ExportStateService の全配列）。 */
export interface ExportStatus {
  status: ExportStatusState;
  total: number;
  processed: number;
  messages: number;
  users: number;
  channels: ChannelIndexEntry[];
  started_at: number | null;
  finished_at: number | null;
  last_error: string | null;
}

/** POST /api/slack-portal/export 成功時の一回限り ack。 */
export interface TriggerResult {
  status: "queued";
  queued: number;
  users: number;
}

/**
 * Drupal の CSRF トークンを取得する。
 *
 * @returns プレーンテキストの CSRF トークン。
 */
export async function getCsrfToken(): Promise<string> {
  const res = await fetch(`${DRUPAL_BASE_URL}/session/token`, {
    credentials: "include",
  });
  if (!res.ok) {
    throw new Error(`CSRF トークンの取得に失敗しました (${res.status})`);
  }
  return (await res.text()).trim();
}

/**
 * エクスポートをトリガする（CSRF ヘッダ付き POST）。
 *
 * @returns 成功時の queued サマリ。
 * @throws 500 等のエラー時、サニタイズ済みメッセージを持つ Error。
 */
export async function triggerExport(): Promise<TriggerResult> {
  const token = await getCsrfToken();
  const res = await fetch(`${DRUPAL_BASE_URL}/api/slack-portal/export`, {
    method: "POST",
    credentials: "include",
    headers: {
      "X-CSRF-Token": token,
    },
  });

  // Check res.ok BEFORE parsing: permission/CSRF failures return a non-JSON
  // (plain-text / HTML) 403, so res.json() would throw an opaque SyntaxError
  // and mask the real reason. Try to surface the sanitised JSON message when
  // present, otherwise fall back to a status-coded message.
  if (!res.ok) {
    let message = `エクスポートの起動に失敗しました (${res.status})`;
    try {
      const body = (await res.json()) as { message?: string };
      if (body && typeof body.message === "string" && body.message) {
        message = body.message;
      }
    } catch {
      // Non-JSON error body — keep the status-coded fallback message.
    }
    throw new Error(message);
  }

  return (await res.json()) as TriggerResult;
}

/**
 * 現在のエクスポート状態を取得する。
 *
 * @returns ExportStatus。
 */
export async function fetchExportStatus(): Promise<ExportStatus> {
  const res = await fetch(`${DRUPAL_BASE_URL}/api/slack-portal/status`, {
    credentials: "include",
  });
  if (!res.ok) {
    throw new Error(`状態の取得に失敗しました (${res.status})`);
  }
  return (await res.json()) as ExportStatus;
}
