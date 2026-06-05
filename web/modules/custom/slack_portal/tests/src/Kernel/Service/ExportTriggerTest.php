<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for ExportTrigger — workspace enumeration and queue dispatch.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\encrypt\EncryptionProfileManagerInterface;
use Drupal\encrypt\EncryptServiceInterface;
use Drupal\slack_portal\Service\CanonicalJsonWriter;
use Drupal\slack_portal\Service\CursorIterator;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\ExportTrigger;
use Drupal\slack_portal\Service\SlackClientFactory;
use Drupal\slack_portal\Service\SlackFetcher;
use Drupal\slack_portal\Service\SlackTokenProvider;
use Drupal\slack_portal\Service\SlackWorkspaceMapper;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use JoliCode\Slack\Api\Client as SlackApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Kernel tests for ExportTrigger.
 *
 * Verifies that trigger() enumerates users + channels, writes users.json,
 * enqueues one item per channel, and sets ExportStateService to 'running'.
 *
 * The slack_portal module is NOT enabled. All services are built directly.
 * A non-final subclass of SlackClientFactory overrides create() to inject
 * a MockHandler, so no real Slack API calls are made.
 *
 * MockHandler queue (must match ExportTrigger::trigger() call order):
 *  1. users.list  → 1 user (U1)
 *  2. conversations.list → C1 (public) + D1 (im)
 *
 * @covers \Drupal\slack_portal\Service\ExportTrigger
 */
#[CoversClass(\Drupal\slack_portal\Service\ExportTrigger::class)]
#[Group('slack_portal')]
class ExportTriggerTest extends KernelTestBase {

  /**
   * Only system is needed to set up the public:// stream wrapper.
   *
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Test-only placeholder token — NOT a real credential.
   *
   * @var string
   */
  // phpcs:ignore Drupal.Commenting.PostStatementComment.Found,Drupal.Commenting.InlineComment.InvalidEndChar,DrupalPractice.Commenting.CommentEmptyLine.SpacingAfter
  private const TEST_TOKEN = 'xoxp-test'; // pragma: allowlist secret

  /**
   * Builds the MockHandler queue for ExportTrigger::trigger().
   *
   * Call order: (1) users.list (U1), (2) conversations.list (C1 + D1).
   *
   * @return \GuzzleHttp\Handler\MockHandler
   *   The MockHandler with ordered responses.
   */
  private function buildMockHandler(): MockHandler {
    $usersBody = json_encode([
      'ok' => TRUE,
      'members' => [
        [
          'id' => 'U1',
          'name' => 'alice',
          'real_name' => 'Alice',
          'profile' => [
            'display_name' => 'al',
            'email' => 'a@x.test',
            'image_192' => 'https://example.test/av.png',
          ],
          'is_bot' => FALSE,
          'deleted' => FALSE,
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    $channelsBody = json_encode([
      'ok' => TRUE,
      'channels' => [
        [
          'id' => 'C1',
          'name' => 'general',
          'is_private' => FALSE,
          'is_im' => FALSE,
          'is_mpim' => FALSE,
          'topic' => ['value' => 'Company'],
          'purpose' => ['value' => 'For all'],
        ],
        [
          'id' => 'D1',
          'is_im' => TRUE,
          'is_mpim' => FALSE,
          'is_private' => TRUE,
          'user' => 'U1',
        ],
      ],
      'response_metadata' => ['next_cursor' => ''],
    ]);

    return new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], (string) $usersBody),
      new Response(200, ['Content-Type' => 'application/json'], (string) $channelsBody),
    ]);
  }

  /**
   * Builds the ExportTrigger under test.
   *
   * Injects a MockHandler-backed SlackClientFactory subclass, real queue,
   * real ExportStateService, real SlackFetcher, real SlackWorkspaceMapper,
   * real CanonicalJsonWriter, and a Settings-backed SlackTokenProvider.
   *
   * @param \GuzzleHttp\Handler\MockHandler $mockHandler
   *   The MockHandler to inject into the test factory subclass.
   *
   * @return \Drupal\slack_portal\Service\ExportTrigger
   *   The configured ExportTrigger instance.
   */
  private function buildTrigger(MockHandler $mockHandler): ExportTrigger {
    // Non-final subclass: overrides create() to inject our MockHandler.
    $factory = new class() extends SlackClientFactory {

      /** @var \GuzzleHttp\Handler\MockHandler|null */
      public ?MockHandler $h = NULL;

      /**
       * {@inheritdoc}
       */
      public function create(string $token): SlackApiClient {
        return $this->createWithHandler($this->h, $token);
      }

    };
    $factory->h = $mockHandler;

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);

    $tokenProvider = new SlackTokenProvider(
      new Settings(['slack_user_token' => self::TEST_TOKEN]),
      $state,
      $this->createMock(EncryptServiceInterface::class),
      $this->createMock(EncryptionProfileManagerInterface::class),
    );

    $fetcher = new SlackFetcher(new CursorIterator(), new NullLogger());
    $mapper = new SlackWorkspaceMapper();

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $writer = new CanonicalJsonWriter($fileSystem, new NullLogger());

    /** @var \Drupal\Core\Queue\QueueFactory $queueFactory */
    $queueFactory = $this->container->get('queue');

    $stateService = new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );

    return new ExportTrigger(
      $tokenProvider,
      $factory,
      $fetcher,
      $mapper,
      $writer,
      $queueFactory,
      $stateService,
      $this->container->get('datetime.time'),
      new NullLogger(),
    );
  }

  /**
   * Tests trigger() counts, writes users.json, fills queue, sets running.
   *
   * Given:
   *   - users.list returns 1 user (U1).
   *   - conversations.list returns C1 (public) and D1 (im).
   *   - A real queue factory and ExportStateService backed by real State.
   *
   * When ExportTrigger::trigger() is called,
   *
   * Then:
   *   1. Returns ['queued'=>2, 'users'=>1].
   *   2. public://slack_archive/latest/users.json exists with 1 user.
   *   3. Queue 'slack_portal_fetch' has 2 items.
   *   4. ExportStateService::getStatus() returns status='running', total=2,
   *      users=1.
   */
  public function testTriggerEnqueuesChannelsAndSetsRunningState(): void {
    // Arrange.
    $mock = $this->buildMockHandler();
    $trigger = $this->buildTrigger($mock);

    // Act.
    $result = $trigger->trigger();

    // Assert 1: return value.
    $this->assertSame(['queued' => 2, 'users' => 1], $result);

    // Assert 2: users.json written.
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $usersUri = 'public://slack_archive/latest/users.json';
    $usersPath = $fileSystem->realpath($usersUri);
    $this->assertNotFalse($usersPath, 'users.json URI must resolve to a real path.');
    $this->assertFileExists((string) $usersPath, 'users.json must exist on disk.');
    $users = json_decode((string) file_get_contents((string) $usersPath), TRUE);
    $this->assertIsArray($users);
    $this->assertCount(1, $users, 'users.json must contain exactly 1 user.');
    $this->assertSame('U1', $users[0]['id']);

    // Assert 3: queue has 2 items.
    /** @var \Drupal\Core\Queue\QueueFactory $queueFactory */
    $queueFactory = $this->container->get('queue');
    $queue = $queueFactory->get('slack_portal_fetch');
    $this->assertSame(2, $queue->numberOfItems(), 'Queue must have exactly 2 items.');

    // Assert 4: state is 'running' with correct totals.
    $stateService = new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
    $status = $stateService->getStatus();
    $this->assertSame('running', $status['status']);
    $this->assertSame(2, $status['total']);
    $this->assertSame(1, $status['users']);
  }

}
