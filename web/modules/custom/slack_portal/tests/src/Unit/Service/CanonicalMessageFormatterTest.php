<?php

declare(strict_types=1);

/**
 * @file
 * Unit tests for CanonicalMessageFormatter.
 */

namespace Drupal\Tests\slack_portal\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\slack_portal\Service\CanonicalMessageFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests CanonicalMessageFormatter transforms raw Slack messages correctly.
 *
 * @covers \Drupal\slack_portal\Service\CanonicalMessageFormatter
 */
#[CoversClass(\Drupal\slack_portal\Service\CanonicalMessageFormatter::class)]
#[Group('slack_portal')]
class CanonicalMessageFormatterTest extends UnitTestCase {

  /**
   * The formatter under test.
   */
  private CanonicalMessageFormatter $formatter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->formatter = new CanonicalMessageFormatter();
  }

  /**
   * Tests a full user message with threads and reply_count.
   *
   * Given a raw Slack message with ts, user, text, type, thread_ts, reply_count
   * and no edited key,
   * When format() is called,
   * Then all canonical fields are mapped correctly,
   * posted_at is derived from the integer-seconds part of ts as ISO-8601 UTC,
   * edited is false, and subtype is null.
   */
  public function testFullUserMessageMapsAllCanonicalFields(): void {
    // Arrange: ts=1700000000.000100 → integer part=1700000000
    // gmdate('Y-m-d\TH:i:s\Z', 1700000000) = '2023-11-14T22:13:20Z'
    $raw = [
      'ts'          => '1700000000.000100',
      'user'        => 'U1',
      'text'        => 'hi <@U2>',
      'type'        => 'message',
      'thread_ts'   => '1700000000.000100',
      'reply_count' => 2,
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert: identity fields.
    $this->assertSame('1700000000.000100', $result['slack_ts']);
    $this->assertSame('C123', $result['channel_id']);
    $this->assertSame('U1', $result['user_id']);
    $this->assertNull($result['bot_id']);
    $this->assertNull($result['username']);
    $this->assertSame('message', $result['type']);
    $this->assertNull($result['subtype']);
    $this->assertSame('hi <@U2>', $result['text']);

    // Assert: posted_at derived from integer-seconds part of ts.
    $expectedPostedAt = gmdate('Y-m-d\TH:i:s\Z', 1700000000);
    $this->assertSame('2023-11-14T22:13:20Z', $result['posted_at'], 'posted_at must be ISO-8601 UTC.');
    $this->assertSame($expectedPostedAt, $result['posted_at'], 'posted_at must match gmdate derivation from integer-seconds part of ts.');

    // Assert: edit/thread/reply fields.
    $this->assertFalse($result['edited'], 'edited must be false when raw lacks the edited key.');
    $this->assertSame('1700000000.000100', $result['thread_ts']);
    $this->assertSame(2, $result['reply_count']);

    // Assert: empty aggregates.
    $this->assertSame([], $result['reactions']);
    $this->assertSame([], $result['files']);
  }

  /**
   * Tests that edited=true when the raw message has an 'edited' key.
   *
   * Given a raw message with an 'edited' array value,
   * When format() is called,
   * Then edited is true.
   */
  public function testEditedKeyPresenceYieldsEditedTrue(): void {
    // Arrange.
    $raw = [
      'ts'     => '1700000000.000100',
      'user'   => 'U1',
      'type'   => 'message',
      'text'   => 'edited text',
      'edited' => ['ts' => '1700000001.000000', 'user' => 'U1'],
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertTrue($result['edited'], 'edited must be true when raw message has an edited key.');
  }

  /**
   * Tests that subtype is mapped from the raw message when present.
   *
   * Given a raw message with subtype='channel_join',
   * When format() is called,
   * Then subtype equals 'channel_join'.
   */
  public function testSubtypeIsMappedWhenPresent(): void {
    // Arrange.
    $raw = [
      'ts'      => '1700000000.000100',
      'user'    => 'U1',
      'type'    => 'message',
      'text'    => '',
      'subtype' => 'channel_join',
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertSame('channel_join', $result['subtype'], 'subtype must be mapped from raw when present.');
  }

  /**
   * Tests that a bot message maps bot_id/username and leaves user_id null.
   *
   * Given a raw message with bot_id and username but no user key,
   * When format() is called,
   * Then user_id is null, bot_id matches, username matches.
   */
  public function testBotMessageHasNullUserIdAndMappedBotFields(): void {
    // Arrange.
    $raw = [
      'ts'       => '1700000000.000100',
      'bot_id'   => 'B1',
      'username' => 'my-bot',
      'type'     => 'message',
      'text'     => 'I am a bot',
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertNull($result['user_id'], 'user_id must be null for bot messages without a user key.');
    $this->assertSame('B1', $result['bot_id'], 'bot_id must be mapped from raw.');
    $this->assertSame('my-bot', $result['username'], 'username must be mapped from raw.');
  }

  /**
   * Tests that reactions are formatted correctly when present.
   *
   * Given a raw message with a reactions array,
   * When format() or formatReactions() is called,
   * Then each reaction maps to {name, count (int), users (array)}.
   */
  public function testReactionsAreMappedToCanonicalShape(): void {
    // Arrange.
    $raw = [
      'ts'        => '1700000000.000100',
      'user'      => 'U1',
      'type'      => 'message',
      'text'      => 'great job',
      'reactions' => [
        ['name' => 'tada', 'count' => 2, 'users' => ['U1', 'U2']],
        ['name' => '+1',   'count' => 1, 'users' => ['U3']],
      ],
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert: canonical reactions from format().
    $this->assertCount(2, $result['reactions']);
    $this->assertSame(['name' => 'tada', 'count' => 2, 'users' => ['U1', 'U2']], $result['reactions'][0]);
    $this->assertSame(['name' => '+1',   'count' => 1, 'users' => ['U3']],       $result['reactions'][1]);

    // Also verify formatReactions() in isolation.
    $reactionsDirect = $this->formatter->formatReactions($raw);
    $this->assertSame($result['reactions'], $reactionsDirect, 'formatReactions() must return the same result as the reactions key in format().');
  }

  /**
   * Tests that formatReactions returns an empty array when reactions key is absent.
   *
   * Given a raw message without a reactions key,
   * When formatReactions() is called,
   * Then an empty array is returned.
   */
  public function testFormatReactionsReturnsEmptyArrayWhenAbsent(): void {
    // Arrange: no reactions key.
    $raw = ['ts' => '1700000000.000100', 'type' => 'message', 'text' => 'hi'];

    // Act.
    $reactions = $this->formatter->formatReactions($raw);

    // Assert.
    $this->assertSame([], $reactions, 'formatReactions() must return [] when reactions key is absent.');
  }

  /**
   * Tests that files are mapped to canonical file refs when present.
   *
   * Given a raw message with a files array,
   * When format() is called,
   * Then each file maps to {id, name, mimetype, url_private, local_path=null}.
   */
  public function testFilesAreMappedToCanonicalRefsWithNullLocalPath(): void {
    // Arrange.
    $raw = [
      'ts'    => '1700000000.000100',
      'user'  => 'U1',
      'type'  => 'message',
      'text'  => 'see attached',
      'files' => [
        [
          'id'          => 'F1',
          'name'        => 'x.png',
          'mimetype'    => 'image/png',
          'url_private' => 'https://files.slack.com/files-pri/T1-F1/x.png',
        ],
      ],
    ];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertCount(1, $result['files']);
    $expected = [
      'id'          => 'F1',
      'name'        => 'x.png',
      'mimetype'    => 'image/png',
      'url_private' => 'https://files.slack.com/files-pri/T1-F1/x.png',
      'local_path'  => NULL,
    ];
    $this->assertSame($expected, $result['files'][0], 'File canonical ref must include id, name, mimetype, url_private and local_path=null.');
  }

  /**
   * Tests that files returns an empty array when the raw message has no files.
   *
   * Given a raw message without a files key,
   * When format() is called,
   * Then files is [].
   */
  public function testFilesIsEmptyArrayWhenAbsent(): void {
    // Arrange: no files key.
    $raw = ['ts' => '1700000000.000100', 'user' => 'U1', 'type' => 'message', 'text' => 'no files'];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertSame([], $result['files'], 'files must be [] when raw message lacks a files key.');
  }

  /**
   * Tests that thread_ts is null when absent from the raw message.
   *
   * Given a raw message without a thread_ts key,
   * When format() is called,
   * Then thread_ts is null.
   */
  public function testThreadTsIsNullWhenAbsent(): void {
    // Arrange.
    $raw = ['ts' => '1700000000.000100', 'user' => 'U1', 'type' => 'message', 'text' => 'standalone'];

    // Act.
    $result = $this->formatter->format($raw, 'C123');

    // Assert.
    $this->assertNull($result['thread_ts'], 'thread_ts must be null when absent from raw message.');
    $this->assertSame(0, $result['reply_count'], 'reply_count must default to 0 when absent.');
  }

}
