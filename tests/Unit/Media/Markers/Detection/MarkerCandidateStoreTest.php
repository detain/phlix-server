<?php

namespace Phlix\Tests\Unit\Media\Markers\Detection;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\Detection\MarkerCandidateStore;

class MarkerCandidateStoreTest extends TestCase
{
    private string $testQueueDir;
    private MarkerCandidateStore $store;

    protected function setUp(): void
    {
        $this->testQueueDir = sys_get_temp_dir() . '/phlix_test_marker_' . uniqid();
        $this->store = new MarkerCandidateStore($this->testQueueDir);
    }

    protected function tearDown(): void
    {
        $this->store->clear();

        if (is_dir($this->testQueueDir)) {
            $files = glob($this->testQueueDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->testQueueDir);
        }
    }

    public function testEnqueueAndDequeue(): void
    {
        $this->store->enqueueShow('show-1');

        $this->assertTrue($this->store->isEnqueued('show-1'));
        $this->assertEquals(1, $this->store->queueSize());

        $dequeued = $this->store->dequeueShow();
        $this->assertEquals('show-1', $dequeued);
    }

    public function testDequeueIsFifo(): void
    {
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-2');
        $this->store->enqueueShow('show-3');

        $first = $this->store->dequeueShow();
        $second = $this->store->dequeueShow();
        $third = $this->store->dequeueShow();

        $this->assertEquals('show-1', $first);
        $this->assertEquals('show-2', $second);
        $this->assertEquals('show-3', $third);
    }

    public function testCompleteRemovesFromQueue(): void
    {
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-2');

        $this->store->completeShow('show-1');

        $this->assertFalse($this->store->isEnqueued('show-1'));
        $this->assertTrue($this->store->isEnqueued('show-2'));
        $this->assertEquals(1, $this->store->queueSize());

        $pending = $this->store->getPendingShows();
        $this->assertCount(1, $pending);
        $this->assertEquals('show-2', $pending[0]);
    }

    public function testGetPendingShows(): void
    {
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-2');
        $this->store->enqueueShow('show-3');

        $pending = $this->store->getPendingShows();

        $this->assertCount(3, $pending);
        $this->assertContains('show-1', $pending);
        $this->assertContains('show-2', $pending);
        $this->assertContains('show-3', $pending);
    }

    public function testEnqueueIsIdempotent(): void
    {
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-1');

        $this->assertEquals(1, $this->store->queueSize());

        $pending = $this->store->getPendingShows();
        $this->assertCount(1, $pending);
    }

    public function testDequeueReturnsNullWhenEmpty(): void
    {
        $result = $this->store->dequeueShow();
        $this->assertNull($result);
    }

    public function testQueueSizeAfterOperations(): void
    {
        $this->assertEquals(0, $this->store->queueSize());

        $this->store->enqueueShow('show-1');
        $this->assertEquals(1, $this->store->queueSize());

        $this->store->enqueueShow('show-2');
        $this->assertEquals(2, $this->store->queueSize());

        $dequeued = $this->store->dequeueShow();
        $this->assertEquals('show-1', $dequeued);
        $this->assertEquals(1, $this->store->queueSize());

        // completeShow on show-2 (which was never dequeued) removes it
        $this->store->completeShow('show-2');
        $this->assertEquals(0, $this->store->queueSize());
    }

    public function testClearRemovesAllJobs(): void
    {
        $this->store->enqueueShow('show-1');
        $this->store->enqueueShow('show-2');
        $this->store->enqueueShow('show-3');

        $this->assertEquals(3, $this->store->queueSize());

        $this->store->clear();

        $this->assertEquals(0, $this->store->queueSize());
        $this->assertEmpty($this->store->getPendingShows());
    }

    public function testCompleteNonExistentShowIsNoOp(): void
    {
        $this->store->enqueueShow('show-1');

        $this->store->completeShow('non-existent');

        $this->assertEquals(1, $this->store->queueSize());
        $this->assertTrue($this->store->isEnqueued('show-1'));
    }
}
