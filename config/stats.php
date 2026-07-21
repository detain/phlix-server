<?php

declare(strict_types=1);

/**
 * Usage-statistics configuration.
 *
 * These statistics are entirely LOCAL: they are written to this server's own
 * `stats_*` tables and are read back only by the admin dashboard. Nothing here
 * is transmitted off the machine — this is not phone-home telemetry, and
 * switching it off is a performance/retention choice rather than a privacy one.
 *
 * The dotted setting key is `stats.enabled` (the `stats` file segment + the
 * `enabled` array key), declared in the shared `server-settings.schema.json`.
 *
 * @since 1.3.0
 */

return [
    /**
     * Record playback, library-change, user-activity and storage statistics.
     *
     * Enforced centrally in {@see \Phlix\Stats\StatsCollector}: every public
     * `record*()` method early-returns when this is false, so a single switch
     * covers all ~52 call sites rather than each of them needing its own guard.
     *
     * Turning this OFF stops the writes and therefore blanks the admin
     * dashboard's activity and storage cards — including the storage snapshot
     * the background timer records. Existing rows are left alone.
     */
    'enabled' => true,
];
