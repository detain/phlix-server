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
     * Whether an item's directory holds all three artefacts, each whole.
     *
     * "Whole" is checked per format, not by size alone:
     * - the sprite is non-empty;
     * - the timeline parses to exactly {@see self::SPRITE_COUNT} entries, each with
     *   a numeric time and position;
     * - the BIF has the magic, at least two frames, and an index terminator whose
     *   end offset is the file size. A truncated archive fails that offset check.
     *
     * A half-finished run therefore counts as not complete, and the item is
     * regenerated rather than advertised broken.
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function isComplete(string $dir): bool
    {
        $sprite = $dir . '/' . FfmpegRunner::SPRITE_FILENAME;
        if (!is_file($sprite) || filesize($sprite) === 0) {
            return false;
        }

        return self::timelineIsWhole($dir . '/' . FfmpegRunner::TIMELINE_FILENAME)
            && self::bifIsWhole($dir . '/' . BifWriter::FILENAME);
    }

    /**
     * Whether a timeline file parses to the full sprite grid.
     *
     * @param string $path Absolute path to timeline.json.
     */
    private static function timelineIsWhole(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return false;
        }

        $entries = json_decode($raw, true);
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== self::SPRITE_COUNT) {
            return false;
        }

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['time'], $entry['x'], $entry['y'])
                || !is_numeric($entry['time']) || !is_numeric($entry['x']) || !is_numeric($entry['y'])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a BIF file is a whole archive, judged from its header and terminator.
     *
     * The terminator entry sits at {@see BifWriter::HEADER_LENGTH} plus eight bytes
     * per frame. Its timestamp field is 0xFFFFFFFF and its offset field is the
     * one-past-the-last byte of the data, so it equals the file size only when no
     * payload was cut off.
     *
     * @param string $path Absolute path to thumbs.bif.
     */
    private static function bifIsWhole(string $path): bool
    {
        $size = is_file($path) ? @filesize($path) : false;
        $minimum = BifWriter::HEADER_LENGTH + BifWriter::INDEX_ENTRY_LENGTH;
        if ($size === false || $size < $minimum) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, BifWriter::HEADER_LENGTH);
            if (!is_string($header) || strlen($header) !== BifWriter::HEADER_LENGTH) {
                return false;
            }
            if (substr($header, 0, 8) !== BifWriter::MAGIC) {
                return false;
            }

            $count = self::uint32At($header, 12);
            if ($count < 2) {
                return false;
            }

            $terminatorAt = BifWriter::HEADER_LENGTH + BifWriter::INDEX_ENTRY_LENGTH * $count;
            if (fseek($handle, $terminatorAt) !== 0) {
                return false;
            }
            $entry = fread($handle, BifWriter::INDEX_ENTRY_LENGTH);
            if (!is_string($entry) || strlen($entry) !== BifWriter::INDEX_ENTRY_LENGTH) {
                return false;
            }

            return self::uint32At($entry, 0) === BifWriter::INDEX_TERMINATOR
                && self::uint32At($entry, 4) === $size;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads a little-endian uint32 from a byte string at an offset.
     */
    private static function uint32At(string $bytes, int $offset): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('V', substr($bytes, $offset, 4));

        return $unpacked[1];
    }

    /** Name of the per-item pass lock. Hidden, so it never reads as an artefact. */
    public const LOCK_FILENAME = '.trickplay.lock';

    /**
     * Takes an item's pass lock without waiting.
     *
     * The media-asset job and the backfill script write an item's artefacts into
     * the same directory. Each takes this lock before it generates, and skips the
     * item when the other holds it, so two passes never write the same files at
     * once. It is an flock on a file in the item directory: it works across
     * processes, and the lock is released when the handle is released.
     *
     * @param string $dir The item's trickplay directory (created if missing).
     *
     * @return \SplFileObject|null The held lock, or null when another pass holds it
     *                             (or the directory cannot be used).
     */
    public static function tryLock(string $dir): ?\SplFileObject
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        try {
            $lock = new \SplFileObject($dir . '/' . self::LOCK_FILENAME, 'c');
        } catch (\RuntimeException) {
            return null;
        }

        return $lock->flock(LOCK_EX | LOCK_NB) ? $lock : null;
    }

    /**
     * Releases a lock taken with {@see tryLock()}. Safe to call with null.
     */
    public static function unlock(?\SplFileObject $lock): void
    {
        $lock?->flock(LOCK_UN);
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
