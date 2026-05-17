<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Library;

use Phlex\Shared\Events\Library\LibraryScanStarted;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Shared\Events\Library\LibraryScanStarted
 */
final class LibraryScanStartedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new LibraryScanStarted('lib-1', 'Movies', '/mnt/movies');
        $this->assertSame('lib-1', $event->libraryId);
        $this->assertSame('Movies', $event->libraryName);
        $this->assertSame('/mnt/movies', $event->path);
    }
}
