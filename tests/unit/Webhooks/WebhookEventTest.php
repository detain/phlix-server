<?php

namespace Phlex\Tests\Unit\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlex\Webhooks\WebhookEvent;

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

        $this->assertIsArray($array);
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

        $this->assertIsString($signature);
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
}
