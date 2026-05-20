<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Relay\HlsRelaySession;

/**
 * Unit tests for HlsRelaySession value object.
 *
 * @since 0.12.0
 */
class HlsRelaySessionTest extends TestCase
{
    public function testCanCreateSession(): void
    {
        $session = new HlsRelaySession(
            'session-123',
            'channel-456',
            'tune-789',
            1700000000,
            '/relay/live'
        );

        $this->assertInstanceOf(HlsRelaySession::class, $session);
    }

    public function testGetSessionIdReturnsCorrectValue(): void
    {
        $sessionId = '550e8400-e29b-41d4-a716-446655440000';
        $session = new HlsRelaySession(
            $sessionId,
            'channel-456',
            'tune-789',
            time()
        );

        $this->assertEquals($sessionId, $session->getSessionId());
        $this->assertEquals($sessionId, $session->sessionId);
    }

    public function testGetChannelIdReturnsCorrectValue(): void
    {
        $channelId = 'channel-abc-123';
        $session = new HlsRelaySession(
            'session-123',
            $channelId,
            'tune-789',
            time()
        );

        $this->assertEquals($channelId, $session->getChannelId());
        $this->assertEquals($channelId, $session->channelId);
    }

    public function testGetTuneRequestIdReturnsCorrectValue(): void
    {
        $tuneRequestId = 'tune-xyz-999';
        $session = new HlsRelaySession(
            'session-123',
            'channel-456',
            $tuneRequestId,
            time()
        );

        $this->assertEquals($tuneRequestId, $session->getTuneRequestId());
        $this->assertEquals($tuneRequestId, $session->tuneRequestId);
    }

    public function testGetCreatedAtReturnsCorrectValue(): void
    {
        $createdAt = 1700000000;
        $session = new HlsRelaySession(
            'session-123',
            'channel-456',
            'tune-789',
            $createdAt
        );

        $this->assertEquals($createdAt, $session->getCreatedAt());
        $this->assertEquals($createdAt, $session->createdAt);
    }

    public function testGetMountUrlFormatsCorrectly(): void
    {
        $sessionId = '550e8400-e29b-41d4-a716-446655440000';
        $session = new HlsRelaySession(
            $sessionId,
            'channel-456',
            'tune-789',
            time(),
            '/relay/live'
        );

        $expected = '/relay/live/550e8400-e29b-41d4-a716-446655440000/playlist.m3u8';
        $this->assertEquals($expected, $session->getMountUrl());
    }

    public function testGetMountUrlWithCustomPrefix(): void
    {
        $sessionId = 'abc-123';
        $session = new HlsRelaySession(
            $sessionId,
            'channel-456',
            'tune-789',
            time(),
            '/custom/relay'
        );

        $expected = '/custom/relay/abc-123/playlist.m3u8';
        $this->assertEquals($expected, $session->getMountUrl());
    }

    public function testGetVariantPlaylistUrl(): void
    {
        $sessionId = 'session-abc-123';
        $session = new HlsRelaySession(
            $sessionId,
            'channel-456',
            'tune-789',
            time()
        );

        $expected = '/hls/session-abc-123/stream_0.m3u8';
        $this->assertEquals($expected, $session->getVariantPlaylistUrl());
    }

    public function testSessionIdIsUuid(): void
    {
        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        $session = new HlsRelaySession(
            '550e8400-e29b-41d4-a716-446655440000',
            'channel-456',
            'tune-789',
            time()
        );

        $this->assertMatchesRegularExpression($uuidPattern, $session->getSessionId());
    }

    public function testSessionIsReadonly(): void
    {
        $session = new HlsRelaySession(
            'session-123',
            'channel-456',
            'tune-789',
            time()
        );

        // Verify properties are readonly by checking they exist and are public
        $reflection = new \ReflectionClass($session);
        $sessionIdProp = $reflection->getProperty('sessionId');
        $this->assertTrue($sessionIdProp->isReadOnly());
    }

    public function testDifferentSessionsHaveDifferentMountUrls(): void
    {
        $session1 = new HlsRelaySession(
            'session-111',
            'channel-1',
            'tune-1',
            time()
        );

        $session2 = new HlsRelaySession(
            'session-222',
            'channel-2',
            'tune-2',
            time()
        );

        $this->assertNotEquals($session1->getMountUrl(), $session2->getMountUrl());
    }
}
