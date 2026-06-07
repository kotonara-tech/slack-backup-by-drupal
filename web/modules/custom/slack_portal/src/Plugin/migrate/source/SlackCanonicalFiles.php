<?php

declare(strict_types=1);

/**
 * @file
 * Migrate source plugin: list canonical archive files for managed-file import.
 */

namespace Drupal\slack_portal\Plugin\migrate\source;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\slack_portal\CanonicalArchive;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the files under <base_dir>/files for non-copy managed-file import.
 *
 * Each row's id is the filename without extension (e.g. F_LOCAL), matching the
 * file_ids emitted by the SlackCanonicalMessages source, so the messages
 * migration can resolve field_attachments via migration_lookup.
 */
#[MigrateSource('slack_canonical_files')]
final class SlackCanonicalFiles extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The canonical archive base directory URI.
   */
  private string $baseDir;

  /**
   * The file system service.
   */
  private FileSystemInterface $fileSystem;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->fileSystem = $file_system;
    $this->baseDir = (string) ($configuration['base_dir'] ?? CanonicalArchive::BASE_DIR);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $rows = [];
    $filesDir = $this->baseDir . '/files';
    if (is_dir($filesDir)) {
      $found = $this->fileSystem->scanDirectory($filesDir, '/.*/');
      $uris = array_keys($found);
      sort($uris);
      foreach ($uris as $uri) {
        $filename = basename($uri);
        $rows[] = [
          'id' => pathinfo($filename, PATHINFO_FILENAME),
          'uri' => $uri,
          'filename' => $filename,
        ];
      }
    }
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => 'File id (filename without extension)',
      'uri' => 'Public stream URI of the file',
      'filename' => 'File name with extension',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => ['type' => 'string'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'slack_canonical_files';
  }

}
