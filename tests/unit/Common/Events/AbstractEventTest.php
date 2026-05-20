<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events;

use PHPUnit\Framework\TestCase;
use Phlix\Tests\Fixtures\Events\SampleEvent;

/**
 * Smoke test for {@see \Phlix\Common\Events\AbstractEvent}.
 *
 * @covers \Phlix\Shared\Events\AbstractEvent
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
        /** @phpstan-ignore-next-line  intentional readonly reassignment for test */
        $event->timestamp = 0;
    }
}
