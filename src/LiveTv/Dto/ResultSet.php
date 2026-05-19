<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Dto;

/**
 * Minimal abstract contract for a database query result used throughout the
 * LiveTv module.
 *
 * The LiveTv code (Recorder, RecordingScheduler, SeriesRuleManager,
 * RecordingDeduplicator, GuideManager) historically treats values returned
 * from {@see \Workerman\MySQL\Connection::query()} as objects with a row
 * count and a `fetch()` method. The Workerman method itself is typed
 * `mixed`, which prevents static analysis from verifying these accesses.
 *
 * This abstract class documents the shape the LiveTv test suite mocks
 * supply and that production helpers narrow against. PHPStan can then
 * resolve `num_rows` and `fetch()` via `instanceof` checks instead of
 * cast-to-object workarounds.
 *
 * Anonymous mock classes used in the LiveTv unit tests `extends ResultSet`
 * so phpstan recognises the property and method types.
 *
 * @since Wave 5a (post-O.7)
 */
abstract class ResultSet
{
    /**
     * Row count surfaced by the underlying driver.
     */
    public int $num_rows = 0;

    /**
     * Fetch the next row of the result.
     *
     * @return array<string, mixed>|false An associative row, or `false` when
     *                                    the cursor is exhausted.
     */
    abstract public function fetch(): array|false;
}
