/**
 * 表示用の整形ヘルパ。
 *
 * JSON:API の `field_posted_at` は offset 付き ISO（例 `2026-05-17T15:38:27+09:00`）。
 * 表示は固定の Asia/Tokyo・固定書式（`YYYY/MM/DD HH:mm`）にして、CI マシンの
 * タイムゾーン/ロケールに依存しないようにする（`formatToParts` で組み立て）。
 */

/** 表示タイムゾーン（社内 Slack＝日本ワークスペース。データの offset も +09:00）。 */
export const DISPLAY_TIME_ZONE = "Asia/Tokyo";

const dateTimeFormat = new Intl.DateTimeFormat("en-CA", {
  timeZone: DISPLAY_TIME_ZONE,
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** offset 付き ISO を `YYYY/MM/DD HH:mm`（JST）へ。不正/空はそのまま返す。 */
export function formatPostedAt(iso: string): string {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const p = Object.fromEntries(
    dateTimeFormat.formatToParts(date).map((part) => [part.type, part.value]),
  );
  return `${p.year}/${p.month}/${p.day} ${p.hour}:${p.minute}`;
}
