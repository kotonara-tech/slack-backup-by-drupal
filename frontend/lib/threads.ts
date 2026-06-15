/**
 * メッセージ配列をスレッドへ再構成する純関数。
 *
 * Slack のスレッドは「親（thread_ts === slack_ts）」と「返信（thread_ts が親の
 * slack_ts）」で表される。本関数は取得順に依存せず:
 *  - 親（thread_ts===slack_ts）と standalone（thread_ts=null）を root にする。
 *  - 返信を親 root へ thread_ts で束ねる（thread_broadcast も返信扱い）。
 *  - 親が取得範囲外の orphan reply は単独 root として救済する（欠落ゼロ）。
 * root は新しい順、返信は時系列昇順（slack_ts）に整列する。
 */
import type { SlackMessage, Thread } from "@/lib/types/slack";

/** slack_ts（epoch 秒.連番）を数値化して時系列比較に使う。 */
function ts(message: SlackMessage): number {
  return Number(message.slackTs) || 0;
}

function isRoot(message: SlackMessage): boolean {
  return message.threadTs == null || message.threadTs === message.slackTs;
}

export function groupIntoThreads(messages: SlackMessage[]): Thread[] {
  const roots = new Map<string, { root: SlackMessage; replies: SlackMessage[] }>();

  // 1st pass: root を登録。
  for (const m of messages) {
    if (isRoot(m)) roots.set(m.slackTs, { root: m, replies: [] });
  }

  // 2nd pass: 返信を親へ。親不在は orphan として単独 root に救済。
  for (const m of messages) {
    if (isRoot(m)) continue;
    const parent = m.threadTs != null ? roots.get(m.threadTs) : undefined;
    if (parent) {
      parent.replies.push(m);
    } else {
      roots.set(m.slackTs, { root: m, replies: [] });
    }
  }

  return Array.from(roots.values())
    .map(({ root, replies }) => ({
      root,
      replies: replies.slice().sort((a, b) => ts(a) - ts(b)),
      replyCount: replies.length,
    }))
    .sort((a, b) => ts(b.root) - ts(a.root));
}
