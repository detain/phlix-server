<?php

declare(strict_types=1);

namespace Phlex\Common\Events;

/**
 * Common base for all Phlex PSR-14 events.
 *
 * Provides a single readonly `timestamp` (UNIX epoch seconds) captured at
 * construction time so listeners can correlate dispatch order without
 * having to add their own clock plumbing. Concrete events are expected to
 * declare their payload as additional `public readonly` constructor
 * properties — events are immutable by PSR-14 convention.
 *
 * Subclasses MUST call `parent::__construct()` so the timestamp is
 * populated. They MUST NOT add mutator methods or write to inherited
 * properties.
 *
 * @package Phlex\Common\Events
 * @since 0.10.0
 */
abstract class AbstractEvent
{
    /**
     * UNIX timestamp (seconds since epoch) at which the event was
     * constructed. Captured here so dispatchers and listeners always see
     * the same value regardless of when a listener actually runs.
     */
    public readonly int $timestamp;

    /**
     * Capture the construction timestamp.
     *
     * @since 0.10.0
     */
    public function __construct()
    {
        $this->timestamp = time();
    }
}
