<?php

declare(strict_types=1);

/**
 * @file
 * Writes normalized canonical JSON to the stable slack_archive/latest/ tree.
 */

namespace Drupal\slack_portal\Service;

use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes canonical Slack archive JSON files idempotently.
 *
 * All files are written to a stable, deterministic tree under
 * public://slack_archive/latest/ using the channel ID (or well-known names
 * "users.json" / "manifest.json") as the filename. Re-running with the same
 * input produces byte-identical output because JSON is encoded with
 * JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, and
 * existing files are replaced rather than renamed.
 *
 * The class intentionally has no dependency on stream_wrapper_manager;
 * FileSystemInterface + public:// URIs are sufficient.
 */
final class CanonicalJsonWriter {

  /**
   * Constructs a CanonicalJsonWriter.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Drupal's file-system service for directory preparation and
   *   path resolution.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (e.g. logger.channel.slack_portal).
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the base directory URI for the canonical archive.
   *
   * @return string
   *   Always 'public://slack_archive/latest'.
   */
  public function baseDir(): string {
    return 'public://slack_archive/latest';
  }

  /**
   * Writes a channel's messages to the canonical JSON tree.
   *
   * The file is written to baseDir()/channels/<channelId>.json and contains:
   * @code
   * {
   *   "schema_version": 1,
   *   "channel": { ... },
   *   "messages": [ ... ]
   * }
   * @endcode
   *
   * Re-running with the same arguments overwrites the file byte-identically.
   *
   * @param string $channelId
   *   The Slack channel ID (e.g. 'C123456').
   * @param array<string, mixed> $channelMeta
   *   The channel metadata as produced by CanonicalMessageFormatter.
   * @param array<int, array<string, mixed>> $foldedMessages
   *   The folded/threaded messages as produced by ThreadFolder.
   *
   * @return string
   *   The stream URI of the written file
   *   (e.g. 'public://…/channels/C123.json').
   */
  public function writeChannel(string $channelId, array $channelMeta, array $foldedMessages): string {
    $data = [
      'schema_version' => 1,
      'channel' => $channelMeta,
      'messages' => $foldedMessages,
    ];
    $uri = $this->baseDir() . '/channels/' . $channelId . '.json';
    return $this->write($uri, $data);
  }

  /**
   * Writes the users list to the canonical JSON tree.
   *
   * The file is written to baseDir()/users.json as a top-level JSON array.
   *
   * @param array<int, array<string, mixed>> $users
   *   The list of normalized user objects.
   *
   * @return string
   *   The stream URI of the written file ('public://…/users.json').
   */
  public function writeUsers(array $users): string {
    $uri = $this->baseDir() . '/users.json';
    return $this->write($uri, $users);
  }

  /**
   * Writes the export manifest to the canonical JSON tree.
   *
   * The file is written to baseDir()/manifest.json.
   *
   * @param array<string, mixed> $manifest
   *   The manifest data (schema_version, generated_at, counts, etc.).
   *
   * @return string
   *   The stream URI of the written file ('public://…/manifest.json').
   */
  public function writeManifest(array $manifest): string {
    $uri = $this->baseDir() . '/manifest.json';
    return $this->write($uri, $manifest);
  }

  /**
   * Encodes $data as deterministic JSON and writes it to $uri.
   *
   * Replaces any existing file (idempotent). Idempotency is guaranteed by:
   * - Deterministic encoding (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
   *   JSON_UNESCAPED_SLASHES).
   * - Writing via file_put_contents() to the resolved real path so that the
   *   exact URI is always used — Drupal's saveData() would append _0 on
   *   conflict when FileExists::Rename is used. We use prepareDirectory() +
   *   file_put_contents() on the real path to guarantee overwrite semantics.
   *
   * @param string $uri
   *   The stream URI to write to (must start with 'public://').
   * @param mixed $data
   *   The data to JSON-encode.
   *
   * @return string
   *   The stream URI passed in (returned unchanged for caller convenience).
   */
  private function write(string $uri, mixed $data): string {
    $dir = dirname($uri);
    $this->fileSystem->prepareDirectory(
      $dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $json = json_encode(
      $data,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );

    // Use realpath of the directory + basename to guarantee we write to the
    // exact path without any rename-on-conflict behaviour.
    $dirRealPath = $this->fileSystem->realpath($dir);
    $filePath = $dirRealPath . DIRECTORY_SEPARATOR . basename($uri);

    $this->logger->debug(
      'Writing canonical JSON to {uri}.',
      ['uri' => $uri],
    );

    file_put_contents($filePath, $json);

    return $uri;
  }

}
