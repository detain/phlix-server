<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\SubtitleSourceRegistry;
use Phlix\Tests\Unit\Media\Subtitles\Fakes\FakeSubtitleSource;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Subtitles\SubtitleSourceRegistry
 */
final class SubtitleSourceRegistryTest extends TestCase
{
    public function testRegisterIsIdempotentAndDeregisterRemoves(): void
    {
        $registry = new SubtitleSourceRegistry();
        $this->assertSame([], $registry->all());

        $a = new FakeSubtitleSource('opensubtitles', 0);
        $registry->register($a);
        $this->assertTrue($registry->has('opensubtitles'));
        $this->assertSame($a, $registry->get('opensubtitles'));
        $this->assertCount(1, $registry->all());

        // Re-registering the same name REPLACES (never grows the map).
        $a2 = new FakeSubtitleSource('opensubtitles', 5);
        $registry->register($a2);
        $this->assertCount(1, $registry->all());
        $this->assertSame($a2, $registry->get('opensubtitles'));

        $registry->deregister('opensubtitles');
        $this->assertFalse($registry->has('opensubtitles'));
        $this->assertCount(0, $registry->all(), 'no leak after deregister');
    }

    public function testDeregisterInstanceRemovesByName(): void
    {
        $registry = new SubtitleSourceRegistry();
        $source = new FakeSubtitleSource('subscene', 1);
        $registry->register($source);

        $registry->deregisterInstance($source);
        $this->assertSame([], $registry->all());
    }

    public function testByPriorityHonorsIntrinsicPriorityThenName(): void
    {
        $registry = new SubtitleSourceRegistry();
        // Deliberately register out of priority order.
        $registry->register(new FakeSubtitleSource('podnapisi', 10));
        $registry->register(new FakeSubtitleSource('opensubtitles', 0));
        $registry->register(new FakeSubtitleSource('subscene', 5));

        $names = array_map(
            static fn ($s): string => $s->getName(),
            $registry->byPriority(),
        );

        // Lower getPriority() first (0, 5, 10).
        $this->assertSame(['opensubtitles', 'subscene', 'podnapisi'], $names);
    }

    public function testByPriorityPinsAdminOrderFirstThenIntrinsic(): void
    {
        $registry = new SubtitleSourceRegistry();
        $registry->register(new FakeSubtitleSource('opensubtitles', 0));
        $registry->register(new FakeSubtitleSource('subscene', 5));
        $registry->register(new FakeSubtitleSource('podnapisi', 10));

        // Admin pins podnapisi + subscene first, in that order; opensubtitles
        // (not pinned) falls to the back despite its lower intrinsic priority.
        $names = array_map(
            static fn ($s): string => $s->getName(),
            $registry->byPriority(['podnapisi', 'subscene']),
        );

        $this->assertSame(['podnapisi', 'subscene', 'opensubtitles'], $names);
    }

    public function testByPrioritySkipsUnknownPinnedNames(): void
    {
        $registry = new SubtitleSourceRegistry();
        $registry->register(new FakeSubtitleSource('opensubtitles', 0));

        $names = array_map(
            static fn ($s): string => $s->getName(),
            $registry->byPriority(['not-installed', 'opensubtitles']),
        );

        $this->assertSame(['opensubtitles'], $names);
    }

    public function testByPriorityEmptyRegistryYieldsEmptyList(): void
    {
        $this->assertSame([], (new SubtitleSourceRegistry())->byPriority(['opensubtitles']));
    }
}
