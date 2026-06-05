<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for SlackWorkspaceMapper — pure user/channel mapping logic.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\SlackWorkspaceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for SlackWorkspaceMapper.
 *
 * Verifies pure mapping of raw Slack API shapes to canonical user/channel
 * arrays. No I/O, no network, no DB — pure logic (small test).
 *
 * @covers \Drupal\slack_portal\Service\SlackWorkspaceMapper
 */
#[CoversClass(\Drupal\slack_portal\Service\SlackWorkspaceMapper::class)]
#[Group('slack_portal')]
class SlackWorkspaceMapperTest extends UnitTestCase {

  /**
   * The mapper under test.
   *
   * @var \Drupal\slack_portal\Service\SlackWorkspaceMapper
   */
  private SlackWorkspaceMapper $mapper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mapper = new SlackWorkspaceMapper();
  }

  /**
   * Tests toCanonicalUser maps all fields from a full raw user record.
   *
   * Given a raw user array with id, name, real_name, profile.display_name,
   *   profile.email, profile.image_192, is_bot=false, deleted=false,
   * When toCanonicalUser() is called,
   * Then all canonical fields are set correctly.
   */
  public function testToCanonicalUserMapsAllFields(): void {
    $raw = [
      'id' => 'U1',
      'name' => 'alice',
      'real_name' => 'Alice Smith',
      'profile' => [
        'display_name' => 'al',
        'email' => 'alice@example.test',
        'image_192' => 'https://example.test/av.png',
      ],
      'is_bot' => FALSE,
      'deleted' => FALSE,
    ];

    $result = $this->mapper->toCanonicalUser($raw);

    $this->assertSame('U1', $result['id']);
    $this->assertSame('alice', $result['name']);
    $this->assertSame('Alice Smith', $result['real_name']);
    $this->assertSame('al', $result['display_name']);
    $this->assertSame('alice@example.test', $result['email']);
    $this->assertFalse($result['is_bot']);
    $this->assertFalse($result['deleted']);
    $this->assertSame('https://example.test/av.png', $result['avatar']);
  }

  /**
   * Tests toCanonicalUser returns null for missing optional profile fields.
   *
   * Given a raw user with no profile sub-array,
   * When toCanonicalUser() is called,
   * Then display_name, email, and avatar are null.
   */
  public function testToCanonicalUserHandlesMissingProfile(): void {
    $raw = [
      'id' => 'U2',
      'name' => 'bot',
      'is_bot' => TRUE,
      'deleted' => FALSE,
    ];

    $result = $this->mapper->toCanonicalUser($raw);

    $this->assertSame('U2', $result['id']);
    $this->assertNull($result['real_name']);
    $this->assertNull($result['display_name']);
    $this->assertNull($result['email']);
    $this->assertNull($result['avatar']);
    $this->assertTrue($result['is_bot']);
  }

  /**
   * Tests toCanonicalUser sets deleted=true for deleted users.
   *
   * Given a raw user with deleted=true,
   * When toCanonicalUser() is called,
   * Then deleted is true in the canonical output.
   */
  public function testToCanonicalUserHandlesDeletedUser(): void {
    $raw = [
      'id' => 'U3',
      'name' => 'gone',
      'deleted' => TRUE,
    ];

    $result = $this->mapper->toCanonicalUser($raw);

    $this->assertTrue($result['deleted']);
  }

  /**
   * Provides raw channel fixtures and expected types for toChannelMeta tests.
   *
   * @return array<string,array{array<string,mixed>,string}>
   *   Each entry: [raw channel array, expected type string].
   */
  public static function channelTypeProvider(): array {
    return [
      'public channel' => [
        [
          'id' => 'C1',
          'name' => 'general',
          'is_private' => FALSE,
          'is_im' => FALSE,
          'is_mpim' => FALSE,
          'topic' => ['value' => 'Company-wide announcements'],
          'purpose' => ['value' => 'For everyone'],
        ],
        'public_channel',
      ],
      'private channel' => [
        [
          'id' => 'G1',
          'name' => 'secret-team',
          'is_private' => TRUE,
          'is_im' => FALSE,
          'is_mpim' => FALSE,
          'topic' => ['value' => ''],
          'purpose' => ['value' => ''],
        ],
        'private_channel',
      ],
      'direct message' => [
        [
          'id' => 'D1',
          'is_im' => TRUE,
          'is_mpim' => FALSE,
          'is_private' => TRUE,
          'user' => 'U1',
        ],
        'im',
      ],
      'multi-party DM' => [
        [
          'id' => 'M1',
          'name' => 'mpdm-alice--bob--carol-1',
          'is_im' => FALSE,
          'is_mpim' => TRUE,
          'is_private' => TRUE,
        ],
        'mpim',
      ],
    ];
  }

  /**
   * Tests toChannelMeta derives the correct type from channel flags.
   *
   * Given raw channel arrays for each type (public/private/im/mpim),
   * When toChannelMeta() is called,
   * Then the type field matches the expected value.
   *
   * @param array<string,mixed> $raw
   *   The raw channel array from conversations.list.
   * @param string $expectedType
   *   The expected canonical type string.
   */
  #[DataProvider('channelTypeProvider')]
  public function testToChannelMetaDerivesType(array $raw, string $expectedType): void {
    $result = $this->mapper->toChannelMeta($raw);

    $this->assertSame($expectedType, $result['type']);
  }

  /**
   * Tests toChannelMeta maps all fields for a public channel.
   *
   * Given a full raw public channel record,
   * When toChannelMeta() is called,
   * Then all canonical fields are mapped correctly.
   */
  public function testToChannelMetaMapsAllFieldsForPublicChannel(): void {
    $raw = [
      'id' => 'C1',
      'name' => 'general',
      'is_private' => FALSE,
      'is_im' => FALSE,
      'is_mpim' => FALSE,
      'topic' => ['value' => 'Company-wide'],
      'purpose' => ['value' => 'For everyone'],
    ];

    $result = $this->mapper->toChannelMeta($raw);

    $this->assertSame('C1', $result['id']);
    $this->assertSame('general', $result['name']);
    $this->assertSame('public_channel', $result['type']);
    $this->assertFalse($result['is_private']);
    $this->assertFalse($result['is_im']);
    $this->assertFalse($result['is_mpim']);
    $this->assertSame([], $result['members']);
    $this->assertSame('Company-wide', $result['topic']);
    $this->assertSame('For everyone', $result['purpose']);
  }

  /**
   * Tests toChannelMeta uses user as name fallback for IM channels.
   *
   * Given a raw IM channel with no 'name' key but a 'user' key,
   * When toChannelMeta() is called,
   * Then the name field equals the user ID.
   */
  public function testToChannelMetaUsesUserAsFallbackNameForIm(): void {
    $raw = [
      'id' => 'D1',
      'is_im' => TRUE,
      'is_mpim' => FALSE,
      'is_private' => TRUE,
      'user' => 'U1',
    ];

    $result = $this->mapper->toChannelMeta($raw);

    $this->assertSame('U1', $result['name']);
    $this->assertSame('im', $result['type']);
  }

  /**
   * Tests toChannelMeta falls back to id when name and user are both absent.
   *
   * Given a raw channel with no 'name' and no 'user' key,
   * When toChannelMeta() is called,
   * Then the name field equals the channel ID.
   */
  public function testToChannelMetaFallsBackToIdWhenNoName(): void {
    $raw = [
      'id' => 'X1',
      'is_im' => FALSE,
      'is_mpim' => FALSE,
      'is_private' => FALSE,
    ];

    $result = $this->mapper->toChannelMeta($raw);

    $this->assertSame('X1', $result['name']);
  }

  /**
   * Tests toChannelMeta returns empty strings for missing topic/purpose.
   *
   * Given a raw channel with no topic or purpose keys,
   * When toChannelMeta() is called,
   * Then topic and purpose are empty strings.
   */
  public function testToChannelMetaHandlesMissingTopicAndPurpose(): void {
    $raw = [
      'id' => 'C2',
      'name' => 'random',
      'is_private' => FALSE,
      'is_im' => FALSE,
      'is_mpim' => FALSE,
    ];

    $result = $this->mapper->toChannelMeta($raw);

    $this->assertSame('', $result['topic']);
    $this->assertSame('', $result['purpose']);
  }

  /**
   * Tests toChannelIndexEntry builds the canonical manifest index entry.
   *
   * The {id,name,type,file} shape is the single source of truth shared by the
   * Drush command and the queue worker.
   */
  public function testToChannelIndexEntry(): void {
    $mapper = new SlackWorkspaceMapper();
    $entry = $mapper->toChannelIndexEntry([
      'id' => 'C123',
      'name' => 'general',
      'type' => 'public_channel',
    ]);

    $this->assertSame(
      [
        'id' => 'C123',
        'name' => 'general',
        'type' => 'public_channel',
        'file' => 'channels/C123.json',
      ],
      $entry,
    );
  }

  /**
   * Tests toChannelIndexEntry falls back to id for name and a default type.
   */
  public function testToChannelIndexEntryFallsBack(): void {
    $mapper = new SlackWorkspaceMapper();
    $entry = $mapper->toChannelIndexEntry(['id' => 'D9']);

    $this->assertSame('D9', $entry['name'], 'name falls back to id.');
    $this->assertSame('public_channel', $entry['type'], 'type defaults to public_channel.');
    $this->assertSame('channels/D9.json', $entry['file']);
  }

}
