<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Playback;

use Phlex\Common\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Playback\PlaybackStopped
 */
final class PlaybackStoppedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new PlaybackStopped(
            sessionId: 's',
            userId: 'u',
            mediaItemId: 'm',
            deviceId: 'd',
            finalPositionTicks: 7,
            reachedEnd: true,
        );

        $this->assertSame(7, $event->finalPositionTicks);
        $this->assertTrue($event->reachedEnd);
    }

    public function test_reached_end_false_path(): void
    {
        $event = new PlaybackStopped('s', 'u', 'm', 'd', 0, false);
        $this->assertFalse($event->reachedEnd);
    }
}
