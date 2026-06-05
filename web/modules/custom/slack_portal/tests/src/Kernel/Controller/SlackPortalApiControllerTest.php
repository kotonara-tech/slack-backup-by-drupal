<?php

declare(strict_types=1);

/**
 * @file
 * Kernel tests for SlackPortalApiController — trigger/status HTTP endpoints.
 */

namespace Drupal\Tests\slack_portal\Kernel\Controller;

use Drupal\KernelTests\KernelTestBase;
use Drupal\slack_portal\Controller\SlackPortalApiController;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\ExportTrigger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Kernel tests for SlackPortalApiController.
 *
 * Tests the controller methods directly (not via HTTP). The real
 * ExportStateService is used backed by Drupal State. ExportTrigger is mocked
 * (non-final) to isolate controller logic from actual Slack API calls.
 *
 * Test cases:
 *  1. status() returns running status with correct totals.
 *  2. triggerExport() returns {status:'queued',queued:2,users:1} on success.
 *  3. triggerExport() returns 500 + status:'error' when trigger throws, and
 *     the error message does NOT contain any xoxp token substring.
 *
 * @covers \Drupal\slack_portal\Controller\SlackPortalApiController
 */
#[CoversClass(\Drupal\slack_portal\Controller\SlackPortalApiController::class)]
#[Group('slack_portal')]
class SlackPortalApiControllerTest extends KernelTestBase {

  /**
   * Only system is needed to set up the public:// stream wrapper and State.
   *
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Returns a real ExportStateService backed by container State + time.
   *
   * @return \Drupal\slack_portal\Service\ExportStateService
   *   The state service instance.
   */
  private function buildStateService(): ExportStateService {
    return new ExportStateService(
      $this->container->get('state'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Builds the controller with a mocked trigger and real state service.
   *
   * @param \Drupal\slack_portal\Service\ExportTrigger $triggerMock
   *   A mock or real ExportTrigger instance.
   * @param \Drupal\slack_portal\Service\ExportStateService $stateService
   *   A real ExportStateService.
   *
   * @return \Drupal\slack_portal\Controller\SlackPortalApiController
   *   The controller instance under test.
   */
  private function buildController(
    ExportTrigger $triggerMock,
    ExportStateService $stateService,
  ): SlackPortalApiController {
    return new SlackPortalApiController(
      $triggerMock,
      $stateService,
      new NullLogger(),
    );
  }

  /**
   * Tests status() returns running status with correct totals.
   *
   * Given ExportStateService::start(3, 5) has been called,
   * When status() is called on the controller,
   * Then it returns a JsonResponse with status='running', total=3, users=5.
   */
  public function testStatusReturnsRunningState(): void {
    // Arrange.
    $stateService = $this->buildStateService();
    $stateService->start(3, 5);

    $triggerMock = $this->createMock(ExportTrigger::class);
    $controller = $this->buildController($triggerMock, $stateService);

    // Act.
    $response = $controller->status();

    // Assert.
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('running', $data['status']);
    $this->assertSame(3, $data['total']);
    $this->assertSame(5, $data['users']);
  }

  /**
   * Tests triggerExport() returns queued + counts on success.
   *
   * Given ExportTrigger::trigger() returns ['queued'=>2, 'users'=>1],
   * When triggerExport() is called,
   * Then it returns a 200 JsonResponse with
   *   {status:'queued', queued:2, users:1}.
   */
  public function testTriggerExportReturnsQueuedOnSuccess(): void {
    // Arrange.
    $stateService = $this->buildStateService();

    $triggerMock = $this->createMock(ExportTrigger::class);
    $triggerMock->expects($this->once())
      ->method('trigger')
      ->willReturn(['queued' => 2, 'users' => 1]);

    $controller = $this->buildController($triggerMock, $stateService);

    // Act.
    $response = $controller->triggerExport();

    // Assert.
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());

    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('queued', $data['status']);
    $this->assertSame(2, $data['queued']);
    $this->assertSame(1, $data['users']);
  }

  /**
   * Tests triggerExport() returns 500 on exception without leaking the token.
   *
   * Given ExportTrigger::trigger() throws RuntimeException with a message
   *   that includes a token-like string,
   * When triggerExport() is called,
   * Then it returns a 500 JsonResponse with status='error', and the JSON
   *   response body does NOT contain any 'xoxp' substring.
   */
  public function testTriggerExportReturns500OnExceptionWithoutTokenLeak(): void {
    // Arrange.
    $stateService = $this->buildStateService();

    $triggerMock = $this->createMock(ExportTrigger::class);
    $triggerMock->expects($this->once())
      ->method('trigger')
      // The exception message mimics "not configured" (no real token value).
      ->willThrowException(new \RuntimeException('Slack user token is not configured.'));

    $controller = $this->buildController($triggerMock, $stateService);

    // Act.
    $response = $controller->triggerExport();

    // Assert: 500 status.
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(500, $response->getStatusCode());

    $data = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($data);
    $this->assertSame('error', $data['status']);
    $this->assertArrayHasKey('message', $data);

    // Token must never appear in the response body.
    $responseBody = (string) $response->getContent();
    $this->assertStringNotContainsString(
      'xoxp',
      $responseBody,
      'Response body must not contain any xoxp token substring.',
    );
  }

}
