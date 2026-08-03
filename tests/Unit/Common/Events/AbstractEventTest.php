<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events;

use PHPUnit\Framework\TestCase;
use Phlix\Tests\Fixtures\Events\SampleEvent;

/**
 * Smoke test for {@see \Phlix\Common\Events\AbstractEvent}.
 */
final class AbstractEventTest extends TestCase
{
    public function test_timestamp_is_populated(): void
    {
        $before = time();
        $event = new SampleEvent('hi');
        $after = time();

        $this->assertGreaterThanOrEqual($before, $event->timestamp);
        $this->assertLessThanOrEqual($after, $event->timestamp);
    }

    public function test_timestamp_is_immutable(): void
    {
        $event = new SampleEvent('hi');
        $this->expectException(\Error::class);
        // S128: the suppression that used to sit here is gone. At the level this repo
        // gates tests/ at, the readonly write is NOT reported, so the suppression was
        // itself an error (`ignore.unmatchedLine`) — a comment claiming to silence
        // something that was never said. If the tests/ level is raised far enough to
        // report it, re-add a suppression WITH its identifier so the next unmatched one
        // is loud again. (⚠ And do not name the directive in prose: PHPStan parses it
        // out of a `//` comment too, which turns the explanation into a parse error.)
        $event->timestamp = 0;
    }
}
