<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Common\Net\SsrfGuard;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Webhooks\WebhookService;
use InvalidArgumentException;
use Throwable;

/**
 * Admin API controller for webhook subscription and delivery management.
 *
 * Endpoints:
 * - GET    /api/v1/admin/webhooks/subscriptions       — list all subscriptions
 * - POST   /api/v1/admin/webhooks/subscriptions       — create subscription
 * - DELETE /api/v1/admin/webhooks/subscriptions/{id}  — delete subscription
 * - GET    /api/v1/admin/webhooks/deliveries           — get deliveries (filter by event_id)
 */
final class AdminWebhooksController
{
    /** Valid webhook event types. */
    private const VALID_EVENT_TYPES = [
        'media.scanned',
        'media.updated',
        'media.deleted',
        'transcode.started',
        'transcode.completed',
        'transcode.failed',
        'user.watched',
        'syncplay.room_created',
        'syncplay.user_joined',
    ];

    /**
     * @param WebhookService $webhookService Webhook service for operations
     */
    public function __construct(
        private readonly WebhookService $webhookService,
    ) {
    }

    /**
     * List all webhook subscriptions.
     *
     * GET /api/v1/admin/webhooks/subscriptions
     *
     * @param Request              $request HTTP request
     * @param array<string, string> $params Path parameters (unused)
     *
     * @return Response JSON response with subscriptions list
     */
    public function listSubscriptions(Request $request, array $params): Response
    {
        try {
            $subscriptions = $this->webhookService->listSubscriptions();

            return (new Response())->json([
                'success' => true,
                'data' => [
                    'subscriptions' => array_map(
                        fn($sub) => $sub->toArray(),
                        $subscriptions
                    ),
                    'total' => count($subscriptions),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to list subscriptions', $e);
        }
    }

    /**
     * Create a new webhook subscription.
     *
     * POST /api/v1/admin/webhooks/subscriptions
     * Body: { "url": string, "events": string[] }
     *
     * @param Request              $request HTTP request
     * @param array<string, string> $params Path parameters (unused)
     *
     * @return Response JSON response with created subscription or error
     */
    public function createSubscription(Request $request, array $params): Response
    {
        try {
            $body = $request->body;

            $url = $this->validateUrl($body['url'] ?? null);
            if ($url === null) {
                return $this->validationError('Invalid or missing "url" field');
            }

            $events = $this->validateEvents($body['events'] ?? null);
            if ($events === null) {
                return $this->validationError(
                    'Invalid or missing "events" field. Must be an array of valid event types.'
                );
            }

            $subscriptionId = $this->webhookService->createSubscription($url, $events);

            $subscriptions = $this->webhookService->listSubscriptions();
            $created = null;
            foreach ($subscriptions as $sub) {
                if ($sub->id === $subscriptionId) {
                    $created = $sub;
                    break;
                }
            }

            return (new Response())->status(201)->json([
                'success' => true,
                'data' => [
                    'subscription' => $created?->toArray(),
                ],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to create subscription', $e);
        }
    }

    /**
     * Delete a webhook subscription.
     *
     * DELETE /api/v1/admin/webhooks/subscriptions/{id}
     *
     * @param Request              $request HTTP request
     * @param array<string, string> $params Path parameters including 'id'
     *
     * @return Response Empty 204 on success, or error response
     */
    public function deleteSubscription(Request $request, array $params): Response
    {
        try {
            $id = $this->validateId($params['id'] ?? null);
            if ($id === null) {
                return $this->validationError('Invalid or missing subscription ID');
            }

            $this->webhookService->deleteSubscription($id);

            return (new Response())->status(204);
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to delete subscription', $e);
        }
    }

    /**
     * Get webhook deliveries, optionally filtered by event ID.
     *
     * GET /api/v1/admin/webhooks/deliveries?event_id=X
     *
     * @param Request              $request HTTP request
     * @param array<string, string> $params Path parameters (unused)
     *
     * @return Response JSON response with deliveries list
     */
    public function getDeliveries(Request $request, array $params): Response
    {
        try {
            $eventId = $this->validateOptionalId($request->query['event_id'] ?? null);

            if ($eventId !== null) {
                $deliveries = $this->webhookService->getDeliveriesForEvent($eventId);
            } else {
                // If no event_id provided, return pending retries
                $deliveries = $this->webhookService->getPendingRetries();
            }

            return (new Response())->json([
                'success' => true,
                'data' => [
                    'deliveries' => array_map(
                        fn($delivery) => $delivery->toArray(),
                        $deliveries
                    ),
                    'total' => count($deliveries),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse('Failed to get deliveries', $e);
        }
    }

    /**
     * Validate and return a valid URL or null.
     *
     * @param mixed $url Raw URL value
     *
     * @return string|null Valid URL or null if invalid
     */
    private function validateUrl(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // SSRF guard: reject loopback/link-local/private/metadata targets
        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $url;
    }

    /**
     * Validate and return an array of valid event types or null.
     *
     * @param mixed $events Raw events value
     *
     * @return array<string>|null Array of valid event types or null if invalid
     */
    private function validateEvents(mixed $events): ?array
    {
        if (!is_array($events) || $events === []) {
            return null;
        }

        $validEvents = [];
        foreach ($events as $event) {
            if (!is_string($event)) {
                return null;
            }
            if (!in_array($event, self::VALID_EVENT_TYPES, true)) {
                return null;
            }
            $validEvents[] = $event;
        }

        return $validEvents;
    }

    /**
     * Validate and return a positive integer ID or null.
     *
     * @param mixed $id Raw ID value
     *
     * @return int|null Positive integer ID or null if invalid
     */
    private function validateId(mixed $id): ?int
    {
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        $intId = is_int($id) ? $id : (int) $id;
        if ($intId <= 0) {
            return null;
        }

        return $intId;
    }

    /**
     * Validate and return a positive integer ID or null (for optional fields).
     *
     * @param mixed $id Raw ID value
     *
     * @return int|null Positive integer ID, null for empty/missing, or null if invalid non-empty
     */
    private function validateOptionalId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->validateId($id);
    }

    /**
     * Create a validation error response.
     *
     * @param string $message Error message
     *
     * @return Response 400 error response
     */
    private function validationError(string $message): Response
    {
        return (new Response())->status(400)->json([
            'success' => false,
            'error' => 'Validation failed',
            'message' => $message,
        ]);
    }

    /**
     * Create an error response with exception details.
     *
     * @param string   $context Context description
     * @param Throwable $e       Exception
     *
     * @return Response 500 error response
     */
    private function errorResponse(string $context, Throwable $e): Response
    {
        return (new Response())->status(500)->json([
            'success' => false,
            'error' => $context,
            'message' => $e->getMessage(),
        ]);
    }
}
