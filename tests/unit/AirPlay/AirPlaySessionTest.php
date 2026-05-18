<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\AirPlay;

use PHPUnit\Framework\TestCase;
use Phlex\AirPlay\AirPlayDevice;
use Phlex\AirPlay\AirPlaySession;
use Phlex\AirPlay\RaopClient;

class AirPlaySessionTest extends TestCase
{
    public function test_start_stream_transitions_to_streaming(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);
        $raopClient->method('buildAnnouncePayload')
            ->willReturn('ANNOUNCE payload');
        $raopClient->method('getLatency')
            ->willReturn(220);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        $result = $session->startStream('http://example.com/audio.m3u8', 'audio/mp4', 180);

        $this->assertSame('streaming', $result['status']);
        $this->assertSame('session-456', $result['session_id']);
        $this->assertSame('device-123', $result['device_id']);
        $this->assertSame(AirPlaySession::STATE_STREAMING, $session->getState());
    }

    public function test_pause_transitions_to_paused(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);
        $raopClient->method('buildAnnouncePayload')
            ->willReturn('ANNOUNCE payload');
        $raopClient->method('flush')
            ->willReturn(['cseq' => 1, 'rtp_time' => 0, 'status' => 'flushed']);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        // Start first to transition to streaming state
        $session->startStream('http://example.com/audio.m3u8', 'audio/mp4', 180);

        $result = $session->pause();

        $this->assertSame('paused', $result['status']);
        $this->assertSame(AirPlaySession::STATE_PAUSED, $session->getState());
    }

    public function test_resume_transitions_to_streaming(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);
        $raopClient->method('buildAnnouncePayload')
            ->willReturn('ANNOUNCE payload');
        $raopClient->method('flush')
            ->willReturn(['cseq' => 1, 'rtp_time' => 0, 'status' => 'flushed']);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        // Start first
        $session->startStream('http://example.com/audio.m3u8', 'audio/mp4', 180);

        // Pause
        $session->pause();

        $result = $session->resume();

        $this->assertSame('streaming', $result['status']);
        $this->assertSame(AirPlaySession::STATE_STREAMING, $session->getState());
    }

    public function test_stop_transitions_to_idle(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);
        $raopClient->method('buildAnnouncePayload')
            ->willReturn('ANNOUNCE payload');

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        // Start first
        $session->startStream('http://example.com/audio.m3u8', 'audio/mp4', 180);

        $result = $session->stop();

        $this->assertSame('stopped', $result['status']);
        $this->assertSame(AirPlaySession::STATE_IDLE, $session->getState());
    }

    public function test_get_device_returns_device(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        $this->assertSame($device, $session->getDevice());
        $this->assertSame('session-456', $session->getSessionId());
    }

    public function test_get_media_url_returns_null_initially(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        $this->assertNull($session->getMediaUrl());
    }

    public function test_get_content_type_returns_default(): void
    {
        $device = new AirPlayDevice(
            deviceId: 'device-123',
            name: 'Test Device',
            host: '192.168.1.100',
            port: 7000,
            raopPort: 7000,
        );

        $raopClient = $this->createMock(RaopClient::class);

        $session = new AirPlaySession(
            'session-456',
            $device,
            $raopClient,
        );

        $this->assertSame('audio/mp4', $session->getContentType());
    }
}
