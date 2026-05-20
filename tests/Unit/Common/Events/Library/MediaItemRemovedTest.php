<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Events\Library;

use Phlix\Shared\Events\Library\MediaItemRemoved;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Shared\Events\Library\MediaItemRemoved
 */
final class MediaItemRemovedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new MediaItemRemoved('item-1', 'lib-1');
        $this->assertSame('item-1', $event->mediaItemId);
        $this->assertSame('lib-1', $event->libraryId);
    }
}
