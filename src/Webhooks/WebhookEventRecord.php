<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use DateTimeImmutable;
use JsonSerializable;
use Phlix\Common\Util\RowMap;

/**
 * Database-backed webhook event record.
 *
 * Represents a stored webhook event with its metadata, following the
 * Parse Don't Validate principle - once loaded from the database,
 * the data is trusted and typed.
 *
 * @property-read int $id Event ID
 * @property-read string $eventType Event type (e.g., media.scanned, transcode.completed)
 * @property-read array<string, mixed> $payload Event payload data
 * @property-read DateTimeImmutable $createdAt Event creation timestamp
 */
final class WebhookEventRecord implements JsonSerializable
{
    /**
     * @param int $id Event ID from database
     * @param string $eventType Event type identifier
     * @param array<string, mixed> $payload Event payload as JSON-compatible array
     * @param DateTimeImmutable $createdAt Event creation timestamp
     */
    public function __construct(
        public readonly int $id,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Create from database row.
     *
     * @param array<string, mixed> $row Database row
     */
    public static function fromRow(array $row): self
    {
        $payloadRaw = $row['payload'] ?? null;
        $payload = [];
        if (is_string($payloadRaw)) {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } elseif (is_array($payloadRaw)) {
            $payload = $payloadRaw;
        }

        $createdAt = $row['created_at'] ?? null;
        if (is_string($createdAt)) {
            $createdAt = new DateTimeImmutable($createdAt);
        } elseif (!$createdAt instanceof DateTimeImmutable) {
            $createdAt = new DateTimeImmutable();
        }

        // E-09: strict-int id and strict-string event_type via the
        // RowMap convention table; the payload JSON/array fold and the
        // temporal fallback stay hand-rolled verbatim (out of the scalar
        // pilot). Byte-identity pin:
        // tests/Unit/Webhooks/WebhookRecordsFromRowByteIdentityTest.php.
        return new self(
            id: RowMap::int($row, 'id', 0),
            eventType: RowMap::str($row, 'event_type', ''),
            payload: $payload,
            createdAt: $createdAt,
        );
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
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
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
