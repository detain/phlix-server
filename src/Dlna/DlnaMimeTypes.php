<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

/**
 * The ONE MIME table the DLNA surface uses.
 *
 * ## Why this class exists
 *
 * A DLNA renderer decides whether it can play a resource by comparing the
 * `<res protocolInfo="http-get:*:{mime}:…">` advertised in the Browse response
 * with the `Content-Type` of the bytes it then fetches. If the two disagree the
 * renderer either refuses the item or starts a decode it cannot finish — and
 * both values were derived from SEPARATE, silently-divergent copies of the same
 * extension table: {@see LibraryBridge::determineMimeType()} and
 * {@see ContentDirectory}'s private `getMimeType()`. Adding the S52 stream route
 * would have made a third copy.
 *
 * So both the served `Content-Type` (S52, {@see \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController})
 * and the advertised `protocolInfo` (S53) read this class instead.
 *
 * ## Two entry points, deliberately
 *
 * - {@see self::forPath()} answers *only* from the file extension. A
 *   {@see self::FALLBACK} result means "this is not a container we recognise",
 *   which is how the stream route decides to answer `415` rather than serve
 *   bytes under a guessed type.
 * - {@see self::forItem()} is the row-level resolution used when building DIDL:
 *   an explicit `mime_type` wins, then the extension, then a coarse
 *   media-type fallback. That RESOLUTION ORDER is {@see LibraryBridge}'s
 *   historical one, unchanged.
 *
 * ## The table is a SUPERSET, so some DIDL mime values changed
 *
 * {@see self::EXTENSION_MAP} is deliberately NOT byte-identical to the table
 * {@see LibraryBridge::determineMimeType()} used to own: that one listed only
 * mp4/m4v/mkv/webm/avi + mp3/aac/flac/wav/ogg + jpg/jpeg/png/gif, and everything
 * else fell through to the coarse `type` arm. About twenty containers therefore
 * now resolve to their real type instead of a guess — `mov`, `wmv`, `flv`, `mpg`,
 * `mpeg`, `m2v`, `ts`, `m2ts`, `mts`, `3gp`, `ogv`, `m4a`, `m4b`, `opus`, `wma`,
 * `aiff`, `aif`, `oga`, `bmp`, `webp`, `tif`, `tiff` — so e.g. a `.mov` typed
 * `movie` reports `video/quicktime` rather than `video/mp4`, and a `.m4a` typed
 * `music` reports `audio/mp4` rather than `audio/mpeg`. That is a visible change
 * in the Browse response and it is an intentional improvement (the old values were
 * wrong, and a renderer that trusts them fails mid-decode), but it is a change:
 * nothing pinned the old values, and it is called out in the CHANGELOG.
 *
 * The `type` fallback arms are deliberately NOT exhaustive over the 13-member
 * `media_items.type` ENUM here — making them exhaustive (and dropping the dead
 * `'image'` alias, which the scanner never emits; the ENUM member is `photo`)
 * belongs to S53 along with the rest of `getProtocolInfo()`. They only ever fire
 * for a path whose extension is unknown, which the stream route rejects anyway.
 *
 * @package Phlix\Dlna
 * @since 1.7.0
 */
final class DlnaMimeTypes
{
    /**
     * The "I do not recognise this container" answer.
     *
     * Treated by the stream route as NOT direct-playable: serving an unknown
     * container as `application/octet-stream` (or, worse, guessing `video/mp4`
     * from the row's `type`) makes a renderer fail mid-decode instead of
     * reporting an unsupported format.
     */
    public const FALLBACK = 'application/octet-stream';

    /**
     * Canonical lower-case extension → MIME map.
     *
     * Every value is a `video/`, `audio/` or `image/` type, so "the extension
     * is known" and "the bytes are a media container" are the same question —
     * see {@see self::isMediaType()}.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MAP = [
        // Video containers.
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'mkv'  => 'video/x-matroska',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
        'mov'  => 'video/quicktime',
        'wmv'  => 'video/x-ms-wmv',
        'flv'  => 'video/x-flv',
        'mpg'  => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'm2v'  => 'video/mpeg',
        'ts'   => 'video/mp2t',
        'm2ts' => 'video/mp2t',
        'mts'  => 'video/mp2t',
        '3gp'  => 'video/3gpp',
        'ogv'  => 'video/ogg',
        // Audio containers.
        'mp3'  => 'audio/mpeg',
        'm4a'  => 'audio/mp4',
        'm4b'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'oga'  => 'audio/ogg',
        'opus' => 'audio/opus',
        'wma'  => 'audio/x-ms-wma',
        'aiff' => 'audio/aiff',
        'aif'  => 'audio/aiff',
        // Stills.
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'bmp'  => 'image/bmp',
        'webp' => 'image/webp',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    /**
     * Resolve a MIME type from a file path's extension ALONE.
     *
     * @param string $path Any path (absolute or relative); only its extension
     *                     is read, the filesystem is never touched.
     *
     * @return string A concrete media MIME type, or {@see self::FALLBACK} when
     *                the extension is not one this server direct-plays.
     */
    public static function forPath(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return self::EXTENSION_MAP[strtolower($extension)] ?? self::FALLBACK;
    }

    /**
     * Resolve a MIME type for a `media_items` row.
     *
     * Order: an explicit `mime_type` column/metadata value, then the path
     * extension, then a coarse fallback keyed on the row's `type`.
     *
     * @param array<string, mixed> $item A hydrated `media_items` row.
     */
    public static function forItem(array $item): string
    {
        $explicit = $item['mime_type'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $path = $item['path'] ?? null;
        $fromPath = is_string($path) ? self::forPath($path) : self::FALLBACK;
        if ($fromPath !== self::FALLBACK) {
            return $fromPath;
        }

        $type = is_string($item['type'] ?? null) ? $item['type'] : '';

        // LibraryBridge's historical last-resort arm, kept as it was. It now fires
        // far less often, because EXTENSION_MAP above is a superset of the table
        // that used to precede it (see the class docblock). `'image'` is a legacy
        // alias the scanner never emits (the ENUM member is `photo`) and is
        // removed in S53 together with getProtocolInfo()'s dead arm.
        return match ($type) {
            'video', 'movie' => 'video/mp4',
            'audio', 'music' => 'audio/mpeg',
            'image', 'photo' => 'image/jpeg',
            default => self::FALLBACK,
        };
    }

    /**
     * Whether a MIME type names actual media (`video/`, `audio/`, `image/`).
     *
     * Used to reject a junk/hand-edited `mime_type` value before it reaches a
     * renderer as a `Content-Type`.
     */
    public static function isMediaType(string $mime): bool
    {
        $base = strtolower(trim(explode(';', $mime, 2)[0]));

        return str_starts_with($base, 'video/')
            || str_starts_with($base, 'audio/')
            || str_starts_with($base, 'image/');
    }
}
