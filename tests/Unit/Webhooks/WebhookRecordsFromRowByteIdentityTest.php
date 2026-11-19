<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\WebhookDeliveryRecord;
use Phlix\Webhooks\WebhookEventRecord;
use Phlix\Webhooks\WebhookSubscriptionRecord;

/**
 * E-09 byte-identity pin for the Webhook DTO trio's fromRow().
 *
 * The three records hand-rolled their narrowings in a DIFFERENT family
 * than Collection: strict `is_int` for ids (a numeric string yields 0,
 * not the number — this is the drift E-09 documents, preserved here
 * deliberately, harmonized nowhere), strict `is_string` for URLs and
 * status, `(bool)` truthy cast for is_active with a `?? true` default,
 * and `isset() && is_int()` for the nullable response_code. Each legacy
 * expression is transcribed verbatim into the oracle below.
 *
 * Temporal columns always arrive as explicit strings or nulls in the
 * provider so comparisons are race-free; the fallback branches (missing
 * / garbage timestamps) have their own proximity tests because "now"
 * cannot be byte-compared across two evaluations.
 */
class WebhookRecordsFromRowByteIdentityTest extends TestCase
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function legacySubscriptionToArray(array $row): array
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

        return [
            'id' => is_int($row['id'] ?? null) ? (int) $row['id'] : 0,
            'url' => is_string($row['url'] ?? null) ? (string) $row['url'] : '',
            'events' => is_array($events) ? array_filter($events, 'is_string') : [],
            'is_active' => (bool) ($row['is_active'] ?? true),
            'created_at' => $createdAt->format(DateTimeImmutable::ATOM),
            'updated_at' => $updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function legacyDeliveryToArray(array $row): array
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

        return [
            'id' => is_int($row['id'] ?? null) ? (int) $row['id'] : 0,
            'event_id' => is_int($row['event_id'] ?? null) ? (int) $row['event_id'] : 0,
            'webhook_url' => is_string($row['webhook_url'] ?? null) ? (string) $row['webhook_url'] : '',
            'attempt' => is_int($row['attempt'] ?? null) ? (int) $row['attempt'] : 1,
            'status' => is_string($row['status'] ?? null) ? (string) $row['status'] : 'pending',
            'response_code' => isset($row['response_code']) && is_int($row['response_code'])
                ? (int) $row['response_code'] : null,
            'response_body' => is_string($row['response_body'] ?? null) ? (string) $row['response_body'] : null,
            'next_retry_at' => $nextRetryAt?->format(DateTimeImmutable::ATOM),
            'created_at' => $createdAt->format(DateTimeImmutable::ATOM),
            'delivered_at' => $deliveredAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function legacyEventToArray(array $row): array
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

        return [
            'id' => is_int($row['id'] ?? null) ? (int) $row['id'] : 0,
            'event_type' => is_string($row['event_type'] ?? null) ? (string) $row['event_type'] : '',
            'payload' => $payload,
            'created_at' => $createdAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function subscriptionRowProvider(): array
    {
        return [
            'full row' => [[
                'id' => 7, 'url' => 'https://example.com/hook',
                'events' => '["media.playback.started","media.other"]', 'is_active' => 1,
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'numeric-string id rejected to zero (strict family)' => [[
                'id' => '5', 'url' => 'u', 'events' => '[]', 'is_active' => 0,
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'events with non-string members filtered, keys preserved' => [[
                'id' => 1, 'url' => 'u', 'events' => [0 => 'a', 1 => 2, 2 => null, 5 => 'b'],
                'is_active' => true,
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'bool grammar: "0" false, "1" true, null defaults true' => [[
                'id' => 1, 'url' => 'u', 'events' => '[]', 'is_active' => '0',
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'is_active null takes true default' => [[
                'id' => 1, 'url' => 'u', 'events' => '[]', 'is_active' => null,
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'is_active absent takes true default' => [[
                'id' => 1, 'url' => 'u', 'events' => '[]',
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
            'malformed events json collapses to empty' => [[
                'id' => 1, 'url' => null, 'events' => '{not json', 'is_active' => false,
                'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00',
            ]],
        ];
    }

    #[DataProvider('subscriptionRowProvider')]
    public function testSubscriptionFromRowByteIdenticalToLegacy(array $row): void
    {
        $this->assertSame(
            self::legacySubscriptionToArray($row),
            WebhookSubscriptionRecord::fromRow($row)->toArray()
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function deliveryRowProvider(): array
    {
        return [
            'full delivered row' => [[
                'id' => 11, 'event_id' => 4, 'webhook_url' => 'https://e/h',
                'attempt' => 2, 'status' => 'delivered', 'response_code' => 200,
                'response_body' => 'ok', 'next_retry_at' => null,
                'created_at' => '2026-01-01 10:00:00', 'delivered_at' => '2026-01-01 10:00:31',
            ]],
            'strict ids: numeric strings collapse to defaults' => [[
                'id' => '11', 'event_id' => '4', 'webhook_url' => 55, 'attempt' => '2',
                'status' => null, 'response_code' => '500', 'response_body' => 77,
                'next_retry_at' => '2026-03-03 12:00:00', 'created_at' => '2026-01-01 10:00:00',
                'delivered_at' => '2026-03-04 00:00:00',
            ]],
            'response_code zero survives; absent nulls' => [[
                'id' => 1, 'event_id' => 1, 'webhook_url' => 'u', 'attempt' => 1,
                'status' => 'failed', 'response_code' => 0, 'response_body' => '',
                'next_retry_at' => '2026-03-03 12:00:00', 'created_at' => '2026-01-01 10:00:00',
                'delivered_at' => null,
            ]],
            'garbage non-null timestamps hydrate null (not now)' => [[
                'id' => 1, 'event_id' => 1, 'webhook_url' => 'u', 'attempt' => 3,
                'status' => 'failed', 'next_retry_at' => 5, 'delivered_at' => 5,
                'created_at' => '2026-01-01 10:00:00',
            ]],
            'minimal row' => [[
                'id' => 1, 'event_id' => 1, 'webhook_url' => 'u', 'attempt' => 1,
                'status' => 'pending', 'created_at' => '2026-01-01 10:00:00',
            ]],
        ];
    }

    #[DataProvider('deliveryRowProvider')]
    public function testDeliveryFromRowByteIdenticalToLegacy(array $row): void
    {
        $this->assertSame(
            self::legacyDeliveryToArray($row),
            WebhookDeliveryRecord::fromRow($row)->toArray()
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function eventRowProvider(): array
    {
        return [
            'full row' => [[
                'id' => 3, 'event_type' => 'media.playback.started',
                'payload' => '{"a":1}', 'created_at' => '2026-01-01 10:00:00',
            ]],
            'pre-decoded payload passes through' => [[
                'id' => 3, 'event_type' => 'e', 'payload' => ['k' => 'v'],
                'created_at' => '2026-01-01 10:00:00',
            ]],
            'strict id + null type + bad json' => [[
                'id' => '3', 'event_type' => null, 'payload' => 'nope',
                'created_at' => '2026-01-01 10:00:00',
            ]],
            'int payload collapses to empty' => [[
                'id' => 3, 'event_type' => 'e', 'payload' => 44,
                'created_at' => '2026-01-01 10:00:00',
            ]],
        ];
    }

    #[DataProvider('eventRowProvider')]
    public function testEventFromRowByteIdenticalToLegacy(array $row): void
    {
        $this->assertSame(
            self::legacyEventToArray($row),
            WebhookEventRecord::fromRow($row)->toArray()
        );
    }

    public function testSubscriptionMissingTimestampsFallBackToNow(): void
    {
        $arr = WebhookSubscriptionRecord::fromRow(['id' => 1, 'url' => 'u'])->toArray();
        $now = new DateTimeImmutable();

        foreach (['created_at', 'updated_at'] as $key) {
            $parsed = new DateTimeImmutable($arr[$key]);
            $this->assertLessThanOrEqual(5, abs($now->getTimestamp() - $parsed->getTimestamp()), $key);
        }
    }

    public function testDeliveryMissingCreatedAtFallsBackToNowAndNullTimestampsStayNull(): void
    {
        $arr = WebhookDeliveryRecord::fromRow([
            'id' => 1, 'event_id' => 1, 'webhook_url' => 'u', 'attempt' => 1, 'status' => 'pending',
        ])->toArray();

        $this->assertNull($arr['delivered_at']);
        $this->assertNull($arr['next_retry_at']);
        $this->assertLessThanOrEqual(
            5,
            abs((new DateTimeImmutable())->getTimestamp() - (new DateTimeImmutable($arr['created_at']))->getTimestamp())
        );
    }

    public function testEventMissingCreatedAtFallsBackToNow(): void
    {
        $arr = WebhookEventRecord::fromRow(['id' => 1, 'event_type' => 'x', 'payload' => '{}'])->toArray();
        $this->assertLessThanOrEqual(
            5,
            abs((new DateTimeImmutable())->getTimestamp() - (new DateTimeImmutable($arr['created_at']))->getTimestamp())
        );
    }

    public function testHandlesEventLawUntouchedByMigration(): void
    {
        // guards that the still-hand-rolled (out-of-scope) events decode +
        // catalog normalization path survived the id/url/is_active move.
        $sub = WebhookSubscriptionRecord::fromRow([
            'id' => 1, 'url' => 'u', 'events' => '["media.scanned"]', 'is_active' => 1,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00',
        ]);
        $this->assertTrue($sub->handlesEvent('media.scanned'));
        $this->assertFalse($sub->handlesEvent('media.deleted'));
    }
}
