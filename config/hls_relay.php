<?php

/**
 * HLS relay session buffer configuration.
 *
 * Bounds the in-memory segment buffer that {@see \Phlix\LiveTv\Relay\SegmentCache}
 * maintains for each relay session. Without these caps live streams
 * grow without limit and eventually exhaust worker memory.
 *
 * @package Phlix\Config
 * @since Wave 2 (post-O.7)
 */

return [
    /*
     * Maximum number of segments retained per session.
     *
     * Once exceeded, the oldest segment that is NOT currently being
     * served to a downstream client is evicted (LRU by insertion
     * timestamp).
     */
    'max_segments' => (int) (getenv('PHLIX_HLS_RELAY_MAX_SEGMENTS') ?: 6),

    /*
     * Maximum cumulative segment duration (in seconds) retained per
     * session. The effective cap is the GREATER of `max_segments`
     * count and this duration cap, so short segments cannot blow past
     * the time budget and long segments cannot starve the count budget.
     */
    'max_buffer_seconds' => (int) (getenv('PHLIX_HLS_RELAY_MAX_BUFFER_SECONDS') ?: 30),
];
