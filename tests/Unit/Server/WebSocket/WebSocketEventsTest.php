<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebSocket;

use PHPUnit\Framework\TestCase;
use Phlix\Server\WebSocket\WebSocketEvents;
use Phlix\Session\SyncPlay\Messages;

/**
 * SV-4.7 Gap 6: privileged-vs-public event classification.
 *
 * @covers \Phlix\Server\WebSocket\WebSocketEvents
 */
class WebSocketEventsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function publicEventProvider(): array
    {
        return [
            'ping' => [WebSocketEvents::PING],
            'pong' => [WebSocketEvents::PONG],
            'auth_request' => [WebSocketEvents::AUTH_REQUEST],
            'connected' => [WebSocketEvents::CONNECTED],
        ];
    }

    /**
     * @dataProvider publicEventProvider
     */
    public function testPublicEventsAreNotPrivileged(string $type): void
    {
        $this->assertTrue(WebSocketEvents::isPublic($type));
        $this->assertFalse(WebSocketEvents::isPrivileged($type));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privilegedEventProvider(): array
    {
        return [
            'subscribe_dashboard' => [WebSocketEvents::SUBSCRIBE_DASHBOARD],
            'dashboard_now_playing' => [WebSocketEvents::DASHBOARD_NOW_PLAYING],
            'playback_start' => [WebSocketEvents::PLAYBACK_START],
            'playback_seek' => [WebSocketEvents::PLAYBACK_SEEK],
            'session_start' => [WebSocketEvents::SESSION_START],
            'session_leave' => [WebSocketEvents::SESSION_LEAVE],
            'syncplay_group_create' => [Messages::TYPE_GROUP_CREATE],
            'syncplay_playback_play' => [Messages::TYPE_PLAYBACK_PLAY],
            'syncplay_time_ping' => [Messages::TYPE_TIME_PING],
        ];
    }

    /**
     * @dataProvider privilegedEventProvider
     */
    public function testPrivilegedEventsRequireAuth(string $type): void
    {
        $this->assertTrue(WebSocketEvents::isPrivileged($type));
        $this->assertFalse(WebSocketEvents::isPublic($type));
    }

    public function testEverySyncplayPrefixedTypeIsPrivileged(): void
    {
        $this->assertTrue(WebSocketEvents::isPrivileged('syncplay_anything_new'));
        $this->assertStringStartsWith(WebSocketEvents::SYNCPLAY_PREFIX, Messages::TYPE_GROUP_JOIN);
    }

    public function testUnknownNonPrivilegedEventIsNotGated(): void
    {
        // A server->client-only / unrecognised type is not in the privileged set
        // and is not SyncPlay-prefixed, so it is not gated (no registered inbound
        // handler dispatches it either).
        $this->assertFalse(WebSocketEvents::isPrivileged('some_unknown_event'));
    }
}
