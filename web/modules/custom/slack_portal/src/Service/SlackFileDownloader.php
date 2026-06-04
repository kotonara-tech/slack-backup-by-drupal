<?php

declare(strict_types=1);

/**
 * @file
 * Downloads Slack private files to disk via Bearer-authenticated HTTP stream.
 */

namespace Drupal\slack_portal\Service;

use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads Slack url_private files to a local stream URI idempotently.
 *
 * Files are streamed directly to disk via Guzzle's `sink` option so that
 * large files are never fully buffered in PHP memory. Downloads are
 * deduplicated: if the destination already exists and its size matches the
 * expected size provided by the caller, the HTTP request is skipped entirely.
 *
 * The Slack token is passed per-call so this class has no dependency on
 * SlackTokenProvider (which belongs to a later milestone).
 */
final class SlackFileDownloader {

  /**
   * Constructs a SlackFileDownloader.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client used to stream files (production: @http_client).
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Drupal's file-system service for directory creation and path resolution.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (e.g. logger.channel.slack_portal).
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Downloads a Slack url_private file to a stream URI destination.
   *
   * Idempotency: if $destStreamUri already exists on disk and either
   * $expectedSize is null or the existing file's size matches $expectedSize,
   * the HTTP download is skipped and the destination URI is returned at once.
   *
   * The file is streamed directly to disk via Guzzle's `sink` option without
   * buffering the full content in PHP memory.
   *
   * @param string $urlPrivate
   *   The Slack url_private to download (e.g. from a file object).
   * @param string $token
   *   A Slack user token (xoxp-…) with files:read scope. Never logged.
   * @param string $destStreamUri
   *   The destination stream URI, e.g. 'public://slack_archive/files/F1.png'.
   * @param int|null $expectedSize
   *   If provided, skip download when the existing file has this exact size.
   *   Pass null to skip only when the file exists regardless of size.
   *
   * @return string
   *   The destination stream URI, whether the file was downloaded or skipped.
   */
  public function download(
    string $urlPrivate,
    string $token,
    string $destStreamUri,
    ?int $expectedSize = NULL,
  ): string {
    // Ensure the parent directory exists and is writable.
    $dir = dirname($destStreamUri);
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    // Idempotency check: skip if file already exists with the expected size.
    $realPath = $this->fileSystem->realpath($destStreamUri);
    if ($realPath !== FALSE && file_exists($realPath)) {
      if ($expectedSize === NULL || filesize($realPath) === $expectedSize) {
        $this->logger->debug(
          'Skipping download; file already exists at {uri}.',
          ['uri' => $destStreamUri],
        );
        return $destStreamUri;
      }
    }

    // Resolve the real path for the destination file. After prepareDirectory
    // the directory exists; use dirname realpath + basename to build the path.
    $dirRealPath = $this->fileSystem->realpath($dir);
    $sinkPath = $dirRealPath . DIRECTORY_SEPARATOR . basename($destStreamUri);

    $this->logger->debug(
      'Downloading Slack file to {uri}.',
      ['uri' => $destStreamUri],
    );

    // Stream the file to disk without loading it into memory.
    // Token is passed in the Authorization header only; it is never logged.
    $this->httpClient->request('GET', $urlPrivate, [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
      ],
      'sink' => $sinkPath,
    ]);

    return $destStreamUri;
  }

}
