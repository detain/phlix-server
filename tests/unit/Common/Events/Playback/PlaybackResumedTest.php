<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Playback;

use Phlex\Shared\Events\Playback\PlaybackResumed;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Events\Playback\PlaybackResumed
 */
final class PlaybackResumedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new PlaybackResumed('s', 'u', 'm', 'd', 9999);
        $this->assertSame('s', $event->sessionId);
        $this->assertSame(9999, $event->positionTicks);
    }
}
