<?php

declare(strict_types=1);

/**
 * @file
 * HTTP API controller for the Slack Portal trigger and status endpoints.
 */

namespace Drupal\slack_portal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\slack_portal\Service\ExportStateService;
use Drupal\slack_portal\Service\ExportTrigger;
use Drupal\slack_portal\Service\SecretMasker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Exposes the Slack export trigger and status as HTTP JSON endpoints.
 *
 * Routes:
 *  POST /api/slack-portal/export — triggers a queued Slack workspace export.
 *  GET  /api/slack-portal/status — returns the current export status.
 *
 * Both routes require the 'administer slack_portal' permission. The trigger
 * route additionally requires a valid CSRF token header.
 *
 * SECRETS RULE: The Slack token must never appear in any HTTP response body,
 * header, or log line. Exception messages from ExportTrigger are sanitised
 * before being returned to the client — only 'Slack user token is not
 * configured.' and similar generic messages are safe to forward.
 *
 * @see \Drupal\slack_portal\Service\ExportTrigger
 * @see \Drupal\slack_portal\Service\ExportStateService
 */
final class SlackPortalApiController extends ControllerBase {

  /**
   * Constructs a SlackPortalApiController.
   *
   * @param \Drupal\slack_portal\Service\ExportTrigger $exportTrigger
   *   The export trigger service.
   * @param \Drupal\slack_portal\Service\ExportStateService $exportState
   *   The export state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A PSR-3 logger (logger.channel.slack_portal).
   */
  public function __construct(
    private readonly ExportTrigger $exportTrigger,
    private readonly ExportStateService $exportState,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('slack_portal.export_trigger'),
      $container->get('slack_portal.export_state'),
      $container->get('logger.channel.slack_portal'),
    );
  }

  /**
   * Triggers a queued Slack workspace export (POST /api/slack-portal/export).
   *
   * On success returns HTTP 200 with JSON:
   * @code
   * {"status":"queued","queued":<int>,"users":<int>}
   * @endcode
   *
   * On error returns HTTP 500 with JSON:
   * @code
   * {"status":"error","message":"<sanitised message>"}
   * @endcode
   *
   * The message field never contains a Slack token value.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the trigger result or an error description.
   */
  public function triggerExport(): JsonResponse {
    try {
      $result = $this->exportTrigger->trigger();
      return new JsonResponse(['status' => 'queued'] + $result);
    }
    catch (\Throwable $e) {
      // Sanitise: strip any Slack token-like substring before logging or
      // returning the message (shared redaction — see SecretMasker).
      $safeMessage = SecretMasker::mask($e->getMessage());

      $this->logger->error(
        'Slack export trigger failed: {message}',
        // Log the sanitised message — never the raw exception if it may
        // contain a token value (e.g. from a misconfigured exception path).
        ['message' => $safeMessage],
      );

      return new JsonResponse(
        ['status' => 'error', 'message' => $safeMessage],
        500,
      );
    }
  }

  /**
   * Returns the current Slack export status (GET /api/slack-portal/status).
   *
   * Returns HTTP 200 with the full status array from ExportStateService.
   * The response never contains a Slack token value.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the current export status array.
   */
  public function status(): JsonResponse {
    return new JsonResponse($this->exportState->getStatus());
  }

}
