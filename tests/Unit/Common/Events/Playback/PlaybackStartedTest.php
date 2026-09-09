<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Playback;

use Phlix\Shared\Events\Playback\PlaybackStarted;
use PHPUnit\Framework\TestCase;

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
        // S128: see AbstractEventTest — the suppression matched nothing at the tests/
        // level and so was itself reported. Re-add it with an identifier if the level
        // rises to where the readonly write is reported.
        //
        // It has: Psalm 6 at the tests/ level reports the intentional readonly write
        // as InaccessibleProperty, so it is suppressed here with its identifier —
        // exactly the loud re-add S128 asked for (the write IS the assertion).
        //
        // S186: the tests/ PHPStan leg is now at level 4 and reports this same
        // intentional out-of-class readonly write, so a line-scoped PHPStan
        // suppression with its identifier sits inline on the write — same-line so the
        // psalm docblock above still binds, and loud-if-it-stops-matching.
        /** @psalm-suppress InaccessibleProperty - intentional write; PHP must throw Error */
        $event->positionTicks = 2; // @phpstan-ignore property.readOnlyAssignOutOfClass
    }
}
