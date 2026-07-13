<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use DateTimeImmutable;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;
use Workerman\Timer;

/**
 * Webhook event service with dispatch queue and retry support.
 *
 * Provides:
 * - Event emission and persistence
 * - Subscription matching for events
 * - Async delivery with exponential backoff retry (30s, 300s, 1800s)
 *
 * @autowire
 */
class WebhookService
{
    /** @var StructuredLogger Logger instance */
    private StructuredLogger $logger;

    /**
     * @param Connection $db Database connection
     * @param WebhookHttpClient $httpClient HTTP client for deliveries
     */
    public function __construct(
        private readonly Connection $db,
        private readonly WebhookHttpClient $httpClient,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::APPLICATION);
    }

    /**
     * Emit a webhook event.
     *
     * Saves the event to the database and queues deliveries to all matching
     * subscriptions. Delivery is attempted asynchronously via Workerman Timer.
     *
     * @param string $eventType Event type (e.g., media.scanned, transcode.completed)
     * @param array<string, mixed> $payload Event payload data
     */
    public function emit(string $eventType, array $payload): void
    {
        $eventId = $this->insertEvent($eventType, $payload);
        $this->queueDeliveries($eventId, $eventType, $payload);

        $this->logger->debug('Webhook event emitted', [
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);
    }

    /**
     * Get active subscriptions that handle a specific event type.
     *
     * @param string $eventType Event type to match
     *
     * @return list<WebhookSubscriptionRecord> Matching subscriptions
     */
    public function getSubscriptionsForEvent(string $eventType): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, url, events, is_active, created_at, updated_at " .
            "FROM webhook_subscriptions WHERE is_active = TRUE"
        );

        $subscriptions = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $subscription = WebhookSubscriptionRecord::fromRow($row);
            if ($subscription->handlesEvent($eventType)) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * Dispatch an event to all matching subscriptions.
     *
     * This method performs the actual HTTP delivery and handles the response.
     * It updates the delivery record with the result and schedules retries if needed.
     *
     * @param WebhookEventRecord $event The event to dispatch
     */
    public function dispatchEvent(WebhookEventRecord $event): void
    {
        $subscriptions = $this->getSubscriptionsForEvent($event->eventType);

        foreach ($subscriptions as $subscription) {
            $this->dispatchToSubscription($event, $subscription);
        }
    }

    /**
     * Insert a new webhook event into the database.
     *
     * @param string $eventType Event type
     * @param array<string, mixed> $payload Event payload
     *
     * @return int Inserted event ID
     */
    private function insertEvent(string $eventType, array $payload): int
    {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->db->query(
            "INSERT INTO webhook_events (event_type, payload, created_at) VALUES (?, ?, NOW(6))",
            [$eventType, $payloadJson]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Queue deliveries for all matching subscriptions.
     *
     * Creates delivery records for each matching subscription and schedules
     * async dispatch via Workerman Timer.
     *
     * @param int $eventId Event ID
     * @param string $eventType Event type
     * @param array<string, mixed> $payload Event payload
     */
    private function queueDeliveries(int $eventId, string $eventType, array $payload): void
    {
        $subscriptions = $this->getSubscriptionsForEvent($eventType);

        foreach ($subscriptions as $subscription) {
            $deliveryId = $this->insertDelivery($eventId, $subscription->url);

            // Schedule async dispatch
            Timer::add(0, function () use ($eventId, $eventType, $payload, $deliveryId): void {
                $this->processDelivery($eventId, $eventType, $payload, $deliveryId);
            }, [], false);
        }
    }

    /**
     * Insert a delivery record.
     *
     * @param int $eventId Event ID
     * @param string $webhookUrl Target URL
     *
     * @return int Inserted delivery ID
     */
    private function insertDelivery(int $eventId, string $webhookUrl): int
    {
        $this->db->query(
            "INSERT INTO webhook_deliveries (event_id, webhook_url, attempt, status, created_at) " .
            "VALUES (?, ?, 1, 'pending', NOW(6))",
            [$eventId, $webhookUrl]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Dispatch delivery to a specific subscription.
     *
     * @param WebhookEventRecord $event Event to deliver
     * @param WebhookSubscriptionRecord $subscription Target subscription
     */
    private function dispatchToSubscription(WebhookEventRecord $event, WebhookSubscriptionRecord $subscription): void
    {
        $deliveryId = $this->insertDelivery($event->id, $subscription->url);
        $this->processDelivery($event->id, $event->eventType, $event->payload, $deliveryId);
    }

    /**
     * Process a single delivery attempt.
     *
     * Makes the HTTP request and updates the delivery record based on the result.
     * Schedules retry if the delivery failed and retries remain.
     *
     * @param int $eventId Event ID
     * @param string $eventType Event type
     * @param array<string, mixed> $payload Event payload
     * @param int $deliveryId Delivery ID
     */
    private function processDelivery(int $eventId, string $eventType, array $payload, int $deliveryId): void
    {
        $delivery = $this->getDelivery($deliveryId);
        if ($delivery === null) {
            return;
        }

        if ($delivery->status !== WebhookDeliveryRecord::STATUS_PENDING) {
            return;
        }

        $httpResponse = $this->httpClient->post(
            $delivery->webhookUrl,
            $eventType,
            (string) $deliveryId,
            [
                'event_type' => $eventType,
                'payload' => $payload,
                'event_id' => $eventId,
                'delivery_id' => $deliveryId,
                'timestamp' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]
        );

        if ($httpResponse['success']) {
            $this->markDelivered($deliveryId, $httpResponse['response_code'], $httpResponse['response_body']);
        } else {
            $this->handleFailedDelivery($delivery, $httpResponse);
        }
    }

    /**
     * Get a delivery record by ID.
     */
    private function getDelivery(int $deliveryId): ?WebhookDeliveryRecord
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, event_id, webhook_url, attempt, status, response_code, " .
            "response_body, next_retry_at, created_at, delivered_at " .
            "FROM webhook_deliveries WHERE id = ?",
            [$deliveryId]
        );

        if ($rows === []) {
            return null;
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];

        return WebhookDeliveryRecord::fromRow($firstRow);
    }

    /**
     * Mark a delivery as successfully delivered.
     *
     * @param int $deliveryId Delivery ID
     * @param int|null $responseCode HTTP response code
     * @param string|null $responseBody HTTP response body
     */
    private function markDelivered(int $deliveryId, ?int $responseCode, ?string $responseBody): void
    {
        $this->db->query(
            "UPDATE webhook_deliveries SET status = 'delivered', response_code = ?, " .
            "response_body = ?, delivered_at = NOW(6) WHERE id = ?",
            [$responseCode, $responseBody, $deliveryId]
        );

        $this->logger->debug('Webhook delivered', [
            'delivery_id' => $deliveryId,
            'response_code' => $responseCode,
        ]);
    }

    /**
     * Handle a failed delivery attempt.
     *
     * If retries remain, schedules the next retry. Otherwise marks as failed.
     *
     * @param WebhookDeliveryRecord $delivery Delivery record
     * @param array<string, mixed> $httpResponse HTTP response data
     */
    private function handleFailedDelivery(WebhookDeliveryRecord $delivery, array $httpResponse): void
    {
        $nextAttempt = $delivery->attempt + 1;

        if ($delivery->canRetry()) {
            // SV-4.4 / S-F10: compute the jittered delay ONCE and thread it
            // through both the persisted `next_retry_at` timestamp and the
            // retry Timer below, so they can never drift apart (each call to
            // calculateNextRetryDelaySeconds()/calculateNextRetryAt() draws a
            // fresh random jitter).
            $delaySeconds = $delivery->calculateNextRetryDelaySeconds();
            $nextRetryAt = $delaySeconds !== null
                ? $delivery->calculateNextRetryAt(null, $delaySeconds)
                : null;

            $this->db->query(
                "UPDATE webhook_deliveries SET attempt = ?, response_code = ?, response_body = ?, " .
                "next_retry_at = ? WHERE id = ?",
                [
                    $nextAttempt,
                    $httpResponse['response_code'],
                    $httpResponse['response_body'],
                    $nextRetryAt?->format('Y-m-d H:i:s.u'),
                    $delivery->id,
                ]
            );

            // Schedule retry via a ONE-SHOT timer (the literal `false` 4th arg
            // is what makes it one-shot — Workerman timers repeat by default).
            if ($delaySeconds !== null) {
                Timer::add((float) $delaySeconds, function () use ($delivery): void {
                    $this->retryDelivery($delivery->id);
                }, [], false);
            }

            $this->logger->debug('Webhook delivery failed, scheduled retry', [
                'delivery_id' => $delivery->id,
                'attempt' => $delivery->attempt,
                'next_retry_at' => $nextRetryAt?->format(DateTimeImmutable::ATOM),
            ]);
        } else {
            $this->db->query(
                "UPDATE webhook_deliveries SET status = 'failed', response_code = ?, " .
                "response_body = ? WHERE id = ?",
                [
                    $httpResponse['response_code'],
                    $httpResponse['response_body'],
                    $delivery->id,
                ]
            );

            $this->logger->warning('Webhook delivery permanently failed', [
                'delivery_id' => $delivery->id,
                'response_code' => $httpResponse['response_code'],
                'error' => $httpResponse['error'],
            ]);
        }
    }

    /**
     * Retry a failed delivery.
     *
     * @param int $deliveryId Delivery ID to retry
     */
    private function retryDelivery(int $deliveryId): void
    {
        $delivery = $this->getDelivery($deliveryId);
        if ($delivery === null) {
            return;
        }

        // Get the event for this delivery
        /** @var array<array<string, mixed>> $eventRows */
        $eventRows = $this->db->query(
            "SELECT id, event_type, payload, created_at FROM webhook_events WHERE id = ?",
            [$delivery->eventId]
        );

        if ($eventRows === []) {
            $this->logger->error('Cannot retry delivery: event not found', [
                'delivery_id' => $deliveryId,
                'event_id' => $delivery->eventId,
            ]);
            return;
        }

        /** @var array<string, mixed> $firstEventRow */
        $firstEventRow = $eventRows[0];
        $event = WebhookEventRecord::fromRow($firstEventRow);

        // Check if already delivered or max retries exceeded
        if ($delivery->status !== WebhookDeliveryRecord::STATUS_PENDING) {
            return;
        }

        $httpResponse = $this->httpClient->post(
            $delivery->webhookUrl,
            $event->eventType,
            (string) $deliveryId,
            [
                'event_type' => $event->eventType,
                'payload' => $event->payload,
                'event_id' => $event->id,
                'delivery_id' => $deliveryId,
                'timestamp' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            ]
        );

        if ($httpResponse['success']) {
            $this->markDelivered($deliveryId, $httpResponse['response_code'], $httpResponse['response_body']);
        } else {
            // Re-fetch delivery to get updated attempt count
            $updatedDelivery = $this->getDelivery($deliveryId);
            if ($updatedDelivery !== null) {
                $this->handleFailedDelivery($updatedDelivery, $httpResponse);
            }
        }
    }

    /**
     * Create a new webhook subscription.
     *
     * @param string $url Webhook endpoint URL
     * @param array<string> $events Array of subscribed event types
     *
     * @return int New subscription ID
     */
    public function createSubscription(string $url, array $events): int
    {
        $eventsJson = json_encode($events, JSON_THROW_ON_ERROR);

        $this->db->query(
            "INSERT INTO webhook_subscriptions (url, events, is_active, created_at, updated_at) " .
            "VALUES (?, ?, TRUE, NOW(), NOW())",
            [$url, $eventsJson]
        );

        $this->logger->info('Webhook subscription created', [
            'url' => $url,
            'events' => $events,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete a webhook subscription.
     *
     * @param int $subscriptionId Subscription ID
     *
     * @return bool True if deleted, false if not found
     */
    public function deleteSubscription(int $subscriptionId): bool
    {
        $this->db->query(
            "DELETE FROM webhook_subscriptions WHERE id = ?",
            [$subscriptionId]
        );

        $this->logger->info('Webhook subscription deleted', [
            'subscription_id' => $subscriptionId,
        ]);

        return true;
    }

    /**
     * List all webhook subscriptions.
     *
     * @return list<WebhookSubscriptionRecord> All subscriptions
     */
    public function listSubscriptions(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, url, events, is_active, created_at, updated_at " .
            "FROM webhook_subscriptions ORDER BY created_at DESC"
        );

        $subscriptions = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $subscriptions[] = WebhookSubscriptionRecord::fromRow($row);
        }

        return $subscriptions;
    }

    /**
     * Get deliveries for a specific event.
     *
     * @param int $eventId Event ID
     *
     * @return list<WebhookDeliveryRecord> Delivery records
     */
    public function getDeliveriesForEvent(int $eventId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, event_id, webhook_url, attempt, status, response_code, " .
            "response_body, next_retry_at, created_at, delivered_at " .
            "FROM webhook_deliveries WHERE event_id = ? ORDER BY created_at",
            [$eventId]
        );

        $deliveries = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $deliveries[] = WebhookDeliveryRecord::fromRow($row);
        }

        return $deliveries;
    }

    /**
     * Get pending deliveries that are due for retry.
     *
     * @return list<WebhookDeliveryRecord> Deliveries due for retry
     */
    public function getPendingRetries(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, event_id, webhook_url, attempt, status, response_code, " .
            "response_body, next_retry_at, created_at, delivered_at " .
            "FROM webhook_deliveries " .
            "WHERE status = 'pending' AND next_retry_at IS NOT NULL AND next_retry_at <= NOW(6)"
        );

        $deliveries = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $deliveries[] = WebhookDeliveryRecord::fromRow($row);
        }

        return $deliveries;
    }
}
