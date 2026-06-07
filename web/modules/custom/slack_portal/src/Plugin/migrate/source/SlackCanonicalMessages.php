<?php

declare(strict_types=1);

/**
 * @file
 * Migrate source plugin: canonical channel JSON -> flattened message rows.
 */

namespace Drupal\slack_portal\Plugin\migrate\source;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\slack_portal\CanonicalArchive;
use Drupal\slack_portal\Service\CanonicalMessageFlattener;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads canonical channel JSON files and yields flattened, deduped messages.
 *
 * Scans <base_dir>/channels/*.json via the public:// stream wrapper, decodes
 * each channel document, and delegates flattening/dedup to
 * CanonicalMessageFlattener. The migrate id is slack_ts.
 */
#[MigrateSource('slack_canonical_messages')]
final class SlackCanonicalMessages extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The canonical archive base directory URI.
   */
  private string $baseDir;

  /**
   * The flattener for one channel's messages.
   */
  private CanonicalMessageFlattener $flattener;

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
    $this->flattener = new CanonicalMessageFlattener();
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
    $channelsDir = $this->baseDir . '/channels';
    if (is_dir($channelsDir)) {
      $found = $this->fileSystem->scanDirectory($channelsDir, '/\.json$/');
      $uris = array_keys($found);
      sort($uris);
      foreach ($uris as $uri) {
        $contents = file_get_contents($uri);
        if ($contents === FALSE) {
          continue;
        }
        $data = json_decode($contents, TRUE);
        if (is_array($data)) {
          foreach ($this->flattener->flattenChannel($data) as $row) {
            $rows[] = $row;
          }
        }
      }
    }
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'slack_ts' => 'Slack timestamp (unique within channel)',
      'channel_id' => 'Channel id',
      'channel_type' => 'Channel type (public_channel/private_channel/im/mpim)',
      'user_id' => 'Slack user id',
      'bot_id' => 'Bot id',
      'username' => 'Username',
      'type' => 'Message type',
      'subtype' => 'Message subtype',
      'text' => 'Message text',
      'edited' => 'Whether the message was edited',
      'thread_ts' => 'Parent thread ts',
      'reply_count' => 'Reply count',
      'reactions' => 'Reactions array',
      'reaction_total' => 'Sum of reaction counts',
      'file_ids' => 'Local file ids',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'slack_ts' => ['type' => 'string'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'slack_canonical_messages';
  }

}
