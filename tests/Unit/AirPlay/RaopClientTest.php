<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlix\AirPlay\RaopClient;

class RaopClientTest extends TestCase
{
    public function test_build_announce_payload_contains_audio_format(): void
    {
        $client = new RaopClient('192.168.1.100', 7000);

        $payload = $client->buildAnnouncePayload(
            'http://example.com/stream.m3u8',
            'audio/mp4',
            3600
        );

        $this->assertStringContainsString('ANNOUNCE', $payload);
        $this->assertStringContainsString('192.168.1.100', $payload);
        $this->assertStringContainsString('mpeg4-generic', $payload);
    }

    public function test_flush_sends_rtsp_flush_command(): void
    {
        $client = new RaopClient('192.168.1.100', 7000);

        $result = $client->flush(0);

        $this->assertArrayHasKey('cseq', $result);
        $this->assertArrayHasKey('rtp_time', $result);
        $this->assertSame(0, $result['rtp_time']);
        $this->assertSame('flushed', $result['status']);
    }

    public function test_get_rtp_info_parses_response(): void
    {
        $client = new RaopClient('192.168.1.100', 7000);

        $result = $client->getRtpInfo();

        $this->assertArrayHasKey('cseq', $result);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertArrayHasKey('device_host', $result);
        $this->assertSame('192.168.1.100', $result['device_host']);
    }

    public function test_get_latency_returns_default_value(): void
    {
        $client = new RaopClient('192.168.1.100', 7000);

        $latency = $client->getLatency();

        // Default AirPlay latency is around 220ms
        $this->assertSame(220, $latency);
    }

    public function test_build_announce_payload_includes_content_type(): void
    {
        $client = new RaopClient('192.168.1.100', 7000);

        $payloadAac = $client->buildAnnouncePayload(
            'http://example.com/stream.m3u8',
            'audio/aac',
            0
        );

        $payloadMp4 = $client->buildAnnouncePayload(
            'http://example.com/stream.m3u8',
            'audio/mp4',
            0
        );

        // Both should contain mpeg4-generic for AAC audio
        $this->assertStringContainsString('mpeg4-generic', $payloadAac);
        $this->assertStringContainsString('mpeg4-generic', $payloadMp4);
    }
}
