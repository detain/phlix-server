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
 * Database-backed webhook subscription record.
 *
 * Represents a webhook endpoint subscription with its subscribed events.
 *
 * @property-read int $id Subscription ID
 * @property-read string $url Webhook endpoint URL
 * @property-read array<string> $events Array of subscribed event types
 * @property-read bool $isActive Whether subscription is active
 * @property-read DateTimeImmutable $createdAt Subscription creation timestamp
 * @property-read DateTimeImmutable $updatedAt Last update timestamp
 */
final class WebhookSubscriptionRecord implements JsonSerializable
{
    /**
     * @param int $id Subscription ID
     * @param string $url Webhook endpoint URL
     * @param array<string> $events Array of subscribed event types
     * @param bool $isActive Whether subscription is active
     * @param DateTimeImmutable $createdAt Subscription creation timestamp
     * @param DateTimeImmutable $updatedAt Last update timestamp
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly array $events,
        public readonly bool $isActive,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Create from database row.
     *
     * @param array<string, mixed> $row Database row
     */
    public static function fromRow(array $row): self
    {
        $events = $row['events'] ?? [];
        if (is_string($events)) {
            $decoded = json_decode($events, true);
            $events = is_array($decoded) ? $decoded : [];
        }

        $createdAt = $row['created_at'] ?? null;
        if (is_string($createdAt)) {
            $createdAt = new DateTimeImmutable($createdAt);
        } elseif (!$createdAt instanceof DateTimeImmutable) {
            $createdAt = new DateTimeImmutable();
        }

        $updatedAt = $row['updated_at'] ?? null;
        if (is_string($updatedAt)) {
            $updatedAt = new DateTimeImmutable($updatedAt);
        } elseif (!$updatedAt instanceof DateTimeImmutable) {
            $updatedAt = new DateTimeImmutable();
        }

        return new self(
            id: is_int($row['id'] ?? null) ? (int) $row['id'] : 0,
            url: is_string($row['url'] ?? null) ? (string) $row['url'] : '',
            events: is_array($events) ? array_filter($events, 'is_string') : [],
            isActive: (bool) ($row['is_active'] ?? true),
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Check if this subscription is interested in the given event type.
     */
    public function handlesEvent(string $eventType): bool
    {
        return $this->isActive && in_array($eventType, $this->events, true);
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
            'url' => $this->url,
            'events' => $this->events,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeImmutable::ATOM),
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
