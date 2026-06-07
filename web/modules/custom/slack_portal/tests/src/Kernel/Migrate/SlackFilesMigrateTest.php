<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: slack_files migration + message field_attachments lookup.
 */

namespace Drupal\Tests\slack_portal\Kernel\Migrate;

use Drupal\Tests\slack_portal\Kernel\SlackMigrateKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies files migrate to managed file entities and attach to messages.
 */
#[Group('slack_portal')]
class SlackFilesMigrateTest extends SlackMigrateKernelTestBase {

  /**
   * Files become permanent file entities and attach to the right message.
   */
  public function testFilesMigrateAndAttach(): void {
    $this->executeMigrations([
      'slack_channels',
      'slack_users',
      'slack_files',
      'slack_messages',
    ]);

    // One managed file entity created, permanent, referencing the public uri.
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties([
      'uri' => 'public://slack_archive/latest/files/F_LOCAL.png',
    ]);
    $this->assertCount(1, $files);
    $file = reset($files);
    $this->assertTrue($file->isPermanent());
    $this->assertSame('F_LOCAL.png', $file->getFilename());

    // The file-bearing message references it.
    $withFile = $this->loadBySlackTs('1700000020.000020');
    $attached = $withFile->get('field_attachments')->referencedEntities();
    $this->assertCount(1, $attached);
    $this->assertSame($file->id(), $attached[0]->id());

    // A message without files has empty field_attachments.
    $noFile = $this->loadBySlackTs('1700000001.000001');
    $this->assertTrue($noFile->get('field_attachments')->isEmpty());
  }

}
