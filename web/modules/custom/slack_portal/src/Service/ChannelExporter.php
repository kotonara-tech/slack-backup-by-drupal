<?php

declare(strict_types=1);

/**
 * @file
 * Integrates the per-channel export pipeline end-to-end.
 */

namespace Drupal\slack_portal\Service;

use JoliCode\Slack\Api\Client as SlackApiClient;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full per-channel Slack export pipeline.
 *
 * Execution order:
 * 1. Fetch all history messages for the channel (cursor-paginated).
 * 2. For each thread-root parent (thread_ts === slack_ts, reply_count > 0),
 *    fetch replies and append them to the flat list.
 * 3. Download all inline files (from message.files[]) to disk, set
 *    local_path to the relative path and replace url_private with 'REDACTED'.
 * 4. Fold the flat list into threaded messages (ThreadFolder).
 * 5. Write the folded messages to the canonical JSON archive
 *    (CanonicalJsonWriter).
 * 6. Return statistics: ['messages' => <top-level count>].
 *
 * This class is stateless between calls. Each call to exportChannel() is
 * independent and idempotent (given identical input and Slack API responses).
 */
class ChannelExporter {

  /**
   * Constructs a ChannelExporter.
   *
   * @param \Drupal\slack_portal\Service\SlackFetcher $fetcher
   *   Cursor-paginated Slack API fetcher.
   * @param \Drupal\slack_portal\Service\CanonicalMessageFormatter $formatter
   *   Transforms raw Slack messages into the canonical shape.
   * @param \Drupal\slack_portal\Service\ThreadFolder $folder
   *   Folds thread replies onto their parent messages.
   * @param \Drupal\slack_portal\Service\SlackFileDownloader $downloader
   *   Downloads Slack url_private files to disk via Bearer auth.
   * @param \Drupal\slack_portal\Service\CanonicalJsonWriter $writer
   *   Writes the folded canonical messages to the archive JSON tree.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger.
   */
  public function __construct(
    private readonly SlackFetcher $fetcher,
    private readonly CanonicalMessageFormatter $formatter,
    private readonly ThreadFolder $folder,
    private readonly SlackFileDownloader $downloader,
    private readonly CanonicalJsonWriter $writer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Exports a single Slack channel through the full ingest pipeline.
   *
   * @param \JoliCode\Slack\Api\Client $client
   *   A configured Slack API client (already built by SlackClientFactory).
   * @param string $token
   *   The Slack user token (xoxp-…) used for file downloads.
   *   Never logged or stored in the output.
   * @param array<string,mixed> $channelMeta
   *   Channel metadata (must include 'id' key; also 'name', 'type', etc.).
   * @param int|null $oldest
   *   Optional Unix timestamp; only messages after this time are fetched.
   *   Pass NULL to retrieve all available history.
   *
   * @return array<string,int>
   *   Statistics: ['messages' => <count of top-level messages after folding>].
   */
  public function exportChannel(
    SlackApiClient $client,
    string $token,
    array $channelMeta,
    ?int $oldest = NULL,
  ): array {
    $channelId = (string) $channelMeta['id'];

    $this->logger->debug(
      'Exporting channel {channel_id}',
      ['channel_id' => $channelId],
    );

    // Step 1 + 2: Fetch history and replies; build a flat canonical list.
    /** @var array<int,array<string,mixed>> $flatList */
    $flatList = [];

    foreach ($this->fetcher->fetchHistory($client, $channelId, $oldest) as $rawMsg) {
      $canonical = $this->formatter->format($rawMsg, $channelId);
      $flatList[] = $canonical;

      // Fetch replies for thread-root messages that have replies.
      $slackTs = (string) $canonical['slack_ts'];
      $threadTs = isset($canonical['thread_ts']) ? (string) $canonical['thread_ts'] : NULL;
      $replyCount = (int) $canonical['reply_count'];

      if ($threadTs === $slackTs && $replyCount > 0) {
        $rawReplies = $this->fetcher->fetchReplies($client, $channelId, $slackTs);
        foreach ($rawReplies as $rawReply) {
          $flatList[] = $this->formatter->format($rawReply, $channelId);
        }
      }
    }

    // Step 3: Inline file download — mutate each message's files[] in place.
    $this->downloadFiles($flatList, $token);

    // Step 4: Fold the flat list into threaded messages.
    $folded = $this->folder->fold($flatList);

    // Step 5: Write the canonical JSON archive.
    $this->writer->writeChannel($channelId, $channelMeta, $folded);

    $this->logger->debug(
      'Exported {count} top-level messages for channel {channel_id}',
      ['count' => count($folded), 'channel_id' => $channelId],
    );

    // Step 6: Return top-level message count.
    return ['messages' => count($folded)];
  }

  /**
   * Downloads inline files for all messages in the flat list.
   *
   * For every entry in message['files'], downloads the file to a deterministic
   * path under baseDir()/files/, sets local_path to the relative path, and
   * replaces url_private with 'REDACTED' so the tokenized URL is never
   * persisted to the JSON archive.
   *
   * @param array<int,array<string,mixed>> $messages
   *   The flat canonical message list (mutated in place).
   * @param string $token
   *   The Slack user token for Bearer authentication. Never logged.
   */
  private function downloadFiles(array &$messages, string $token): void {
    $baseDir = $this->writer->baseDir();

    foreach ($messages as &$message) {
      if (empty($message['files'])) {
        continue;
      }

      /** @var array<int,array<string,mixed>> $files */
      $files = $message['files'];

      foreach ($files as $i => $fileEntry) {
        $id = (string) $fileEntry['id'];
        $name = (string) $fileEntry['name'];
        $urlPrivate = (string) $fileEntry['url_private'];
        $size = isset($fileEntry['size']) ? (int) $fileEntry['size'] : NULL;

        $ext = $this->fileExtension($name);
        $relativePath = "files/{$id}.{$ext}";
        $destUri = "{$baseDir}/{$relativePath}";

        $this->downloader->download($urlPrivate, $token, $destUri, $size);

        // Set local_path and REDACT url_private — never persist the token URL.
        $files[$i]['local_path'] = $relativePath;
        $files[$i]['url_private'] = 'REDACTED';
      }

      $message['files'] = $files;
    }
    // Release by-reference binding.
    unset($message);
  }

  /**
   * Derives a lowercase file extension from a filename, defaulting to 'bin'.
   *
   * @param string $filename
   *   The filename (e.g. 'photo.PNG', 'document.pdf', 'noext').
   *
   * @return string
   *   Lowercase extension ('png', 'pdf') or 'bin' when none is present.
   */
  private function fileExtension(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'bin';
  }

}
