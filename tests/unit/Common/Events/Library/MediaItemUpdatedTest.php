<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events\Library;

use Phlex\Common\Events\Library\MediaItemUpdated;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Common\Events\Library\MediaItemUpdated
 */
final class MediaItemUpdatedTest extends TestCase
{
    public function test_constructs_with_expected_payload(): void
    {
        $event = new MediaItemUpdated('item-1', ['title', 'year']);
        $this->assertSame('item-1', $event->mediaItemId);
        $this->assertSame(['title', 'year'], $event->changedFields);
    }

    public function test_empty_changed_fields_is_valid(): void
    {
        $event = new MediaItemUpdated('item-1', []);
        $this->assertSame([], $event->changedFields);
    }
}
