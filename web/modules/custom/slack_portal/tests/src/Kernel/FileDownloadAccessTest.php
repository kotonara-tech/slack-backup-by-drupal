<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: hook_file_download() gates private archive attachments.
 */

namespace Drupal\Tests\slack_portal\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies hook_file_download() allows/denies archive attachment access.
 *
 * Given: full migrations (channels/users/files/messages) executed so the
 *        published public-channel node 1700000020.000020 references F_LOCAL.
 * When:  anonymous user calls hook_file_download() with the file URI.
 * Then:
 *   - File referenced only by a published node  → header array (allow).
 *   - File referenced only by an unpublished node → -1 (deny).
 *   - URI outside slack_archive/               → NULL (not our concern).
 */
#[Group('slack_portal')]
class FileDownloadAccessTest extends SlackMigrateKernelTestBase {

  /**
   * Tests all three file-download gate cases as the anonymous user.
   */
  public function testFileDownloadAccessControl(): void {
    $this->executeMigrations([
      'slack_channels',
      'slack_users',
      'slack_files',
      'slack_messages',
    ]);

    // Switch to anonymous so $node->access('view') reflects anon grants.
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    // -- Case 1: public attachment → allowed -------------------------
    // The published node at slack_ts=1700000020.000020 references F_LOCAL.
    $pubNode = $this->loadBySlackTs('1700000020.000020');
    $this->assertTrue(
      $pubNode->isPublished(),
      'Pre-condition: node must be published.',
    );

    $attached = $pubNode->get('field_attachments')->referencedEntities();
    $this->assertNotEmpty(
      $attached,
      'Pre-condition: published node must have an attachment.',
    );
    /** @var \Drupal\file\FileInterface $pubFile */
    $pubFile = reset($attached);
    $pubUri = $pubFile->getFileUri();
    $this->assertStringContainsString('slack_archive/', $pubUri);

    $result = slack_portal_file_download($pubUri);
    $this->assertIsArray(
      $result,
      'Public-channel attachment must return a header array.',
    );
    $this->assertArrayHasKey('Content-Type', $result);

    // -- Case 2: private-only attachment → denied (-1) ---------------
    // Create a file and an UNPUBLISHED node so the only referencing node
    // is unpublished → anonymous must be denied.
    // pragma: allowlist secret
    $hiddenUri = 'private://slack_archive/latest/files/hidden.bin';
    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $filesDir = 'private://slack_archive/latest/files';
    $fs->prepareDirectory($filesDir);
    file_put_contents(
      $fs->realpath($filesDir) . '/hidden.bin',
      'hidden-content',
    );

    $hiddenFile = File::create([
      'uri' => $hiddenUri,
      'filename' => 'hidden.bin',
      'filemime' => 'application/octet-stream',
      'status' => 1,
    ]);
    $hiddenFile->save();

    $privateNode = Node::create([
      'type' => 'slack_message',
      'title' => 'private attachment node',
      'status' => 0,
      'field_attachments' => [['target_id' => $hiddenFile->id()]],
    ]);
    $privateNode->save();

    $denyResult = slack_portal_file_download($hiddenUri);
    $this->assertSame(
      -1,
      $denyResult,
      'Private-only attachment must return -1 (deny).',
    );

    // -- Case 3: non-archive URI → NULL (not ours) -------------------
    $nullResult = slack_portal_file_download('public://some-other-file.png');
    $this->assertNull(
      $nullResult,
      'Non-archive URI must return NULL.',
    );
  }

}
