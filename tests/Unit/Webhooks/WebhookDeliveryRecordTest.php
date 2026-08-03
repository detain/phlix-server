<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\WebhookDeliveryRecord;

/**
 * Unit tests for WebhookDeliveryRecord's jittered retry-delay calculation
 * (SV-4.4 / S-F10): delay grows across attempts and is capped at MAX_ATTEMPTS.
 *
 */
class WebhookDeliveryRecordTest extends TestCase
{
    private function delivery(int $attempt): WebhookDeliveryRecord
    {
        return new WebhookDeliveryRecord(
            id: 1,
            eventId: 1,
            webhookUrl: 'https://example.com/webhook',
            attempt: $attempt,
            status: WebhookDeliveryRecord::STATUS_PENDING,
            responseCode: 500,
            responseBody: null,
            nextRetryAt: null,
            createdAt: new DateTimeImmutable(),
            deliveredAt: null,
        );
    }

    /**
     * Base delays are 30s/300s/1800s (RETRY_DELAYS[1..3]); jitter is a
     * uniform +/-20% adjustment, so the possible ranges are
     * [24,36] / [240,360] / [1440,2160] and — critically — never overlap
     * across attempts, so "grows across retries" holds regardless of the
     * random draw.
     */
    public function testDelayIsJitteredWithinTwentyPercentOfBaseDelay(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $attempt0 = $this->delivery(0)->calculateNextRetryDelaySeconds();
            $attempt1 = $this->delivery(1)->calculateNextRetryDelaySeconds();
            $attempt2 = $this->delivery(2)->calculateNextRetryDelaySeconds();

            $this->assertNotNull($attempt0);
            $this->assertNotNull($attempt1);
            $this->assertNotNull($attempt2);

            $this->assertGreaterThanOrEqual(24, $attempt0);
            $this->assertLessThanOrEqual(36, $attempt0);

            $this->assertGreaterThanOrEqual(240, $attempt1);
            $this->assertLessThanOrEqual(360, $attempt1);

            $this->assertGreaterThanOrEqual(1440, $attempt2);
            $this->assertLessThanOrEqual(2160, $attempt2);

            // Delay grows across attempts even accounting for jitter: the
            // ranges above never overlap.
            $this->assertLessThan($attempt1, $attempt0);
            $this->assertLessThan($attempt2, $attempt1);
        }
    }

    /**
     * Jitter must vary the delay across calls (not collapse to a single
     * constant value) — otherwise it provides no thundering-herd protection.
     */
    public function testJitterVariesAcrossCalls(): void
    {
        $values = [];
        for ($i = 0; $i < 30; $i++) {
            $values[] = $this->delivery(1)->calculateNextRetryDelaySeconds();
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($values)),
            'jitter must vary the delay, not always return the same value',
        );
    }

    /**
     * Max attempts caps retries: once attempt >= MAX_ATTEMPTS, no further
     * delay/retry is computed — the delivery is permanently failed.
     */
    public function testDelayIsNullAtAndBeyondMaxAttempts(): void
    {
        $this->assertNull($this->delivery(WebhookDeliveryRecord::MAX_ATTEMPTS)->calculateNextRetryDelaySeconds());
        $this->assertNull($this->delivery(WebhookDeliveryRecord::MAX_ATTEMPTS + 1)->calculateNextRetryDelaySeconds());
    }

    /**
     * calculateNextRetryAt() must use the SAME delay value the caller
     * precomputed via calculateNextRetryDelaySeconds() when passed as an
     * override — this is what lets WebhookService keep the persisted
     * `next_retry_at` and the scheduled retry Timer consistent (each fresh
     * call to calculateNextRetryDelaySeconds() draws a new random jitter, so
     * calling it twice independently would drift).
     */
    public function testCalculateNextRetryAtUsesTheProvidedDelayOverrideExactly(): void
    {
        $delivery = $this->delivery(1);
        $from = new DateTimeImmutable('2026-01-01 00:00:00');

        $nextRetryAt = $delivery->calculateNextRetryAt($from, 123);

        $this->assertSame('2026-01-01 00:02:03', $nextRetryAt?->format('Y-m-d H:i:s'));
    }

    public function testCalculateNextRetryAtReturnsNullWhenDelayOverrideIsNull(): void
    {
        // Simulates the exhausted-retries case: no override value available.
        $delivery = $this->delivery(WebhookDeliveryRecord::MAX_ATTEMPTS);

        $this->assertNull($delivery->calculateNextRetryAt(null, null));
    }
}
