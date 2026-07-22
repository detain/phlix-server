<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Enrichment;

use Phlix\Media\Metadata\Enrichment\PluginEnrichmentQueue;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Enrichment\PluginEnrichmentQueue
 */
final class PluginEnrichmentQueueTest extends TestCase
{
    public function testEnqueueDeDuplicatesAndBoundsSize(): void
    {
        $queue = new PluginEnrichmentQueue(1.0, 2);

        $this->assertTrue($queue->enqueue('a'));
        $this->assertFalse($queue->enqueue('a'), 'duplicate must be refused');
        $this->assertTrue($queue->enqueue('b'));
        $this->assertFalse($queue->enqueue('c'), 'must not grow past maxSize=2');
        $this->assertFalse($queue->enqueue(''), 'empty id must be refused');
        $this->assertSame(2, $queue->size());
    }

    public function testDequeueDueReleasesAtMostOnePerInterval(): void
    {
        $now = 1000.0;
        $queue = new PluginEnrichmentQueue(5.0, 100, static function () use (&$now): float {
            return $now;
        });

        $queue->enqueue('a');
        $queue->enqueue('b');

        // First dispatch is always allowed.
        $this->assertSame('a', $queue->dequeueDue());
        // Still inside the 5s cool-down → throttled.
        $this->assertNull($queue->dequeueDue());

        // Advance past the interval → next item released.
        $now += 5.0;
        $this->assertSame('b', $queue->dequeueDue());

        // Empty queue → null.
        $now += 10.0;
        $this->assertNull($queue->dequeueDue());
    }

    public function testMinIntervalClampedToOneSecondFloor(): void
    {
        $queue = new PluginEnrichmentQueue(0.0, 10);
        $this->assertSame(1.0, $queue->minIntervalSeconds());
    }
}
