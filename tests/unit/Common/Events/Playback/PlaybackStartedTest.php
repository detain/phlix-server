<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Playback;

use Phlex\Common\Events\Playback\PlaybackStarted;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Playback\PlaybackStarted
 */
final class PlaybackStartedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new PlaybackStarted(
            sessionId: 'session-1',
            userId: 'user-1',
            mediaItemId: 'item-1',
            deviceId: 'device-1',
            positionTicks: 0,
        );

        $this->assertSame('session-1', $event->sessionId);
        $this->assertSame('user-1', $event->userId);
        $this->assertSame('item-1', $event->mediaItemId);
        $this->assertSame('device-1', $event->deviceId);
        $this->assertSame(0, $event->positionTicks);
        $this->assertGreaterThan(0, $event->timestamp);
    }

    public function test_immutable_fields(): void
    {
        $event = new PlaybackStarted(
            sessionId: 's',
            userId: 'u',
            mediaItemId: 'm',
            deviceId: 'd',
            positionTicks: 1,
        );

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line  intentional readonly reassignment for test */
        $event->positionTicks = 2;
    }
}
