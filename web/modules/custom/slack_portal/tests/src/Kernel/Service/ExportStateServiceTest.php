<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for ExportStateService — export status state model.
 */

namespace Drupal\Tests\slack_portal\Kernel\Service;

use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\Service\ExportStateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for ExportStateService.
 *
 * Exercises the idle/queued/running/done/error state model backed by Drupal
 * State API. Uses real @state and @datetime.time services (only system module).
 *
 * @covers \Drupal\slack_portal\Service\ExportStateService
 */
#[CoversClass(\Drupal\slack_portal\Service\ExportStateService::class)]
#[Group('slack_portal')]
class ExportStateServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * The service under test.
   *
   * @var \Drupal\slack_portal\Service\ExportStateService
   */
  private ExportStateService $stateService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stateService = new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Tests that start() transitions status to running with correct fields.
   *
   * Given the export state is unset,
   * When start(2, 5) is called,
   * Then getStatus() returns status='running', total=2, users=5,
   * processed=0, messages=0, channels=[], started_at is set,
   * finished_at=null, last_error=null.
   */
  public function testStartSetsRunningStatus(): void {
    // Act.
    $this->stateService->start(2, 5);

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame('running', $status['status']);
    $this->assertSame(2, $status['total']);
    $this->assertSame(5, $status['users']);
    $this->assertSame(0, $status['processed']);
    $this->assertSame(0, $status['messages']);
    $this->assertSame([], $status['channels']);
    $this->assertNotNull($status['started_at'], 'started_at must be set after start().');
    $this->assertNull($status['finished_at']);
    $this->assertNull($status['last_error']);
  }

  /**
   * Tests that recordChannel() increments counters and appends channel entry.
   *
   * Given start(2, 5) has been called,
   * When recordChannel(['id'=>'C1','name'=>'general','type'=>'public_channel',
   *   'file'=>'channels/C1.json'], 3) is called,
   * Then processed==1, messages==3, channels has exactly 1 entry,
   * And isComplete() returns false (1 < 2 total).
   */
  public function testRecordChannelIncrementsCounters(): void {
    // Arrange.
    $this->stateService->start(2, 5);

    // Act.
    $indexEntry = [
      'id' => 'C1',
      'name' => 'general',
      'type' => 'public_channel',
      'file' => 'channels/C1.json',
    ];
    $this->stateService->recordChannel($indexEntry, 3);

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame(1, $status['processed']);
    $this->assertSame(3, $status['messages']);
    $this->assertCount(1, $status['channels']);
    $this->assertSame($indexEntry, $status['channels'][0]);
    $this->assertFalse($this->stateService->isComplete());
  }

  /**
   * Tests that recording all channels causes isComplete() to return true.
   *
   * Given start(2, 5) has been called and one channel has been recorded,
   * When recordChannel is called for the second channel,
   * Then processed==2, messages==7 (3+4), isComplete() returns true.
   */
  public function testIsCompleteAfterAllChannelsRecorded(): void {
    // Arrange.
    $this->stateService->start(2, 5);
    $this->stateService->recordChannel(
      ['id' => 'C1', 'name' => 'general', 'type' => 'public_channel', 'file' => 'channels/C1.json'],
      3,
    );

    // Act.
    $this->stateService->recordChannel(
      ['id' => 'C2', 'name' => 'random', 'type' => 'public_channel', 'file' => 'channels/C2.json'],
      4,
    );

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame(2, $status['processed']);
    $this->assertSame(7, $status['messages']);
    $this->assertTrue($this->stateService->isComplete());
  }

  /**
   * Tests that finish() sets status to 'done' and sets finished_at.
   *
   * Given start(2, 5) and two recordChannel() calls (isComplete=true),
   * When finish() is called,
   * Then status=='done' and finished_at is a non-null integer.
   */
  public function testFinishSetsDoneStatus(): void {
    // Arrange.
    $this->stateService->start(2, 5);
    $this->stateService->recordChannel(
      ['id' => 'C1', 'name' => 'g', 'type' => 'public_channel', 'file' => 'channels/C1.json'],
      1,
    );
    $this->stateService->recordChannel(
      ['id' => 'C2', 'name' => 'r', 'type' => 'public_channel', 'file' => 'channels/C2.json'],
      2,
    );

    // Act.
    $this->stateService->finish();

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame('done', $status['status']);
    $this->assertNotNull($status['finished_at'], 'finished_at must be set after finish().');
    $this->assertIsInt($status['finished_at']);
  }

  /**
   * Tests that fail() sets status to 'error' and stores the masked error.
   *
   * Given start() has been called,
   * When fail('channel C1 export failed') is called,
   * Then status=='error' and last_error=='channel C1 export failed'.
   */
  public function testFailSetsErrorStatus(): void {
    // Arrange.
    $this->stateService->start(1, 0);

    // Act.
    $this->stateService->fail('channel C1 export failed');

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame('error', $status['status']);
    $this->assertSame('channel C1 export failed', $status['last_error']);
  }

  /**
   * Tests that reset() returns state to the idle shape.
   *
   * Given start() has been called and fail() was called,
   * When reset() is called,
   * Then getStatus() returns the full idle shape with all zero/null fields
   * and status='idle'.
   */
  public function testResetReturnsIdleShape(): void {
    // Arrange — get into a non-idle state.
    $this->stateService->start(1, 2);
    $this->stateService->fail('boom');

    // Act.
    $this->stateService->reset();

    // Assert.
    $status = $this->stateService->getStatus();
    $this->assertSame('idle', $status['status']);
    $this->assertSame(0, $status['total']);
    $this->assertSame(0, $status['processed']);
    $this->assertSame(0, $status['messages']);
    $this->assertSame(0, $status['users']);
    $this->assertSame([], $status['channels']);
    $this->assertNull($status['started_at']);
    $this->assertNull($status['finished_at']);
    $this->assertNull($status['last_error']);
  }

  /**
   * Tests that getStatus() returns the idle shape when state is unset.
   *
   * Given no state has been stored,
   * When getStatus() is called,
   * Then it returns the full idle shape (defaults, no exception).
   */
  public function testGetStatusDefaultsToIdleWhenUnset(): void {
    // Act — no start() called.
    $status = $this->stateService->getStatus();

    // Assert.
    $this->assertSame('idle', $status['status']);
    $this->assertSame(0, $status['total']);
    $this->assertSame(0, $status['processed']);
    $this->assertSame(0, $status['messages']);
    $this->assertSame(0, $status['users']);
    $this->assertSame([], $status['channels']);
    $this->assertNull($status['started_at']);
    $this->assertNull($status['finished_at']);
    $this->assertNull($status['last_error']);
  }

}
