<?php

declare(strict_types=1);

/**
 * @file
 * Kernel test: taxonomy_term access control for private Slack channels.
 */

namespace Drupal\Tests\slack_portal\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies that anonymous cannot view private slack_channels terms.
 *
 * Given: all migrations executed so channel/user taxonomy terms exist.
 * When:  the anonymous user checks term->access('view').
 * Then:
 *   - slack_channels term with field_channel_type='public_channel' → TRUE.
 *   - slack_channels term with field_channel_type='private_channel' → FALSE.
 *   - slack_users term → TRUE (not restricted by this hook).
 */
#[Group('slack_portal')]
class TaxonomyTermAccessTest extends SlackMigrateKernelTestBase {

  /**
   * Anonymous can view public channel terms but not private ones.
   */
  public function testAnonymousTermAccessByChannelType(): void {
    $this->executeMigrations([
      'slack_channels',
      'slack_users',
    ]);

    // Grant 'access content' so user permissions are in place, then switch
    // current user to anonymous.
    $this->installConfig(['user']);
    $anonRole = Role::load(RoleInterface::ANONYMOUS_ID);
    assert($anonRole instanceof RoleInterface);
    $anonRole->grantPermission('access content');
    $anonRole->save();

    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    $termStorage = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term');

    // -- public_channel term: anonymous MUST be able to view -------
    $pubTerms = $termStorage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'C_PUB001',
    ]);
    $pubTerm = reset($pubTerms);
    $this->assertNotFalse(
      $pubTerm,
      'Pre-condition: public channel term must exist.',
    );
    $this->assertSame(
      'public_channel',
      $pubTerm->get('field_channel_type')->value,
    );
    $this->assertTrue(
      $pubTerm->access('view'),
      'Anonymous must be able to view a public_channel term.',
    );

    // -- private_channel term: anonymous MUST be denied ------------
    $prvTerms = $termStorage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'C_PRV001',
    ]);
    $prvTerm = reset($prvTerms);
    $this->assertNotFalse(
      $prvTerm,
      'Pre-condition: private channel term must exist.',
    );
    $this->assertSame(
      'private_channel',
      $prvTerm->get('field_channel_type')->value,
    );
    $this->assertFalse(
      $prvTerm->access('view'),
      'Anonymous must NOT be able to view a private_channel term.',
    );

    // -- im term: anonymous MUST be denied -----------------------
    $imTerms = $termStorage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'D_IM001',
    ]);
    $imTerm = reset($imTerms);
    $this->assertNotFalse(
      $imTerm,
      'Pre-condition: im channel term must exist.',
    );
    $this->assertSame(
      'im',
      $imTerm->get('field_channel_type')->value,
    );
    $this->assertFalse(
      $imTerm->access('view'),
      'Anonymous must NOT be able to view an im channel term.',
    );

    // -- mpim term: anonymous MUST be denied ----------------------
    $mpimTerms = $termStorage->loadByProperties([
      'vid' => 'slack_channels',
      'field_slack_channel_id' => 'G_MPIM001',
    ]);
    $mpimTerm = reset($mpimTerms);
    $this->assertNotFalse(
      $mpimTerm,
      'Pre-condition: mpim channel term must exist.',
    );
    $this->assertSame(
      'mpim',
      $mpimTerm->get('field_channel_type')->value,
    );
    $this->assertFalse(
      $mpimTerm->access('view'),
      'Anonymous must NOT be able to view an mpim channel term.',
    );

    // -- slack_users term: anonymous MUST be able to view ----------
    $userTerms = $termStorage->loadByProperties([
      'vid' => 'slack_users',
      'field_slack_user_id' => 'U_ALICE',
    ]);
    $userTerm = reset($userTerms);
    $this->assertNotFalse(
      $userTerm,
      'Pre-condition: slack_users term must exist.',
    );
    $this->assertTrue(
      $userTerm->access('view'),
      'Anonymous must be able to view a slack_users term.',
    );
  }

}
