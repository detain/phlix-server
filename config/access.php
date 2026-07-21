<?php

declare(strict_types=1);

/**
 * Access-control configuration.
 *
 * Boot-time defaults for the per-profile access limits. Admin-editable
 * overrides are layered on top via the server-settings store
 * ({@see \Phlix\Admin\SettingsRepository}); the *effective* value is the
 * override when present, else the default declared here.
 *
 * @since 1.3.0
 */

return [
    /**
     * How many streams one profile may play at the same time, when that
     * profile has no explicit entry in `profile_stream_limits`.
     *
     * Addressed by the dotted setting key `access.default_concurrent_streams`.
     * Read at PLAYBACK time by
     * {@see \Phlix\Access\StreamSessionService::defaultConcurrentStreams()},
     * so a change applies immediately and server-wide.
     *
     * This is not merely a seed for new profiles: nothing writes a
     * `profile_stream_limits` row at profile creation — the only writer is
     * `StreamSessionService::updateStreamLimit()`, reached solely from the
     * admin API — so this value is what every profile an administrator has not
     * explicitly configured actually runs on.
     *
     * Clamped to `MIN_CONCURRENT_STREAMS`..`MAX_CONCURRENT_STREAMS` in code, so
     * a 0 here cannot deny playback to every unconfigured profile.
     */
    'default_concurrent_streams' => 1,
];
