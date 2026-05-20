<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Library;

use Phlix\Shared\Events\Library\LibraryScanCompleted;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Library\LibraryScanCompleted
 */
final class LibraryScanCompletedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new LibraryScanCompleted(
            libraryId: 'lib-1',
            itemsAdded: 3,
            itemsUpdated: 1,
            itemsRemoved: 2,
            durationMs: 1500,
        );

        $this->assertSame('lib-1', $event->libraryId);
        $this->assertSame(3, $event->itemsAdded);
        $this->assertSame(1, $event->itemsUpdated);
        $this->assertSame(2, $event->itemsRemoved);
        $this->assertSame(1500, $event->durationMs);
    }

    public function test_zero_counts_are_valid(): void
    {
        $event = new LibraryScanCompleted('lib', 0, 0, 0, 0);
        $this->assertSame(0, $event->itemsAdded);
    }
}
