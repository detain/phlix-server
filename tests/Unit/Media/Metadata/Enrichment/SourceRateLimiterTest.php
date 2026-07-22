<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Enrichment;

use Phlix\Media\Metadata\Enrichment\SourceRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Enrichment\SourceRateLimiter
 */
final class SourceRateLimiterTest extends TestCase
{
    public function testSourceIsDueUntilMarkedThenNotUntilIntervalElapses(): void
    {
        $now = 500.0;
        $limiter = new SourceRateLimiter(
            ['omdb' => 90],
            null,
            static function () use (&$now): float {
                return $now;
            }
        );

        // Never dispatched → due.
        $this->assertTrue($limiter->due('omdb'));

        $limiter->mark('omdb');
        // Immediately after → NOT due (inside the 90s window).
        $this->assertFalse($limiter->due('omdb'));

        // Just short of the interval → still not due.
        $now += 89.0;
        $this->assertFalse($limiter->due('omdb'));

        // At/after the interval → due again.
        $now += 1.0;
        $this->assertTrue($limiter->due('omdb'));
    }

    public function testDistinctSourcesAreThrottledIndependently(): void
    {
        $now = 0.0;
        $limiter = new SourceRateLimiter(
            ['omdb' => 90, 'anidb' => 4],
            null,
            static function () use (&$now): float {
                return $now;
            }
        );

        $limiter->mark('omdb');
        // anidb was never marked → still due even though omdb is throttled.
        $this->assertFalse($limiter->due('omdb'));
        $this->assertTrue($limiter->due('anidb'));
    }

    public function testIntervalClampedUpToFloorAndDefaultApplied(): void
    {
        // A 0s request must be clamped to the 1s courtesy floor.
        $limiter = new SourceRateLimiter(['omdb' => 0], 0.0);
        $this->assertSame(1.0, $limiter->intervalFor('omdb'));

        // An unnamed source falls back to the built-in default (2s here since
        // no explicit default was configured and 0.0 clamps to the 1s floor…),
        // but an explicit default below the floor is also clamped.
        $this->assertSame(1.0, $limiter->intervalFor('totally-unknown-source'));
    }

    public function testBuiltinDefaultsUsedWhenSourceNotConfigured(): void
    {
        $limiter = new SourceRateLimiter();
        // Built-in conservative defaults (quota safety) apply with no config.
        $this->assertSame(90.0, $limiter->intervalFor('omdb'));
        $this->assertSame(4.0, $limiter->intervalFor('anidb'));
        $this->assertSame(2.0, $limiter->intervalFor('myanimelist'));
        // Unknown source → built-in default (2.0).
        $this->assertSame(2.0, $limiter->intervalFor('mystery'));
    }
}
