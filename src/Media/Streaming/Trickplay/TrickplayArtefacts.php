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
     * Whether an item's directory holds all three artefacts, each whole and from one pass.
     *
     * "Whole" is checked per format, not by size alone:
     * - the sprite is non-empty;
     * - the timeline parses to exactly {@see self::SPRITE_COUNT} entries, each with
     *   a numeric time and position;
     * - the BIF has the magic, at least two frames, and an index terminator whose
     *   end offset is the file size. A truncated archive fails that offset check.
     *
     * "One pass" is checked with a generation stamp. A pass writes one random token into
     * the sprite stamp ({@see FfmpegRunner::SPRITE_GENERATION_FILENAME}), into the
     * `generation` field of every timeline entry, and, when the BIF is written, into the
     * BIF stamp ({@see BifWriter::GENERATION_FILENAME}). All of them must carry the same
     * token. Each publish renames its stamp before its commit point, so a crash between
     * renames leaves a mismatch. isComplete() reports such a torn set as not complete, and the
     * backfill script and MediaAssetBackfill::reenqueueLibrary() then regenerate it. The
     * controller does not consult
     * isComplete(): it serves whichever of the three files exist (see
     * {@see \Phlix\Media\Streaming\Trickplay\TrickplayController::resolveSpritePath()}), so a torn
     * set is served until the next regeneration replaces it.
     *
     * The bound: an item written before stamps existed has no `generation` field and no
     * stamp file. It is accepted when its three files are whole, as before stamps. Two
     * legacy artefacts from different passes cannot be told apart from the files, and no
     * stamp can be added to them afterwards, so only a regeneration removes that case.
     *
     * A half-finished run therefore counts as not complete. The backfill regenerates such an
     * item; the controller still serves the files that exist (see above).
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function isComplete(string $dir): bool
    {
        $sprite = $dir . '/' . FfmpegRunner::SPRITE_FILENAME;
        if (!is_file($sprite) || filesize($sprite) === 0) {
            return false;
        }

        $generation = self::timelineGeneration($dir . '/' . FfmpegRunner::TIMELINE_FILENAME);
        if ($generation === false) {
            return false;
        }

        $spriteStamp = self::readStamp($dir . '/' . FfmpegRunner::SPRITE_GENERATION_FILENAME);
        $bifStamp = self::readStamp($dir . '/' . BifWriter::GENERATION_FILENAME);
        if ($generation === null) {
            // A legacy timeline has no generation, so no stamp may exist either.
            $stampsAgree = $spriteStamp === null && $bifStamp === null;
        } else {
            $stampsAgree = $spriteStamp === $generation && $bifStamp === $generation;
        }

        return $stampsAgree && self::bifIsWhole($dir . '/' . BifWriter::FILENAME);
    }

    /**
     * Reads a timeline and reports the generation its entries carry.
     *
     * @param string $path Absolute path to timeline.json.
     *
     * @return string|null|false The generation all entries share; null for a legacy
     *         timeline whose entries carry no generation field; false when the file is
     *         not a whole grid, or its entries disagree (some tagged, or two tokens).
     */
    private static function timelineGeneration(string $path): string|null|false
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

        $generation = null;
        $tagged = 0;
        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['time'], $entry['x'], $entry['y'])
                || !is_numeric($entry['time']) || !is_numeric($entry['x']) || !is_numeric($entry['y'])
            ) {
                return false;
            }

            if (!array_key_exists('generation', $entry)) {
                continue;
            }
            $value = $entry['generation'];
            if (!is_string($value) || preg_match(self::GENERATION_PATTERN, $value) !== 1) {
                return false;
            }
            if ($generation !== null && $generation !== $value) {
                return false;
            }
            $generation = $value;
            $tagged++;
        }

        // Some entries tagged and some not is a torn file, not a legacy one.
        return $tagged === 0 || $tagged === count($entries) ? $generation : false;
    }

    /**
     * Reads a generation stamp file.
     *
     * @param string $path Absolute path to the stamp file.
     *
     * @return string|null Null when the file is absent. Otherwise the trimmed content, which
     *         is not a valid token when the file is damaged, so it never matches.
     */
    private static function readStamp(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * Renames a stamp file's temporary onto its final name, after checking the bytes.
     *
     * @param string $path       Final stamp path.
     * @param string $generation The token to store.
     */
    private static function publishStamp(string $path, string $generation): bool
    {
        $temp = dirname($path) . '/' . FfmpegRunner::TEMP_FILE_PREFIX . basename($path);
        $bytes = @file_put_contents($temp, $generation);
        if ($bytes !== strlen($generation) || !@rename($temp, $path)) {
            if (is_file($temp)) {
                @unlink($temp);
            }

            return false;
        }

        return true;
    }

    /** A generation token: 8 random bytes in lower-case hex. */
    private const GENERATION_PATTERN = '/^[0-9a-f]{16}$/';

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
     * Name of the file that records a backfill generation that failed.
     *
     * The backfill reads it before it spends a decode on the item. Without it, an
     * item whose BIF can never be written is re-decoded on every run for nothing.
     * Deleting the file makes the item eligible again.
     */
    public const FAILED_MARKER_FILENAME = 'generation-failed.json';

    /**
     * Records that generation for an item failed, with the reason and the time (UTC).
     *
     * @param string $dir    The item's trickplay directory (created if missing).
     * @param string $reason Short human-readable reason, for the operator.
     */
    public static function markFailed(string $dir, string $reason): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // The reason can carry a message from a thrown exception, which may hold bytes that are
        // not valid UTF-8. Substitute them, so the marker is written and the item is still skipped.
        $json = json_encode(
            ['failed_at' => gmdate('c'), 'reason' => $reason],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
        @file_put_contents($dir . '/' . self::FAILED_MARKER_FILENAME, $json);
    }

    /**
     * Whether an earlier generation for the item recorded a failure.
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function hasFailedMarker(string $dir): bool
    {
        return is_file($dir . '/' . self::FAILED_MARKER_FILENAME);
    }

    /**
     * Why the backfill must not generate an item, or null when it should.
     *
     * An item is skipped when its generation failed (generation-failed.json) or when its BIF
     * failed (bif-failed.json). Both record a failure that repeats on every run, so an item
     * that carries either is left alone until an operator deletes the marker. When both are
     * present the generation marker is reported.
     *
     * @param string $dir The item's trickplay directory.
     *
     * @return string|null The marker file name that skips the item, or null to generate it.
     */
    public static function backfillSkipMarker(string $dir): ?string
    {
        if (self::hasFailedMarker($dir)) {
            return self::FAILED_MARKER_FILENAME;
        }

        return is_file($dir . '/' . self::BIF_FAILED_MARKER_FILENAME) ? self::BIF_FAILED_MARKER_FILENAME : null;
    }

    /**
     * Removes a failure marker, if present (for example once the item is complete).
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function clearFailedMarker(string $dir): void
    {
        $path = $dir . '/' . self::FAILED_MARKER_FILENAME;
        if (is_file($path)) {
            @unlink($path);
        }
    }

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
     * @param string|null  $generation The generation of the pass that produced the frames
     *                                 (FfmpegRunner's result `generation`). The archive is
     *                                 stamped with it, so isComplete() accepts it only
     *                                 beside a sprite and timeline of the same pass. Null
     *                                 writes an unstamped archive and removes any old stamp.
     *
     * @return bool True if the archive was written and, when stamped, its stamp published.
     *
     * @throws \Throwable When the frames are not valid JPEGs (from {@see BifWriter}).
     */
    public static function writeBif(array $frames, int $intervalMs, string $dir, ?string $generation = null): bool
    {
        if (count($frames) < 2) {
            return false;
        }

        $stampPath = $dir . '/' . BifWriter::GENERATION_FILENAME;
        if ($generation === null && is_file($stampPath)) {
            // An unstamped archive must not keep the stamp of the one it replaces.
            @unlink($stampPath);
        }

        BifWriter::writeFromFiles($frames, $intervalMs, $dir . '/' . BifWriter::FILENAME);

        if ($generation === null) {
            return true;
        }

        // The stamp is renamed after the archive it describes, so a stamp that matches a
        // generation implies the archive of that generation is already in place.
        return self::publishStamp($stampPath, $generation);
    }

    /**
     * Name of the file that records that the BIF of the latest generation failed.
     *
     * Written when the sprite and timeline were published but the BIF was not. The backfill
     * skips an item that carries it, as it does for {@see self::FAILED_MARKER_FILENAME}, so a
     * deterministic BIF failure is not decoded again on every run and cannot use up a
     * --limit ahead of later items. Deleting the file makes the item eligible again. The
     * in-app job writes and clears the same marker.
     */
    public const BIF_FAILED_MARKER_FILENAME = 'bif-failed.json';

    /**
     * Records that the BIF of the item's latest generation failed, with the reason (UTC).
     *
     * @param string $dir    The item's trickplay directory (created if missing).
     * @param string $reason Short human-readable reason, for the operator.
     */
    public static function markBifFailed(string $dir, string $reason): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode(['failed_at' => gmdate('c'), 'reason' => $reason], JSON_THROW_ON_ERROR);
        @file_put_contents($dir . '/' . self::BIF_FAILED_MARKER_FILENAME, $json);
    }

    /**
     * Removes the BIF failure marker, if present (once a BIF has been written).
     *
     * @param string $dir The item's trickplay directory.
     */
    public static function clearBifFailedMarker(string $dir): void
    {
        $path = $dir . '/' . self::BIF_FAILED_MARKER_FILENAME;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
