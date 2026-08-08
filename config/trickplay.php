<?php

/**
 * Trickplay (scrubber preview) configuration.
 *
 * ⚠ This file is deliberately tiny. Every other key it used to carry was read by
 * nothing:
 *
 * - `interval_seconds`, `grid_columns`, `grid_rows`, `thumb_width`,
 *   `thumb_height`, `image_format`, `jpeg_quality` were never loaded by any
 *   code path. `TrickplayConfig` — the class whose properties they mirrored —
 *   took its values from constructor defaults, and its only consumer
 *   (`TrickplayGenerator`) had no callers at all; S275 deleted both. The live
 *   grid is `FfmpegRunner`'s own constants: 6 columns of 160x90 thumbnails.
 * - `storage_dir` pointed at `/var/trickplay` while the producer wrote under
 *   ffmpeg's `transcode_dir` (`/var/transcodes`), so it could only ever have
 *   made artefacts unreachable. The serving directory is now derived from
 *   `config/ffmpeg.php`'s `transcode_dir` — one key, no drift.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

return [
    /**
     * Shipped default for the `trickplay.enabled` admin setting.
     *
     * ⚠ Keep this key. It is not read by any trickplay code path — the live
     * toggle is resolved at use-time from `SettingsRepository::getEffective()`
     * in `MediaAssetGenerationJob::generateTrickplaySprites()` — but it IS the
     * config default that setting resolves against, and
     * `SettingsDefaultResolvabilityTest` fails without it: a schema key with no
     * default renders in the admin UI and does nothing.
     */
    'enabled' => true,

    /**
     * Origin prefixed to trickplay URLs (`sprite.jpg`, `timeline.json`,
     * `thumbs.bif`). Empty means emit host-relative paths, which is what a
     * client behind the same origin or the relay wants.
     */
    'base_url' => '',
];
