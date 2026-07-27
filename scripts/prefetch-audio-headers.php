#!/usr/bin/env php
<?php

/**
 * Music-scan read-ahead reader — one member of {@see \Phlix\Media\Music\MusicScanPrefetcher}'s pool.
 *
 * Reads NUL-delimited absolute paths from STDIN and, for each one, reads exactly
 * the byte ranges getID3 is about to ask for, then discards them. Its only
 * product is a warm page cache: it parses nothing, writes nothing, and reports
 * nothing back. Exits when STDIN reaches EOF, which is how the pool shuts it down
 * (and what happens by itself if the parent worker is killed).
 *
 * ## Why a separate process at all
 *
 * The scan is latency-bound on a remote single spindle, and 4 concurrent cold
 * `open()`s measured **1.73x** against 1 (8 measured 0.59x — see
 * {@see \Phlix\Media\Music\MusicScanPrefetcher} for the table and the cap). There
 * is no in-process way to get that concurrency on this runtime:
 * `SWOOLE_HOOK_FILE` is excluded from `SwooleRuntime::SAFE_HOOK_NAMES` because
 * it crashed workers, and there is no `ext-parallel`/`ext-pthreads`. So the
 * concurrency is processes.
 *
 * ## Which ranges, and why those
 *
 * Measured getID3 read pattern per MP3 (568 KB across ~60 `fread`/`fseek` calls
 * in 4 widely separated regions):
 *
 *  - the 10-byte ID3v2 header, then **the entire ID3v2 frame region in ONE
 *    contiguous `fread($sizeofframes)`** (`module.tag.id3v2.php:139`) — ≈404 KB
 *    of it cover art that Phlix immediately discards;
 *  - `fread(32774)` at the audio data offset (`getid3.php:709`);
 *  - `fread(min(128 * 1024, …))` in the MPEG frame scan
 *    (`module.audio.mp3.php:1468`);
 *  - four separate probes near END OF FILE (id3v1, APE, Lyrics3, 2 GB check).
 *
 * So: one leading range that covers the declared ID3v2 tag plus the audio-header
 * window, and one trailing range that covers all the end-of-file probes at once.
 * The ID3v2 length is decoded from the file's own 10-byte header rather than
 * guessed, so a file with no cover art is not over-read.
 *
 * ⚠ **This must never read MORE than the scanner would.** Over-reading would
 * add wire bytes on the very mount the step exists to unburden. That is why the
 * leading range is derived from the tag header and capped, not fixed at some
 * comfortable megabyte.
 *
 * ## Failure handling
 *
 * Every error is swallowed. An unreadable, vanished, or truncated file simply
 * does not get warmed, and the scanner reads it as it always did. This program
 * has no way to make a scan wrong.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * Bytes read past the end of the ID3v2 tag, covering `getid3.php:709`'s
 * `fread(32774)` at the audio data offset and `module.audio.mp3.php:1468`'s
 * `fread(min(128 * 1024, …))` frame scan, plus the 51 x `fread(226)` frame
 * header checks that follow them.
 */
const PREFETCH_AUDIO_WINDOW = 192 * 1024;

/**
 * Leading bytes read for a file with no ID3v2 tag at all (FLAC/Vorbis comments,
 * an MP4 `moov` at the head, WAV `LIST`/`INFO`). Same order as the MP3 window.
 */
const PREFETCH_HEAD_DEFAULT = 192 * 1024;

/**
 * Trailing bytes read, covering getID3's four end-of-file probes in one range:
 * ID3v1 (last 128 B), APE footer (last 32 B, then its declared length),
 * Lyrics3 (last ~137 B) and the 2 GB check.
 */
const PREFETCH_TAIL_BYTES = 8 * 1024;

/** Ceiling on the leading range, so a pathological tag cannot pull a whole album side. */
const PREFETCH_HEAD_MAX = 1024 * 1024;

/** Read granularity. Matches getID3's own `FREAD_BUFFER_SIZE`. */
const PREFETCH_CHUNK = 32768;

/**
 * Reads `$length` bytes from the current position and throws them away.
 *
 * @param resource $handle Open file handle.
 * @param int      $length Bytes to consume.
 *
 * @return void
 */
function prefetch_consume($handle, int $length): void
{
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(PREFETCH_CHUNK, $remaining));
        if ($chunk === false || $chunk === '') {
            return;
        }
        $remaining -= strlen($chunk);
    }
}

/**
 * Decodes the declared ID3v2 tag length from a 10-byte header, or 0 when the
 * header is not an ID3v2 header.
 *
 * The size is a 28-bit "syncsafe" integer: four bytes, high bit of each unused.
 *
 * @param string $header First 10 bytes of the file.
 *
 * @return int Total tag length including the 10-byte header, or 0.
 */
function prefetch_id3v2_length(string $header): int
{
    if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
        return 0;
    }

    $bytes = array_map('ord', str_split(substr($header, 6, 4)));
    if (count($bytes) !== 4) {
        return 0;
    }

    $size = 0;
    foreach ($bytes as $byte) {
        if (($byte & 0x80) !== 0) {
            // Not syncsafe — a malformed header. Do not trust the length.
            return 0;
        }
        $size = ($size << 7) | $byte;
    }

    // + the 10-byte header, + a possible 10-byte footer.
    return $size + 20;
}

/**
 * Warms the page cache for one audio file.
 *
 * @param string $path Absolute path.
 *
 * @return void
 */
function prefetch_file(string $path): void
{
    if ($path === '' || !is_file($path)) {
        return;
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return;
    }

    try {
        $header = fread($handle, 10);
        $head = PREFETCH_HEAD_DEFAULT;

        if (is_string($header) && $header !== '') {
            $tagLength = prefetch_id3v2_length($header);
            if ($tagLength > 0) {
                $head = min(PREFETCH_HEAD_MAX, $tagLength + PREFETCH_AUDIO_WINDOW);
            }
        }

        // The 10 header bytes are already consumed.
        prefetch_consume($handle, max(0, $head - 10));

        // One trailing range for all four end-of-file probes. Seek from the end so
        // no filesize() round trip is needed.
        if (@fseek($handle, -PREFETCH_TAIL_BYTES, SEEK_END) === 0) {
            prefetch_consume($handle, PREFETCH_TAIL_BYTES);
        }
    } catch (Throwable) {
        // Nothing to report: a file this program cannot warm is a file the
        // scanner reads for itself.
    } finally {
        @fclose($handle);
    }
}

$stdin = defined('STDIN') ? STDIN : fopen('php://stdin', 'rb');
if (!is_resource($stdin)) {
    exit(0);
}

$buffer = '';

while (!feof($stdin)) {
    $chunk = fread($stdin, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }

    $buffer .= $chunk;

    while (($nul = strpos($buffer, "\0")) !== false) {
        $path = substr($buffer, 0, $nul);
        $buffer = substr($buffer, $nul + 1);
        prefetch_file($path);
    }

    // A path longer than any real filesystem allows means the stream is not
    // carrying what this program expects. Drop it rather than grow forever.
    if (strlen($buffer) > 65536) {
        $buffer = '';
    }
}

exit(0);
