<?php

declare(strict_types=1);

/**
 * @file
 * Settings form for Slack Portal credentials (workspace URL + encrypted token).
 */

namespace Drupal\slack_portal\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administration form for Slack Portal credentials.
 *
 * Allows site administrators to configure the Slack workspace URL and the
 * Slack user token. The token is encrypted at rest via the drupal/encrypt
 * module (real_aes / slack_portal profile) and stored in Drupal State. The
 * plaintext is never written to configuration or logs.
 */
final class SlackSettingsForm extends ConfigFormBase {

  /**
   * Constructs a SlackSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed configuration manager.
   * @param \Drupal\encrypt\EncryptServiceInterface $encryption
   *   The encrypt service for token encryption.
   * @param \Drupal\encrypt\EncryptionProfileManagerInterface $profileManager
   *   The encryption profile manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The Drupal state service for storing the token ciphertext.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly EncryptServiceInterface $encryption,
    private readonly EncryptionProfileManagerInterface $profileManager,
    private readonly StateInterface $state,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('encryption'),
      $container->get('encrypt.encryption_profile.manager'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'slack_portal_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['slack_portal.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('slack_portal.settings');

    $form['workspace_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Slack Workspace URL'),
      '#description' => $this->t('The URL of your Slack workspace, e.g. https://your-team.slack.com'),
      '#default_value' => $config->get('workspace_url') ?? '',
      '#required' => FALSE,
    ];

    // Determine whether a token is already stored (non-empty ciphertext).
    $existingCipher = $this->state->get(SlackTokenProvider::TOKEN_STATE_KEY);
    $hasStoredToken = is_string($existingCipher) && $existingCipher !== '';

    if ($hasStoredToken) {
      $description = $this->t('設定済み（再入力すると上書き。空欄なら現状維持）');
    }
    else {
      $description = $this->t('未設定');
    }

    $form['slack_user_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Slack User Token'),
      '#description' => $description,
      '#default_value' => '',
      '#required' => FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save workspace URL to config.
    $this->config('slack_portal.settings')
      ->set('workspace_url', $form_state->getValue('workspace_url'))
      ->save();

    // Handle token encryption only when a non-empty token was submitted.
    $submittedToken = (string) $form_state->getValue('slack_user_token');
    if ($submittedToken !== '') {
      $profiles = $this->profileManager->getAllEncryptionProfiles();
      if (!isset($profiles[SlackTokenProvider::ENCRYPTION_PROFILE_ID])) {
        // The encryption profile is not installed; warn the operator.
        $this->messenger()->addError(
          $this->t('暗号化プロファイル未設定（SLACK_ENCRYPTION_KEY を設定してください）')
        );
        // Do NOT call parent::submitForm() so the default status message is
        // not shown when we cannot actually save the token.
        return;
      }

      $profile = $profiles[SlackTokenProvider::ENCRYPTION_PROFILE_ID];
      // Encrypt the token; NEVER log the plaintext or the ciphertext.
      $cipher = $this->encryption->encrypt($submittedToken, $profile);
      $this->state->set(SlackTokenProvider::TOKEN_STATE_KEY, $cipher);
    }
    // Empty token: existing State ciphertext is left untouched.
    parent::submitForm($form, $form_state);
  }

}
