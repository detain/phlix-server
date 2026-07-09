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
use JsonSerializable;

/**
 * Database-backed webhook delivery record.
 *
 * Tracks individual delivery attempts for each webhook subscription,
 * including retry scheduling with exponential backoff.
 *
 * @property-read int $id Delivery record ID
 * @property-read int $eventId Associated event ID
 * @property-read string $webhookUrl Target webhook URL
 * @property-read int $attempt Current attempt number (1-3)
 * @property-read string $status Delivery status (pending|delivered|failed)
 * @property-read int|null $responseCode HTTP response code
 * @property-read string|null $responseBody HTTP response body
 * @property-read DateTimeImmutable|null $nextRetryAt Next retry timestamp
 * @property-read DateTimeImmutable $createdAt Record creation timestamp
 * @property-read DateTimeImmutable|null $deliveredAt Successful delivery timestamp
 */
final class WebhookDeliveryRecord implements JsonSerializable
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * Maximum number of delivery attempts.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Exponential backoff delays in seconds: 30s, 300s (5min), 1800s (30min).
     *
     * @var array<int, int>
     */
    public const RETRY_DELAYS = [
        1 => 30,      // First retry: 30 seconds
        2 => 300,     // Second retry: 5 minutes
        3 => 1800,    // Third retry: 30 minutes
    ];

    /**
     * @param int $id Delivery record ID
     * @param int $eventId Associated event ID
     * @param string $webhookUrl Target webhook URL
     * @param int $attempt Current attempt number (1-3)
     * @param string $status Delivery status
     * @param int|null $responseCode HTTP response code
     * @param string|null $responseBody HTTP response body
     * @param DateTimeImmutable|null $nextRetryAt Next retry timestamp
     * @param DateTimeImmutable $createdAt Record creation timestamp
     * @param DateTimeImmutable|null $deliveredAt Successful delivery timestamp
     */
    public function __construct(
        public readonly int $id,
        public readonly int $eventId,
        public readonly string $webhookUrl,
        public readonly int $attempt,
        public readonly string $status,
        public readonly ?int $responseCode,
        public readonly ?string $responseBody,
        public readonly ?DateTimeImmutable $nextRetryAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $deliveredAt,
    ) {
    }

    /**
     * Create from database row.
     *
     * @param array<string, mixed> $row Database row
     */
    public static function fromRow(array $row): self
    {
        $createdAt = $row['created_at'] ?? null;
        if (is_string($createdAt)) {
            $createdAt = new DateTimeImmutable($createdAt);
        } elseif (!$createdAt instanceof DateTimeImmutable) {
            $createdAt = new DateTimeImmutable();
        }

        $deliveredAt = $row['delivered_at'] ?? null;
        if (is_string($deliveredAt)) {
            $deliveredAt = new DateTimeImmutable($deliveredAt);
        } elseif (!is_null($deliveredAt) && !$deliveredAt instanceof DateTimeImmutable) {
            $deliveredAt = null;
        }

        $nextRetryAt = $row['next_retry_at'] ?? null;
        if (is_string($nextRetryAt)) {
            $nextRetryAt = new DateTimeImmutable($nextRetryAt);
        } elseif (!is_null($nextRetryAt) && !$nextRetryAt instanceof DateTimeImmutable) {
            $nextRetryAt = null;
        }

        return new self(
            id: is_int($row['id'] ?? null) ? (int) $row['id'] : 0,
            eventId: is_int($row['event_id'] ?? null) ? (int) $row['event_id'] : 0,
            webhookUrl: is_string($row['webhook_url'] ?? null) ? (string) $row['webhook_url'] : '',
            attempt: is_int($row['attempt'] ?? null) ? (int) $row['attempt'] : 1,
            status: is_string($row['status'] ?? null) ? (string) $row['status'] : self::STATUS_PENDING,
            responseCode: isset($row['response_code']) && is_int($row['response_code']) ? (int) $row['response_code'] : null,
            responseBody: is_string($row['response_body'] ?? null) ? (string) $row['response_body'] : null,
            nextRetryAt: $nextRetryAt,
            createdAt: $createdAt,
            deliveredAt: $deliveredAt,
        );
    }

    /**
     * Check if this delivery can be retried.
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->attempt < self::MAX_ATTEMPTS;
    }

    /**
     * Check if delivery is considered successful.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if delivery has permanently failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Calculate the next retry timestamp based on current attempt.
     *
     * @param DateTimeImmutable|null $fromTime Base time for calculation (defaults to now)
     */
    public function calculateNextRetryAt(?DateTimeImmutable $fromTime = null): ?DateTimeImmutable
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            return null;
        }

        $delaySeconds = self::RETRY_DELAYS[$this->attempt + 1] ?? self::RETRY_DELAYS[self::MAX_ATTEMPTS];
        $baseTime = $fromTime ?? new DateTimeImmutable();

        return $baseTime->modify("+{$delaySeconds} seconds");
    }

    /**
     * Convert to JSON-serializable array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'webhook_url' => $this->webhookUrl,
            'attempt' => $this->attempt,
            'status' => $this->status,
            'response_code' => $this->responseCode,
            'response_body' => $this->responseBody,
            'next_retry_at' => $this->nextRetryAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'delivered_at' => $this->deliveredAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
