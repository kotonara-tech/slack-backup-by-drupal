<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackSettingsForm — credential encryption via settings UI.
 */

namespace Drupal\Tests\slack_portal\Kernel\Form;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\key\Entity\Key;
use Drupal\slack_portal\Form\SlackSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for SlackSettingsForm.
 *
 * Verifies that the Slack credentials settings form:
 * - Encrypts the submitted token and stores the ciphertext in Drupal State.
 * - Saves workspace_url to config.
 * - Keeps existing ciphertext when the token field is re-submitted empty.
 * - Never exposes the plaintext token in state or config.
 * - Renders the token field description indicating "設定済み" when stored.
 *
 * @covers \Drupal\slack_portal\Form\SlackSettingsForm
 */
#[CoversClass(SlackSettingsForm::class)]
#[Group('slack_portal')]
class SlackSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'key',
    'encrypt',
    'real_aes',
    'system',
    'user',
    'slack_portal',
  ];

  /**
   * The encryption profile.
   *
   * @var \Drupal\encrypt\EncryptionProfileInterface
   */
  private $profile;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install config for system (required by ConfigFormBase / messenger).
    $this->installConfig(['system']);

    // Create a 256-bit config-provider key for real_aes (32 bytes required).
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $keyValue = 'mustbesixteenbitmustbesixteenbit'; // pragma: allowlist secret
    Key::create([
      'id' => 'slack_portal_encryption',
      'label' => 'Slack Portal Encryption Key',
      'key_type' => 'encryption',
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $keyValue],
    ])->save();

    // Create the encryption profile pointing at the test key.
    EncryptionProfile::create([
      'id' => 'slack_portal',
      'label' => 'Slack Portal',
      'encryption_method' => 'real_aes',
      'encryption_key' => 'slack_portal_encryption',
    ])->save();

    /** @var \Drupal\encrypt\EncryptionProfileManagerInterface $profileManager */
    $profileManager = $this->container->get('encrypt.encryption_profile.manager');
    $this->profile = $profileManager->getEncryptionProfile('slack_portal');
  }

  /**
   * Tests that submitting a token encrypts it and stores the ciphertext.
   *
   * Given the form is submitted with a workspace URL and a Slack user token,
   * When submitForm() is processed,
   * Then State stores a ciphertext (non-empty, not equal to the plaintext),
   * And decrypting the ciphertext returns the original token,
   * And config stores the workspace URL.
   */
  public function testSubmitEncryptsTokenAndSavesUrl(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $plainToken = 'xoxp-ui-entered'; // pragma: allowlist secret
    $workspaceUrl = 'https://acme.slack.com';

    $formState = new FormState();
    $formState->setValues([
      'workspace_url' => $workspaceUrl,
      'slack_user_token' => $plainToken,
    ]);

    $this->container->get('form_builder')->submitForm(SlackSettingsForm::class, $formState);

    /** @var \Drupal\Core\State\StateInterface $state */
    $state = $this->container->get('state');
    $cipher = $state->get('slack_portal.token_ciphertext');

    // Assertion 1: ciphertext is a non-empty string.
    $this->assertIsString($cipher, 'State must contain a string ciphertext.');
    $this->assertNotEmpty($cipher, 'Stored ciphertext must not be empty.');

    // Assertion 2: ciphertext is NOT the plaintext.
    $this->assertNotSame(
      $plainToken,
      $cipher,
      'Ciphertext stored in State must differ from the plaintext token.'
    );

    // Assertion 3: decrypting returns the original token.
    /** @var \Drupal\encrypt\EncryptServiceInterface $encryptService */
    $encryptService = $this->container->get('encryption');
    $decrypted = $encryptService->decrypt($cipher, $this->profile);
    $this->assertSame(
      $plainToken,
      $decrypted,
      'Decrypting the stored ciphertext must return the original plaintext token.'
    );

    // Assertion 4: workspace_url is saved to config.
    $savedUrl = $this->config('slack_portal.settings')->get('workspace_url');
    $this->assertSame(
      $workspaceUrl,
      $savedUrl,
      'workspace_url must be saved to slack_portal.settings config.'
    );
  }

  /**
   * Tests that an empty token submission keeps the existing ciphertext.
   *
   * Given a token is already stored as a ciphertext in State,
   * When the form is submitted with an empty token field but a new URL,
   * Then the ciphertext in State is unchanged (still decrypts to the original),
   * And the workspace URL is updated.
   */
  public function testEmptyTokenKeepsExistingCiphertext(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $originalToken = 'xoxp-ui-entered'; // pragma: allowlist secret
    // Pre-store an encrypted token in State via a first submit.
    $firstState = new FormState();
    $firstState->setValues([
      'workspace_url' => 'https://old.slack.com',
      'slack_user_token' => $originalToken,
    ]);
    $this->container->get('form_builder')->submitForm(SlackSettingsForm::class, $firstState);

    /** @var \Drupal\Core\State\StateInterface $state */
    $state = $this->container->get('state');
    $originalCipher = $state->get('slack_portal.token_ciphertext');
    $this->assertNotEmpty($originalCipher, 'Pre-condition: ciphertext must be stored.');

    // Second submit: empty token, different URL.
    $secondState = new FormState();
    $secondState->setValues([
      'workspace_url' => 'https://new.slack.com',
      'slack_user_token' => '',
    ]);
    $this->container->get('form_builder')->submitForm(SlackSettingsForm::class, $secondState);

    $cipherAfter = $state->get('slack_portal.token_ciphertext');

    // Assertion 4 (spec): ciphertext is unchanged.
    $this->assertSame(
      $originalCipher,
      $cipherAfter,
      'Ciphertext must be unchanged when token field is empty on re-submit.'
    );

    // Decryption still returns the original token.
    /** @var \Drupal\encrypt\EncryptServiceInterface $encryptService */
    $encryptService = $this->container->get('encryption');
    $decrypted = $encryptService->decrypt($cipherAfter, $this->profile);
    $this->assertSame(
      $originalToken,
      $decrypted,
      'Decrypting unchanged ciphertext must still return the original token.'
    );

    // URL is updated.
    $this->assertSame(
      'https://new.slack.com',
      $this->config('slack_portal.settings')->get('workspace_url'),
      'workspace_url must be updated on empty-token re-submit.'
    );
  }

  /**
   * Tests that the rendered token field never contains the real token value.
   *
   * Given a token ciphertext is stored in State,
   * When the form is built via getForm(),
   * Then the token field has no real-token #default_value,
   * And its description signals "設定済み".
   */
  public function testBuildFormShowsSetStatusWithoutRevealingToken(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $plainToken = 'xoxp-ui-entered'; // pragma: allowlist secret
    // Store a ciphertext in State first.
    /** @var \Drupal\encrypt\EncryptServiceInterface $encryptService */
    $encryptService = $this->container->get('encryption');
    $cipher = $encryptService->encrypt($plainToken, $this->profile);
    $this->container->get('state')->set('slack_portal.token_ciphertext', $cipher);

    // Build the form.
    $form = $this->container->get('form_builder')->getForm(SlackSettingsForm::class);

    // Assertion 5: token field default value is empty (not the real token).
    $tokenFieldValue = $form['slack_user_token']['#default_value'] ?? '';
    $this->assertNotSame(
      $plainToken,
      $tokenFieldValue,
      'The token field must NOT contain the plaintext token as its default value.'
    );

    // The description must signal "設定済み".
    $description = $form['slack_user_token']['#description'] ?? '';
    if ($description instanceof TranslatableMarkup) {
      $description = (string) $description;
    }
    $this->assertStringContainsString(
      '設定済み',
      (string) $description,
      'Token field description must indicate "設定済み" when a ciphertext is stored.'
    );
  }

  /**
   * Tests that the plaintext token never appears in State or config.
   *
   * Given the form is submitted with a plaintext token,
   * When submitForm() is processed,
   * Then neither Drupal State nor the config object contains the raw plaintext.
   */
  public function testPlaintextTokenNeverStoredInStateOrConfig(): void {
    // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar
    $plainToken = 'xoxp-ui-entered'; // pragma: allowlist secret
    $formState = new FormState();
    $formState->setValues([
      'workspace_url' => 'https://acme.slack.com',
      'slack_user_token' => $plainToken,
    ]);
    $this->container->get('form_builder')->submitForm(SlackSettingsForm::class, $formState);

    /** @var \Drupal\Core\State\StateInterface $state */
    $state = $this->container->get('state');
    $cipher = $state->get('slack_portal.token_ciphertext');

    // Assertion 6: plaintext is not in State value.
    $this->assertNotSame(
      $plainToken,
      $cipher,
      'Plaintext token must not appear as State value.'
    );

    // Plaintext must not be in any config value.
    $configData = $this->config('slack_portal.settings')->getRawData();
    $configJson = json_encode($configData);
    $this->assertStringNotContainsString(
      $plainToken,
      (string) $configJson,
      'Plaintext token must not appear in any config value.'
    );
  }

}
