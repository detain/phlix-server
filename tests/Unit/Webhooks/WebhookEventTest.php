<?php

namespace Phlix\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\WebhookEvent;

class WebhookEventTest extends TestCase
{
    public function testEventToArrayIncludesAllFields(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123', 'position' => 0],
            $occurredAt
        );

        $array = $event->toArray();

        $this->assertEquals('playback.started', $array['event_type']);
        $this->assertEquals(['media_id' => 'media-123', 'position' => 0], $array['payload']);
        $this->assertEquals('2024-01-15T10:30:00+00:00', $array['occurred_at']);
    }

    public function testGetSignatureProducesHmacSha256(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123'],
            $occurredAt
        );

        $secret = 'test-secret-key';
        $signature = $event->getSignature($secret);

        $this->assertStringStartsWith('sha256=', $signature);

        $expectedPayload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        $expectedHash = hash_hmac('sha256', $expectedPayload, $secret);
        $this->assertEquals("sha256={$expectedHash}", $signature);
    }

    public function testGetSignatureIsConsistent(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $event = new WebhookEvent(
            'library.updated',
            ['library_id' => 'lib-1', 'action' => 'scan_complete'],
            $occurredAt
        );

        $secret = 'my-secret';
        $signature1 = $event->getSignature($secret);
        $signature2 = $event->getSignature($secret);

        $this->assertEquals($signature1, $signature2);
    }

    public function testGetSignatureDiffersForDifferentSecrets(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $event = new WebhookEvent(
            'download.complete',
            ['download_id' => 'dl-1'],
            $occurredAt
        );

        $signature1 = $event->getSignature('secret1');
        $signature2 = $event->getSignature('secret2');

        $this->assertNotEquals($signature1, $signature2);
    }

    public function testGetSignatureDiffersForDifferentPayloads(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $event1 = new WebhookEvent('test.event', ['key' => 'value1'], $occurredAt);
        $event2 = new WebhookEvent('test.event', ['key' => 'value2'], $occurredAt);

        $secret = 'same-secret';
        $signature1 = $event1->getSignature($secret);
        $signature2 = $event2->getSignature($secret);

        $this->assertNotEquals($signature1, $signature2);
    }

    public function testSerializedPayloadIsTheCanonicalSignedBytes(): void
    {
        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123'],
            new DateTimeImmutable('2024-01-15T10:30:00+00:00')
        );

        $this->assertSame(
            json_encode($event->toArray(), JSON_THROW_ON_ERROR),
            $event->serializedPayload()
        );
    }

    public function testTimestampedSignatureCoversTimestampDotPayload(): void
    {
        $event = new WebhookEvent(
            'playback.started',
            ['media_id' => 'media-123'],
            new DateTimeImmutable('2024-01-15T10:30:00+00:00')
        );
        $secret = 'topsecret';
        $timestamp = 1700000000;

        $header = $event->getTimestampedSignature($secret, $timestamp);

        $expected = hash_hmac('sha256', $timestamp . '.' . $event->serializedPayload(), $secret);
        $this->assertSame("t={$timestamp},v1={$expected}", $header);
    }

    public function testTimestampedSignatureIsDeterministicPerTimestamp(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));

        $this->assertSame(
            $event->getTimestampedSignature('s', 1700000000),
            $event->getTimestampedSignature('s', 1700000000)
        );
        $this->assertNotSame(
            $event->getTimestampedSignature('s', 1700000000),
            $event->getTimestampedSignature('s', 1700000001),
            'A second apart must produce a different header — the timestamp is signed.'
        );
    }

    public function testVerifyAcceptsFreshTimestampedHeader(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));
        $secret = 's';
        $now = 1700000000;

        $header = $event->getTimestampedSignature($secret, $now);

        $this->assertTrue(WebhookEvent::verify($secret, $event->serializedPayload(), $header, null, 300, $now));
    }

    public function testVerifyRejectsReplayOutsideToleranceWindow(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));
        $secret = 's';
        $now = 1700000000;
        $header = $event->getTimestampedSignature($secret, $now);
        $body = $event->serializedPayload();

        $this->assertTrue(
            WebhookEvent::verify($secret, $body, $header, null, 300, $now + 300),
            'exactly at the window edge is still inside'
        );
        $this->assertFalse(
            WebhookEvent::verify($secret, $body, $header, null, 300, $now + 301),
            'a capture replayed past the tolerance window must die'
        );
        $this->assertFalse(
            WebhookEvent::verify($secret, $body, $header, null, 300, $now - 301),
            'a header clocked far in the future is equally untrustworthy'
        );
    }

    public function testVerifyRejectsTamperedBodyAndWrongSecret(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));
        $secret = 's';
        $now = 1700000000;
        $header = $event->getTimestampedSignature($secret, $now);

        $this->assertFalse(WebhookEvent::verify($secret, '{"tampered":true}', $header, null, 300, $now));
        $this->assertFalse(WebhookEvent::verify('other-secret', $event->serializedPayload(), $header, null, 300, $now));
    }

    public function testVerifyAcceptsAnyMatchingV1CandidateAndFailsClosedOnMalformedHeaders(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));
        $secret = 's';
        $now = 1700000000;
        $body = $event->serializedPayload();
        $good = hash_hmac('sha256', $now . '.' . $body, $secret);

        // Stripe's secret-rotation shape: several v1 candidates, one valid.
        $rotated = "t={$now},v1=" . str_repeat('00', 32) . ",v1={$good}";
        $this->assertTrue(WebhookEvent::verify($secret, $body, $rotated, null, 300, $now));

        $this->assertFalse(
            WebhookEvent::verify($secret, $body, "t=abc,v1={$good}", null, 300, $now),
            'non-numeric t= fails closed'
        );
        $this->assertFalse(
            WebhookEvent::verify($secret, $body, "v1={$good}", null, 300, $now),
            'missing t= fails closed'
        );
        $this->assertFalse(
            WebhookEvent::verify($secret, $body, "t={$now}", null, 300, $now),
            'missing v1= fails closed'
        );
    }

    public function testVerifyLegacyHeaderOnlyWhenNoTimestampedHeaderGiven(): void
    {
        $event = new WebhookEvent('test.event', ['k' => 'v'], new DateTimeImmutable('2024-01-15T10:30:00+00:00'));
        $secret = 's';
        $body = $event->serializedPayload();
        $legacy = $event->getSignature($secret);

        $this->assertTrue(WebhookEvent::verify($secret, $body, null, $legacy));
        $this->assertFalse(WebhookEvent::verify($secret, $body, null, 'sha256=' . str_repeat('f', 64)));
        $this->assertFalse(WebhookEvent::verify($secret, $body, null, null), 'unsigned delivery is never trusted');
    }
}
