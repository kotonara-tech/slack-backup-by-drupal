<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackTokenProvider — encrypted State resolver path.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\slack_portal\Service\SlackTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for SlackTokenProvider's encrypted State resolution.
 *
 * Verifies that a ciphertext stored in Drupal State is decrypted via the
 * encrypt module and returned as the Slack user token.  Uses the real_aes
 * encryption method with a 256-bit config-provider key so that encryption is
 * fully exercised without mocking.
 *
 * @covers \Drupal\slack_portal\Service\SlackTokenProvider
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackTokenProvider::class)]
#[Group('slack_portal')]
class SlackTokenProviderEncryptTest extends KernelTestBase {

  /**
   * Modules required for this test.
   *
   * @var string[]
   */
  protected static $modules = ['key', 'encrypt', 'real_aes', 'system'];

  /**
   * The encryption profile used in tests.
   *
   * @var \Drupal\encrypt\EncryptionProfileInterface
   */
  private \Drupal\encrypt\EncryptionProfileInterface $profile;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a 256-bit config-provider key for real_aes (32 bytes required).
    // The key value is a test-only placeholder.
    Key::create([
      'id' => 'slack_portal_enc_test',
      'label' => 'Slack Portal Test Key',
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
      'key_provider_settings' => ['key_value' => 'mustbesixteenbitmustbesixteenbit'], // pragma: allowlist secret
    ])->save();

    // Create the encryption profile pointing at that key.
    $encProfile = EncryptionProfile::create([
      'id' => 'slack_portal',
      'label' => 'Slack Portal',
      'encryption_method' => 'real_aes',
      'encryption_key' => 'slack_portal_enc_test',
    ]);
    $encProfile->save();

    /** @var \Drupal\encrypt\EncryptionProfileManagerInterface $profileManager */
    $profileManager = $this->container->get('encrypt.encryption_profile.manager');
    $this->profile = $profileManager->getEncryptionProfile('slack_portal');
  }

  /**
   * Builds a SlackTokenProvider wired to real container services.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   Site settings to inject.
   *
   * @return \Drupal\slack_portal\Service\SlackTokenProvider
   *   Configured provider instance.
   */
  private function buildProvider(Settings $settings): SlackTokenProvider {
    return new SlackTokenProvider(
      $settings,
      $this->container->get('state'),
      $this->container->get('encryption'),
      $this->container->get('encrypt.encryption_profile.manager'),
    );
  }

  /**
   * Tests that a token stored as a ciphertext in State is correctly decrypted.
   *
   * Given the encryption service encrypts a plaintext token using the
   *   slack_portal profile,
   * And the resulting ciphertext is stored in State 'slack_portal.token_ciphertext',
   * And Settings and env vars have no token configured,
   * When getToken() is called,
   * Then the original plaintext token is returned,
   * And the stored State value is NOT the plaintext (encryption is at rest).
   */
  public function testDecryptsTokenFromState(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $plaintext = 'xoxp-secret-from-ui'; // pragma: allowlist secret

    /** @var \Drupal\encrypt\EncryptServiceInterface $encryptService */
    $encryptService = $this->container->get('encryption');
    $cipher = $encryptService->encrypt($plaintext, $this->profile);

    // Verify the stored value is NOT the plaintext (i.e., it is encrypted).
    $this->assertNotSame(
      $plaintext,
      $cipher,
      'The ciphertext stored in State must differ from the plaintext token.'
    );

    // Store the ciphertext in Drupal State.
    $this->container->get('state')->set('slack_portal.token_ciphertext', $cipher);

    // Build provider with empty Settings and no env var.
    putenv('SLACK_USER_TOKEN');
    $provider = $this->buildProvider(new Settings([]));

    $this->assertSame($plaintext, $provider->getToken());
  }

  /**
   * Tests that Settings take priority over an encrypted token in State.
   *
   * Given Settings has 'slack_user_token' set to a plaintext value,
   * And State also has an encrypted ciphertext stored,
   * When getToken() is called,
   * Then the Settings value is returned (Settings wins over State).
   */
  public function testSettingsTakesPriorityOverState(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $settingsToken = 'xoxp-from-settings'; // pragma: allowlist secret
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $stateToken = 'xoxp-from-state'; // pragma: allowlist secret

    /** @var \Drupal\encrypt\EncryptServiceInterface $encryptService */
    $encryptService = $this->container->get('encryption');
    $cipher = $encryptService->encrypt($stateToken, $this->profile);
    $this->container->get('state')->set('slack_portal.token_ciphertext', $cipher);

    putenv('SLACK_USER_TOKEN');
    $provider = $this->buildProvider(new Settings(['slack_user_token' => $settingsToken]));

    $this->assertSame($settingsToken, $provider->getToken());
  }

}
