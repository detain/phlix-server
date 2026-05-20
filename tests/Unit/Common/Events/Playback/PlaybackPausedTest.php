<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Playback;

use Phlix\Shared\Events\Playback\PlaybackPaused;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Playback\PlaybackPaused
 */
final class PlaybackPausedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new PlaybackPaused('s', 'u', 'm', 'd', 1234);
        $this->assertSame('s', $event->sessionId);
        $this->assertSame('u', $event->userId);
        $this->assertSame('m', $event->mediaItemId);
        $this->assertSame('d', $event->deviceId);
        $this->assertSame(1234, $event->positionTicks);
    }
}
