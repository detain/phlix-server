<?php

/**
 * Phlix media server component: Fs.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Fs;

/**
 * The ONE extension→MIME table the whole server reads.
 *
 * ## Why this class exists
 *
 * Four independent copies of the same knowledge used to answer "what type is
 * this file?": the Workerman static server's private `mimeFor()` (web assets),
 * {@see \Phlix\Dlna\DlnaMimeTypes::forPath()} (DLNA containers), and two
 * byte-identical `getRealMime()` finfo twins in `AvatarStorage` and
 * `ImageResizer`. Every new format therefore had to be taught in several
 * places at once, and the copies had already drifted apart in a way that is
 * load-bearing:
 *
 * - the web table serves `text/css`, fonts, `image/avif`, `application/wasm`
 *   — types a renderer never asks for;
 * - the media table serves `video/x-matroska`, `audio/flac`, `image/bmp`
 *   — types the browser bundle never ships.
 *
 * Merging the two VIEWs would change observable output (a `.mkv` under static
 * serving used to fall through to libmagic; a `.avif` on the DLNA surface used
 * to answer the `FALLBACK` and let the stream route reject with 415). That is
 * forbidden here: this class is a merge point for the STORAGE of the mapping,
 * not for its meaning. Each row declares which of the two legacy views
 * includes it ('web', 'media'), and each original output is frozen by the
 * pin matrix in `tests/Unit/Common/Fs/MimePinMatrixTest.php`.
 *
 * ## Adding a format is one line
 *
 * Append one row to {@see self::EXTENSIONS} with the flags the format belongs
 * to. The planted proof lives in the pin-matrix test; no site file needs an
 * edit, because every consumer routes through this class.
 *
 * ## The two chains, verbatim
 *
 * - {@see self::responseTypeForFile()} is the static server's historical
 *   answer: web-view extension hit, else a `mime_content_type()` sniff gated
 *   on `is_file()` (the primitive itself, kept for byte-identity — exotic
 *   hosts without the function took the generic answer and still do), else
 *   {@see self::FALLBACK}.
 * - {@see self::mediaTypeFromPath()} is the DLNA surface's historical answer:
 *   media-view extension hit, else {@see self::FALLBACK}, which the stream
 *   route reads as "not direct-playable" (415). The filesystem is never
 *   touched by this arm.
 *
 * {@see self::sniffFile()} replaces both finfo twins; note the deliberate
 * absence of `finfo_close()` explained there.
 *
 * @package Phlix\Common\Fs
 * @since 2.3.0
 */
final class Mime
{
    /**
     * The "no better answer" type: static serving's generic binary default
     * and the DLNA surface's "container not recognised" marker — the same
     * literal the four legacy sites each hardcoded independently.
     */
    public const FALLBACK = 'application/octet-stream';

    /**
     * Canonical lower-case extension → row map, union of the two legacy views.
     *
     * `web` marks membership in the static server's asset table (the view
     * {@see self::responseTypeForFile()} consults BEFORE sniffing); `media`
     * marks membership in the DLNA direct-play table (the view
     * {@see self::mediaTypeFromPath()} answers from, extension only). Keys
     * true in both views carried byte-identical MIME values in both legacy
     * tables — verified leg by leg in the pin matrix.
     *
     * @var array<string, array{mime: string, web: bool, media: bool}>
     */
    private const EXTENSIONS = [
        // Text / stylesheet / script assets (web view only). The charset
        // suffixes are part of the frozen Content-Type wire values.
        'css'   => ['mime' => 'text/css; charset=utf-8', 'web' => true, 'media' => false],
        'js'    => ['mime' => 'application/javascript; charset=utf-8', 'web' => true, 'media' => false],
        'mjs'   => ['mime' => 'application/javascript; charset=utf-8', 'web' => true, 'media' => false],
        'json'  => ['mime' => 'application/json; charset=utf-8', 'web' => true, 'media' => false],
        'map'   => ['mime' => 'application/json; charset=utf-8', 'web' => true, 'media' => false],
        'html'  => ['mime' => 'text/html; charset=utf-8', 'web' => true, 'media' => false],
        'htm'   => ['mime' => 'text/html; charset=utf-8', 'web' => true, 'media' => false],
        'txt'   => ['mime' => 'text/plain; charset=utf-8', 'web' => true, 'media' => false],
        'xml'   => ['mime' => 'application/xml; charset=utf-8', 'web' => true, 'media' => false],
        // Web platform extras (web view only).
        'svg'          => ['mime' => 'image/svg+xml', 'web' => true, 'media' => false],
        'webmanifest'  => ['mime' => 'application/manifest+json', 'web' => true, 'media' => false],
        'ico'          => ['mime' => 'image/x-icon', 'web' => true, 'media' => false],
        'avif'         => ['mime' => 'image/avif', 'web' => true, 'media' => false],
        'woff'         => ['mime' => 'font/woff', 'web' => true, 'media' => false],
        'woff2'        => ['mime' => 'font/woff2', 'web' => true, 'media' => false],
        'ttf'          => ['mime' => 'font/ttf', 'web' => true, 'media' => false],
        'otf'          => ['mime' => 'font/otf', 'web' => true, 'media' => false],
        'eot'          => ['mime' => 'application/vnd.ms-fontobject', 'web' => true, 'media' => false],
        'pdf'          => ['mime' => 'application/pdf', 'web' => true, 'media' => false],
        'wasm'         => ['mime' => 'application/wasm', 'web' => true, 'media' => false],
        // Video containers.
        'mp4'  => ['mime' => 'video/mp4', 'web' => true, 'media' => true],
        'webm' => ['mime' => 'video/webm', 'web' => true, 'media' => true],
        'm4v'  => ['mime' => 'video/mp4', 'web' => false, 'media' => true],
        'mkv'  => ['mime' => 'video/x-matroska', 'web' => false, 'media' => true],
        'avi'  => ['mime' => 'video/x-msvideo', 'web' => false, 'media' => true],
        'mov'  => ['mime' => 'video/quicktime', 'web' => false, 'media' => true],
        'wmv'  => ['mime' => 'video/x-ms-wmv', 'web' => false, 'media' => true],
        'flv'  => ['mime' => 'video/x-flv', 'web' => false, 'media' => true],
        'mpg'  => ['mime' => 'video/mpeg', 'web' => false, 'media' => true],
        'mpeg' => ['mime' => 'video/mpeg', 'web' => false, 'media' => true],
        'm2v'  => ['mime' => 'video/mpeg', 'web' => false, 'media' => true],
        'ts'   => ['mime' => 'video/mp2t', 'web' => false, 'media' => true],
        'm2ts' => ['mime' => 'video/mp2t', 'web' => false, 'media' => true],
        'mts'  => ['mime' => 'video/mp2t', 'web' => false, 'media' => true],
        '3gp'  => ['mime' => 'video/3gpp', 'web' => false, 'media' => true],
        'ogv'  => ['mime' => 'video/ogg', 'web' => false, 'media' => true],
        // Audio containers.
        'mp3'  => ['mime' => 'audio/mpeg', 'web' => true, 'media' => true],
        'm4a'  => ['mime' => 'audio/mp4', 'web' => true, 'media' => true],
        'ogg'  => ['mime' => 'audio/ogg', 'web' => true, 'media' => true],
        'm4b'  => ['mime' => 'audio/mp4', 'web' => false, 'media' => true],
        'aac'  => ['mime' => 'audio/aac', 'web' => false, 'media' => true],
        'flac' => ['mime' => 'audio/flac', 'web' => false, 'media' => true],
        'wav'  => ['mime' => 'audio/wav', 'web' => false, 'media' => true],
        'oga'  => ['mime' => 'audio/ogg', 'web' => false, 'media' => true],
        'opus' => ['mime' => 'audio/opus', 'web' => false, 'media' => true],
        'wma'  => ['mime' => 'audio/x-ms-wma', 'web' => false, 'media' => true],
        'aiff' => ['mime' => 'audio/aiff', 'web' => false, 'media' => true],
        'aif'  => ['mime' => 'audio/aiff', 'web' => false, 'media' => true],
        // Stills.
        'jpg'  => ['mime' => 'image/jpeg', 'web' => true, 'media' => true],
        'jpeg' => ['mime' => 'image/jpeg', 'web' => true, 'media' => true],
        'png'  => ['mime' => 'image/png', 'web' => true, 'media' => true],
        'gif'  => ['mime' => 'image/gif', 'web' => true, 'media' => true],
        'webp' => ['mime' => 'image/webp', 'web' => true, 'media' => true],
        'bmp'  => ['mime' => 'image/bmp', 'web' => false, 'media' => true],
        'tif'  => ['mime' => 'image/tiff', 'web' => false, 'media' => true],
        'tiff' => ['mime' => 'image/tiff', 'web' => false, 'media' => true],
    ];

    /**
     * Pure seam: resolve an extension against a given table under one view.
     *
     * Public so a test can prove the one-line law: a single appended row
     * (mime + both flags) feeds every consumer of the default table, with no
     * second edit anywhere.
     *
     * @param string $ext Lower-case extension, no dot.
     * @param array<string, array{mime: string, web: bool, media: bool}>|null $table
     *                    Resolution table; null uses {@see self::EXTENSIONS}.
     * @param bool $mediaView True asks the media (DLNA) view, false the web
     *                        (static-server) view.
     */
    public static function forExtension(string $ext, ?array $table = null, bool $mediaView = false): ?string
    {
        $row = ($table ?? self::EXTENSIONS)[$ext] ?? null;
        if ($row === null) {
            return null;
        }

        return $row[$mediaView ? 'media' : 'web'] === true ? $row['mime'] : null;
    }

    /**
     * Web-view type for a path, extension only — the static server's first arm.
     *
     * @return string|null Null means "not in the web table"; callers continue
     *                     their own fallback chain.
     */
    public static function webTypeFromPath(string $path): ?string
    {
        return self::forExtension(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }

    /**
     * Media-view type for a path — the DLNA surface's historical answer.
     *
     * Never touches the filesystem; {@see self::FALLBACK} means "container not
     * recognised" (the stream route turns it into a 415).
     */
    public static function mediaTypeFromPath(string $path): string
    {
        return self::forExtension(strtolower(pathinfo($path, PATHINFO_EXTENSION)), null, true)
            ?? self::FALLBACK;
    }

    /**
     * The static server's full historical chain, verbatim.
     *
     * Web-view extension hit (BEFORE any filesystem check — a table key with a
     * missing file still answers its type), else the guarded libmagic sniff,
     * else {@see self::FALLBACK}. The `function_exists` arm is kept because
     * exotic hosts without `mime_content_type` took the generic answer; a
     * union into one "shared sniff" would silently change them.
     */
    public static function responseTypeForFile(string $path): string
    {
        $byExt = self::webTypeFromPath($path);
        if ($byExt !== null) {
            return $byExt;
        }

        // Guard is_file(): mime_content_type() emits a "Failed to open stream"
        // warning (and PHPUnit exits non-zero on warnings) when handed a path that
        // doesn't exist — fall through to the generic type instead.
        if (function_exists('mime_content_type') && is_file($path)) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Content-sniff a file with finfo; null when the answer cannot be had.
     *
     * Replaces the byte-identical `getRealMime()` twins in `AvatarStorage`
     * and `ImageResizer`. Like them, callers must not feed it paths that do
     * not exist: `finfo_file()` answers null but emits a "Failed to open
     * stream" warning on missing input — the static server's chain
     * ({@see self::responseTypeForFile()}) guards `is_file()` for exactly that
     * reason, and the production twins only ever see freshly-written temp
     * files.
     */
    public static function sniffFile(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $raw = finfo_file($finfo, $path);
        // No finfo_close(): the finfo object is freed automatically by GC, and
        // finfo_close() is a deprecated no-op as of PHP 8.5 (its E_DEPRECATED
        // would otherwise be escalated by the error handler and abort the caller).

        if ($raw === false) {
            return null;
        }

        return $raw;
    }
}
