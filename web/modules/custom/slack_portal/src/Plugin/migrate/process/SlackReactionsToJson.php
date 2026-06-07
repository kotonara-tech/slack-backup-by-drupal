<?php

declare(strict_types=1);

/**
 * @file
 * Migrate process plugin: encode a reactions array as a JSON string.
 */

namespace Drupal\slack_portal\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Encodes the reactions array from the source row as a JSON string.
 *
 * The reactions source field is a PHP array (possibly nested). This plugin
 * JSON-encodes the whole array as a single string suitable for storage in a
 * string_long field. It declares handle_multiples so the migrate pipeline does
 * not iterate over the array elements before calling transform().
 *
 * @code
 * field_reactions:
 *   plugin: slack_reactions_to_json
 *   source: reactions
 * @endcode
 */
#[MigrateProcess('slack_reactions_to_json', handle_multiples: TRUE)]
final class SlackReactionsToJson extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_array($value)) {
      return '[]';
    }
    $json = json_encode($value);
    return $json !== FALSE ? $json : '[]';
  }

}
