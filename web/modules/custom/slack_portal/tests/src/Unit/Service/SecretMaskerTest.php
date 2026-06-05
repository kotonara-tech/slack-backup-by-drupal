<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SecretMasker — Slack token redaction.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\slack_portal\Service\SecretMasker;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for SecretMasker.
 *
 * @covers \Drupal\slack_portal\Service\SecretMasker
 */
#[CoversClass(SecretMasker::class)]
#[Group('slack_portal')]
class SecretMaskerTest extends UnitTestCase {

  /**
   * Test-only placeholder user token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const FAKE_TOKEN = 'xoxp-deadbeef-1234'; // pragma: allowlist secret

  /**
   * Test-only placeholder bot token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const FAKE_BOT = 'xoxb-deadbeef-5678'; // pragma: allowlist secret

  /**
   * Tests that a Slack user token substring is redacted.
   */
  public function testMasksUserToken(): void {
    $masked = SecretMasker::mask('export failed using ' . self::FAKE_TOKEN . ' now');
    $this->assertStringNotContainsString(self::FAKE_TOKEN, $masked, 'Token must be redacted.');
    $this->assertStringContainsString('[REDACTED]', $masked);
  }

  /**
   * Tests that a bot token (xoxb-) is also redacted.
   */
  public function testMasksBotToken(): void {
    $masked = SecretMasker::mask('client error: ' . self::FAKE_BOT);
    $this->assertStringNotContainsString(self::FAKE_BOT, $masked);
    $this->assertStringContainsString('[REDACTED]', $masked);
  }

  /**
   * Tests that text without a token is returned unchanged.
   */
  public function testLeavesPlainTextUnchanged(): void {
    $this->assertSame(
      'Slack user token is not configured.',
      SecretMasker::mask('Slack user token is not configured.'),
    );
  }

}
