<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Library;

use Phlex\Common\Events\Library\MediaItemAdded;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Library\MediaItemAdded
 */
final class MediaItemAddedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new MediaItemAdded('item-1', 'lib-1', '/mnt/x.mkv', 'movie');
        $this->assertSame('item-1', $event->mediaItemId);
        $this->assertSame('lib-1', $event->libraryId);
        $this->assertSame('/mnt/x.mkv', $event->path);
        $this->assertSame('movie', $event->type);
    }
}
