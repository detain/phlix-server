<?php

/**
 * Phlix media server component: Trickplay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Media\Streaming\Trickplay;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * The shared rules for an item's trickplay artefacts.
 *
 * The media-asset job and the backfill script both produce the same three files
 * (`sprite.jpg`, `timeline.json`, `thumbs.bif`), so the cadence, the frame cap and
 * the "is this item already done?" test live here, not in either caller. A
 * caller that drifts would otherwise re-decode items it should skip, or write an
 * archive the other caller would not have.
 *
 * @since 0.103.0
 */
final class TrickplayArtefacts
{
    /** Sprite thumbnails per item; laid out by {@see FfmpegRunner} as 6 columns by 10 rows. */
    public const SPRITE_COUNT = 60;

    /** Seconds of runtime per BIF frame, from which the frame count is derived. */
    public const BIF_TARGET_INTERVAL_SECONDS = 10;

    /**
     * Hard cap on BIF frames. The archive embeds every frame, so its size is linear
     * in this number and the device downloads it whole before scrubbing works. Past
     * a 100-minute runtime the effective interval stretches rather than the file
     * growing without bound.
     */
    public const BIF_MAX_FRAMES = 600;

    /** BIF frame width in pixels: Roku's recommended HD trickplay width. */
    public const BIF_FRAME_WIDTH = 320;

    private function __construct()
    {
    }

    /**
     * BIF frame count for an item of the given runtime.
     *
     * One frame per {@see self::BIF_TARGET_INTERVAL_SECONDS}, clamped to
     * [2, {@see self::BIF_MAX_FRAMES}]. An unknown runtime falls back to the sprite
     * count, as it always has.
     *
     * @param float $duration Runtime in seconds.
     */
    public static function bifFrameCount(float $duration): int
    {
        if ($duration <= 0) {
            return self::SPRITE_COUNT;
        }

        $count = (int) ceil($duration / self::BIF_TARGET_INTERVAL_SECONDS);

        return max(2, min(self::BIF_MAX_FRAMES, $count));
    }

    /**
     * Whether an item's directory already holds all three artefacts, each non-empty.
     *
     * A half-finished run leaves a zero-byte or missing file, which counts as not
     * complete, so the item is regenerated rather than advertised broken.
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function isComplete(string $dir): bool
    {
        foreach ([FfmpegRunner::SPRITE_FILENAME, FfmpegRunner::TIMELINE_FILENAME, BifWriter::FILENAME] as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || filesize($path) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds `thumbs.bif` from frames a trickplay pass already extracted.
     *
     * The caller owns the frame files and deletes them. A BIF needs at least two
     * frames, so a shorter list writes nothing and returns false.
     *
     * @param list<string> $frames     Frame JPEG paths, in capture order.
     * @param int          $intervalMs Milliseconds between consecutive frames.
     * @param string       $dir        The item's trickplay directory.
     *
     * @return bool True if the archive was written.
     *
     * @throws \Throwable When the frames are not valid JPEGs (from {@see BifWriter}).
     */
    public static function writeBif(array $frames, int $intervalMs, string $dir): bool
    {
        if (count($frames) < 2) {
            return false;
        }

        BifWriter::writeFromFiles($frames, $intervalMs, $dir . '/' . BifWriter::FILENAME);

        return true;
    }
}
