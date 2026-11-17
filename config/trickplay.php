<?php

/**
 * Trickplay (scrubber preview) configuration.
 *
 * Both keys below are live: code reads each one.
 *
 * - `enabled` is the shipped default for the `trickplay.enabled` admin setting.
 *   The live toggle is read at use-time from SettingsRepository::getEffective()
 *   in MediaAssetGenerationJob, and SettingsDefaultResolvabilityTest fails
 *   without this default.
 * - `base_url` is the origin prefixed to trickplay URLs (see
 *   Application::getTrickplayController()).
 *
 * The tuning values are deliberately NOT keys here. They set the bytes of every
 * artefact, and clients cache those bytes, so a change must be deliberate and
 * must say so in its commit. They are therefore code constants, each with one
 * owner:
 *
 * - `TrickplayArtefacts::SPRITE_COUNT` (60 thumbnails per sprite),
 *   `TrickplayArtefacts::BIF_TARGET_INTERVAL_SECONDS` (one BIF frame per 10 s),
 *   `TrickplayArtefacts::BIF_MAX_FRAMES` (600 frames) and
 *   `TrickplayArtefacts::BIF_FRAME_WIDTH` (320 px).
 * - `FfmpegRunner::TRICKPLAY_SPRITE_COLUMNS` (6 columns),
 *   `FfmpegRunner::TRICKPLAY_THUMB_WIDTH` (160 px),
 *   `FfmpegRunner::TRICKPLAY_THUMB_HEIGHT` (90 px),
 *   `FfmpegRunner::TRICKPLAY_MARGIN` (2 px) and
 *   `FfmpegRunner::TRICKPLAY_PADDING` (1 px). These set the sheet layout.
 *
 * Two more are operational, not format, so they can change without touching a
 * client: `FfmpegRunner::TRICKPLAY_DECODE_THREADS` (4) and
 * `FfmpegRunner::TRICKPLAY_NICE_LEVEL` (19) only change how fast a pass runs and
 * what it costs the host.
 *
 * The serving directory is not a key either. It is derived from config/ffmpeg.php's
 * `transcode_dir`, which is the tree the producer writes under. An earlier
 * `storage_dir` key pointed at a different tree and could only make artefacts
 * unreachable.
 *
 * TrickplayConfigDocumentationTest checks that every constant named above exists
 * and still has the value written here.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
