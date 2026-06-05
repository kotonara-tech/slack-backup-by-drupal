<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SlackTokenProvider.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\encrypt\EncryptionProfileInterface;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\slack_portal\Service\SlackTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests SlackTokenProvider resolves token/config from Settings, env, and State.
 *
 * @covers \Drupal\slack_portal\Service\SlackTokenProvider
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackTokenProvider::class)]
#[Group('slack_portal')]
class SlackTokenProviderTest extends UnitTestCase {

  /**
   * Fake Slack user token used only in tests (never a real credential).
   *
   * The value is a test-only placeholder; the pragma on the const line
   * prevents detect-secrets from flagging it as a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test-token'; // pragma: allowlist secret

  /**
   * Alternate fake token used to test Settings-over-env precedence.
   *
   * Only used in testSettingsTakesPrecedenceOverEnvForToken(). Never a real
   * credential; the pragma prevents detect-secrets from flagging it.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const ALT_TOKEN = 'xoxp-env-alt-tok'; // pragma: allowlist secret

  /**
   * Saves the original env token value so tests can restore it.
   *
   * @var string|false
   */
  private string|false $originalEnvToken;

  /**
   * Mock StateInterface (returns null by default — no encrypted token stored).
   *
   * @var \Drupal\Core\State\StateInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private StateInterface $state;

  /**
   * Mock EncryptServiceInterface (should not be called in Settings/env paths).
   *
   * @var \Drupal\encrypt\EncryptServiceInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private EncryptServiceInterface $encryption;

  /**
   * Mock EncryptionProfileManagerInterface.
   *
   * @var \Drupal\encrypt\EncryptionProfileManagerInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private EncryptionProfileManagerInterface $profileManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalEnvToken = getenv('SLACK_USER_TOKEN');
    // Clear env vars before each test so Settings is the only source.
    putenv('SLACK_USER_TOKEN');
    putenv('SLACK_EXPORT_SINCE_DAYS');
    putenv('SLACK_RATE_LIMIT_MAX_RETRIES');

    // State returns null — encryption never called in Settings/env path.
    $this->state = $this->createMock(StateInterface::class);
    $this->state->method('get')->willReturn(NULL);

    $this->encryption = $this->createMock(EncryptServiceInterface::class);
    $this->profileManager = $this->createMock(EncryptionProfileManagerInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Restore original env values.
    if ($this->originalEnvToken !== FALSE) {
      putenv('SLACK_USER_TOKEN=' . $this->originalEnvToken);
    }
    else {
      putenv('SLACK_USER_TOKEN');
    }
    putenv('SLACK_EXPORT_SINCE_DAYS');
    putenv('SLACK_RATE_LIMIT_MAX_RETRIES');
    parent::tearDown();
  }

  /**
   * Builds a SlackTokenProvider with mock deps and the given Settings.
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
      $this->state,
      $this->encryption,
      $this->profileManager,
    );
  }

  /**
   * Tests that getToken() returns the value from Settings when configured.
   *
   * Given Settings has slack_user_token set to the test token,
   * When getToken() is called,
   * Then the token value is returned unchanged.
   */
  public function testGetTokenReturnsSettingsToken(): void {
    $settings = new Settings(['slack_user_token' => self::TEST_TOKEN]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(self::TEST_TOKEN, $provider->getToken());
  }

  /**
   * Tests that getToken() falls back to the env var when Settings has no token.
   *
   * Given Settings has no slack_user_token,
   * And env var SLACK_USER_TOKEN is set,
   * When getToken() is called,
   * Then the env var value is returned.
   */
  public function testGetTokenFallsBackToEnvVar(): void {
    putenv('SLACK_USER_TOKEN=' . self::TEST_TOKEN);
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(self::TEST_TOKEN, $provider->getToken());
  }

  /**
   * Tests that getToken() throws RuntimeException when no token is configured.
   *
   * Given Settings has no slack_user_token,
   * And env var SLACK_USER_TOKEN is unset,
   * And State has no ciphertext,
   * When getToken() is called,
   * Then a RuntimeException is thrown.
   */
  public function testGetTokenThrowsWhenNotConfigured(): void {
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->expectException(\RuntimeException::class);
    $provider->getToken();
  }

  /**
   * Tests that the thrown exception message does not expose the token value.
   *
   * Given Settings has no slack_user_token and env var is unset,
   * When getToken() is called and throws,
   * Then the exception message does not contain 'xoxp'.
   */
  public function testGetTokenExceptionMessageDoesNotContainToken(): void {
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    try {
      $provider->getToken();
      $this->fail('Expected RuntimeException was not thrown.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringNotContainsStringIgnoringCase(
        'xoxp',
        $e->getMessage(),
        'Exception message must not contain the token value or "xoxp" prefix.'
      );
    }
  }

  /**
   * Tests that State ciphertext path skips decryption when profile not found.
   *
   * Given State has a ciphertext stored,
   * And the EncryptionProfileManager returns NULL for the profile ID,
   * When getToken() is called,
   * Then a RuntimeException is thrown (falls through to "not configured").
   */
  public function testGetTokenSkipsDecryptWhenProfileNotFound(): void {
    // State returns a non-empty ciphertext.
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('slack_portal.token_ciphertext')
      ->willReturn('some-ciphertext');

    // Profile manager returns NULL => profile not found.
    $profileManager = $this->createMock(EncryptionProfileManagerInterface::class);
    $profileManager->method('getEncryptionProfile')
      ->willReturn(NULL);

    $encryption = $this->createMock(EncryptServiceInterface::class);
    $encryption->expects($this->never())->method('decrypt');

    $provider = new SlackTokenProvider(
      new Settings([]),
      $state,
      $encryption,
      $profileManager,
    );

    $this->expectException(\RuntimeException::class);
    $provider->getToken();
  }

  /**
   * Tests that a decrypt exception wraps as RuntimeException without cipher.
   *
   * Given State has a ciphertext and the profile is found,
   * And encryption->decrypt() throws,
   * When getToken() is called,
   * Then a RuntimeException is thrown with a generic message (no ciphertext).
   */
  public function testGetTokenThrowsCleanExceptionOnDecryptFailure(): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')
      ->with('slack_portal.token_ciphertext')
      ->willReturn('bad-ciphertext');

    $profile = $this->createMock(EncryptionProfileInterface::class);
    $profileManager = $this->createMock(EncryptionProfileManagerInterface::class);
    $profileManager->method('getEncryptionProfile')
      ->willReturn($profile);

    $encryption = $this->createMock(EncryptServiceInterface::class);
    $encryption->method('decrypt')
      ->willThrowException(new \Exception('crypto error'));

    $provider = new SlackTokenProvider(
      new Settings([]),
      $state,
      $encryption,
      $profileManager,
    );

    try {
      $provider->getToken();
      $this->fail('Expected RuntimeException was not thrown.');
    }
    catch (\RuntimeException $e) {
      // Message must not reveal the ciphertext.
      $this->assertStringNotContainsString(
        'bad-ciphertext',
        $e->getMessage(),
        'Exception message must not expose the ciphertext.'
      );
      $this->assertStringContainsString(
        'decrypted',
        $e->getMessage(),
      );
    }
  }

  /**
   * Tests that getSinceDays() returns 90 by default when nothing is configured.
   *
   * Given Settings has no slack_export_since_days and env is unset,
   * When getSinceDays() is called,
   * Then 90 is returned.
   */
  public function testGetSinceDaysDefaultsToNinety(): void {
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(90, $provider->getSinceDays());
  }

  /**
   * Tests that getSinceDays() returns a custom value from Settings.
   *
   * Given Settings has slack_export_since_days set to 30,
   * When getSinceDays() is called,
   * Then 30 is returned.
   */
  public function testGetSinceDaysHonorsSettings(): void {
    $settings = new Settings(['slack_export_since_days' => 30]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(30, $provider->getSinceDays());
  }

  /**
   * Tests that getSinceDays() reads from the env var when Settings is absent.
   *
   * Given Settings has no slack_export_since_days,
   * And env var SLACK_EXPORT_SINCE_DAYS is set to 14,
   * When getSinceDays() is called,
   * Then 14 is returned.
   */
  public function testGetSinceDaysHonorsEnvVar(): void {
    putenv('SLACK_EXPORT_SINCE_DAYS=14');
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(14, $provider->getSinceDays());
  }

  /**
   * Tests that getSinceDays() falls back to 90 when value is 0 or negative.
   *
   * Given Settings has slack_export_since_days set to 0,
   * When getSinceDays() is called,
   * Then 90 is returned (zero/negative treated as unset).
   */
  public function testGetSinceDaysDefaultsWhenZero(): void {
    $settings = new Settings(['slack_export_since_days' => 0]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(90, $provider->getSinceDays());
  }

  /**
   * Tests that getMaxRetries() returns 10 by default.
   *
   * Given Settings has no slack_rate_limit_max_retries and env is unset,
   * When getMaxRetries() is called,
   * Then 10 is returned.
   */
  public function testGetMaxRetriesDefaultsToTen(): void {
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(10, $provider->getMaxRetries());
  }

  /**
   * Tests that getMaxRetries() returns a custom value from Settings.
   *
   * Given Settings has slack_rate_limit_max_retries set to 5,
   * When getMaxRetries() is called,
   * Then 5 is returned.
   */
  public function testGetMaxRetriesHonorsSettings(): void {
    $settings = new Settings(['slack_rate_limit_max_retries' => 5]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(5, $provider->getMaxRetries());
  }

  /**
   * Tests that getMaxRetries() reads from the env var when Settings is absent.
   *
   * Given Settings has no slack_rate_limit_max_retries,
   * And env var SLACK_RATE_LIMIT_MAX_RETRIES is set to 3,
   * When getMaxRetries() is called,
   * Then 3 is returned.
   */
  public function testGetMaxRetriesHonorsEnvVar(): void {
    putenv('SLACK_RATE_LIMIT_MAX_RETRIES=3');
    $settings = new Settings([]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(3, $provider->getMaxRetries());
  }

  /**
   * Tests that getMaxRetries() falls back to 10 when value is 0 or negative.
   *
   * Given Settings has slack_rate_limit_max_retries set to -1,
   * When getMaxRetries() is called,
   * Then 10 is returned.
   */
  public function testGetMaxRetriesDefaultsWhenNegative(): void {
    $settings = new Settings(['slack_rate_limit_max_retries' => -1]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(10, $provider->getMaxRetries());
  }

  /**
   * Tests that Settings takes precedence over the env var for getToken().
   *
   * Given Settings has slack_user_token set,
   * And env var SLACK_USER_TOKEN is also set to a different value,
   * When getToken() is called,
   * Then the Settings value is returned (Settings wins).
   */
  public function testSettingsTakesPrecedenceOverEnvForToken(): void {
    // Set a competing env token; Settings must win.
    putenv('SLACK_USER_TOKEN=' . self::ALT_TOKEN);
    $settings = new Settings(['slack_user_token' => self::TEST_TOKEN]);
    $provider = $this->buildProvider($settings);

    $this->assertSame(self::TEST_TOKEN, $provider->getToken());
  }

  /**
   * Tests that getToken() trims whitespace from the returned value.
   *
   * Given Settings has slack_user_token set with surrounding whitespace,
   * When getToken() is called,
   * Then the trimmed value is returned.
   */
  public function testGetTokenTrimsWhitespace(): void {
    $settings = new Settings(['slack_user_token' => '  ' . self::TEST_TOKEN . '  ']);
    $provider = $this->buildProvider($settings);

    $this->assertSame(self::TEST_TOKEN, $provider->getToken());
  }

}
