<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use Phlex\Plugins\EventNameMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\EventNameMap
 */
final class EventNameMapTest extends TestCase
{
    public function test_fromAlias_returns_fqcn_for_known_alias(): void
    {
        $this->assertSame(
            \Phlex\Common\Events\Playback\PlaybackStarted::class,
            EventNameMap::fromAlias('phlex.playback.started'),
        );
    }

    public function test_fromAlias_returns_null_for_unknown_alias(): void
    {
        $this->assertNull(EventNameMap::fromAlias('phlex.nonsense.event'));
    }

    public function test_toAlias_returns_alias_for_known_fqcn(): void
    {
        $this->assertSame(
            'phlex.user.logged_in',
            EventNameMap::toAlias(\Phlex\Common\Events\Auth\UserLoggedIn::class),
        );
    }

    public function test_toAlias_returns_null_for_unknown_fqcn(): void
    {
        $this->assertNull(EventNameMap::toAlias(\stdClass::class));
    }

    public function test_aliases_returns_sorted_map(): void
    {
        $aliases = EventNameMap::aliases();
        $keys = array_keys($aliases);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys, 'aliases() must be sorted by alias.');
        $this->assertCount(12, $aliases, 'Phase A.4 expects exactly 12 alias entries.');
    }

    public function test_every_event_class_has_a_round_trip_alias(): void
    {
        foreach (EventNameMap::aliases() as $alias => $fqcn) {
            $this->assertSame($fqcn, EventNameMap::fromAlias($alias));
            $this->assertSame($alias, EventNameMap::toAlias($fqcn));
            $this->assertTrue(class_exists($fqcn), sprintf('Event class %s must exist.', $fqcn));
        }
    }
}
