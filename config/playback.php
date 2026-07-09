<?php

/**
 * Phlix media server component: Playback configuration.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

return [
    // Crossfade duration in seconds. When > 0, crossfade is enabled.
    // The next track will start playing when the current track has this
    // many seconds remaining, and both tracks will mix during this window.
    'crossfade_duration' => (int) ($_ENV['PHLIX_CROSSFADE_DURATION'] ?? 0),

    // Fraction of crossfade_duration spent fading OUT the current track (1.0 → 0.0).
    // The remaining (1.0 - crossfade_fade_out) is spent fading IN the next track (0.0 → 1.0).
    // Example: crossfade_duration=5, crossfade_fade_out=0.3 → current fades out over 1.5s,
    // next fades in over 3.5s (overlap of 1.5s).
    'crossfade_fade_out' => (float) ($_ENV['PHLIX_CROSSFADE_FADE_OUT'] ?? 0.3),

    // Fraction of crossfade_duration spent fading IN the next track (0.0 → 1.0).
    // Computed as (1.0 - crossfade_fade_out) when not explicitly set.
    'crossfade_fade_in' => (float) ($_ENV['PHLIX_CROSSFADE_FADE_IN'] ?? 0.3),
];
