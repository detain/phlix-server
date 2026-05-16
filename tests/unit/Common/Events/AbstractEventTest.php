<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events;

use PHPUnit\Framework\TestCase;
use Phlex\Tests\Fixtures\Events\SampleEvent;

/**
 * Smoke test for {@see \Phlex\Common\Events\AbstractEvent}.
 *
 * @covers \Phlex\Common\Events\AbstractEvent
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
