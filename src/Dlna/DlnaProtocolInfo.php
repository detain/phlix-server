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
 * The `<res protocolInfo="…">` value advertised for one media item.
 *
 * ## What was wrong before (S53)
 *
 * `ContentDirectory::getProtocolInfo()` owned a four-arm `match` over the
 * item's `type`:
 *
 * ```php
 * 'video', 'movie' => 'DLNA.ORG_PN=AVC_MP4_MP_HD',
 * 'audio', 'music' => 'DLNA.ORG_PN=AAC_ADTS',
 * 'image', 'photo' => 'DLNA.ORG_PN=JPEG_LRG',
 * default          => '',
 * ```
 *
 * Three defects, all of which a renderer sees:
 *
 * 1. **`DLNA.ORG_PN` is a claim about the BITSTREAM, not about the row's
 *    type.** `AVC_MP4_MP_HD` was advertised unconditionally for anything typed
 *    `video`/`movie` — including a `.mkv`, a `.avi` or an MPEG-2 `.ts`. A
 *    renderer that trusts a `DLNA.ORG_PN` starts a decoder for that exact
 *    profile and fails mid-stream when the bytes are something else. Guessing
 *    wrong is strictly worse than not claiming a profile at all, which the
 *    spec permits.
 * 2. **Non-exhaustive over the 13-member `media_items.type` ENUM.** `episode`,
 *    `track` and `audiobook` — the types the TV/music/audiobook scanners
 *    actually write — all fell to `default => ''`.
 * 3. **A dead `'image'` arm.** The ENUM member is `photo`; `image` has never
 *    been a column value (see {@see \Phlix\Media\MediaItemType}).
 *
 * ## What it does now
 *
 * The MIME comes from {@see DlnaMimeTypes} — the same table
 * {@see \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController} serves the
 * bytes under, resolved through the same decisions in the same order (see
 * {@see self::mimeFor()}), so the advertised type and the delivered
 * `Content-Type` cannot drift. The `DLNA.ORG_PN` is then looked up from that
 * MIME, and **only** for containers whose profile is unambiguous from the
 * container alone ({@see self::MIME_TO_DLNA_PN}). Everything else advertises no
 * profile, which is what the `*` in the fourth field means.
 *
 * ## What this deliberately does NOT do
 *
 * An item the stream route would answer `415` for still gets a `<res>`, just an
 * honestly-unplayable one (`application/octet-stream`, no flags). Suppressing
 * the element entirely for those rows is a defensible further step — renderers
 * surface a 415 poorly — but it changes what a Browse response CONTAINS rather
 * than what it CLAIMS, so it is left as a separate decision (plan S53, the
 * carried-forward reviewer proposals) rather than folded in here.
 *
 * The flags are facts about {@see DlnaRoutes::STREAM_PATTERN}, not decoration:
 *
 * - `DLNA.ORG_OP=01` — byte-range seek yes, time-seek no. S52's controller
 *   parses `Range` and answers 206/416, and implements no `TimeSeekRange`.
 * - `DLNA.ORG_CI=0` — the bytes are the source file, not transcoded. S52 is
 *   direct-play only; it answers 415 rather than converting.
 *
 * @package Phlix\Dlna
 * @since 1.7.0
 */
final class DlnaProtocolInfo
{
    /**
     * MIME → `DLNA.ORG_PN` profile name, for containers where the profile is
     * implied by the container itself.
     *
     * Deliberately SHORT. A `DLNA.ORG_PN` names a constrained profile (codec,
     * level, bitrate ceiling), so it may only be asserted where the container
     * leaves no room for doubt. `video/mp4` is listed as `AVC_MP4_MP_HD`
     * because that is the profile Phlix's own transcode presets produce and the
     * one an `.mp4` in a media library overwhelmingly is; `video/x-matroska`,
     * `video/mp2t`, `video/x-msvideo` and friends are deliberately ABSENT,
     * because their contents genuinely could be anything.
     *
     * @var array<string, string>
     */
    private const MIME_TO_DLNA_PN = [
        'video/mp4'  => 'AVC_MP4_MP_HD',
        'audio/mpeg' => 'MP3',
        'audio/mp4'  => 'AAC_ISO',
        'audio/aac'  => 'AAC_ADTS',
        'audio/wav'  => 'LPCM',
        'image/jpeg' => 'JPEG_LRG',
        'image/png'  => 'PNG_LRG',
        'image/gif'  => 'GIF_LRG',
    ];

    /**
     * Operation flags for the S52 stream route: byte-seek yes, time-seek no,
     * conversion no.
     */
    private const OPERATION_FLAGS = 'DLNA.ORG_OP=01;DLNA.ORG_CI=0';

    /**
     * The `protocolInfo` string for one `media_items` row.
     *
     * @param array<string, mixed> $item A hydrated row / CDS object.
     *
     * @return string `http-get:*:{mime}:{fourth-field}`.
     */
    public static function forItem(array $item): string
    {
        return sprintf('http-get:*:%s:%s', self::mimeFor($item), self::fourthField($item));
    }

    /**
     * The MIME this item is advertised — and served — under.
     *
     * Mirrors {@see \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController}
     * decision for decision, because the whole value of this string to a
     * renderer is that it predicts the `Content-Type` the bytes arrive with:
     *
     *  1. **The direct-play gate first.** The controller gates on the
     *     CONTAINER — `DlnaMimeTypes::forPath($real) === FALLBACK` means 415, no
     *     bytes at all — so a row whose extension this server does not
     *     recognise must not be advertised as playable, however its `type`
     *     column reads. Before S53 a `.iso` row typed `movie` advertised
     *     `video/mp4` and then 415'd on fetch.
     *  2. **Then the row-level resolution**, `mime_type` → extension → type.
     *  3. **Then the same junk-value rejection**: a hand-edited `mime_type`
     *     that does not name media falls back to the container's own type,
     *     exactly as the controller does, so the two agree on the substituted
     *     value as well as on the happy one.
     *
     * @param array<string, mixed> $item
     */
    public static function mimeFor(array $item): string
    {
        $pathRaw = $item['path'] ?? null;
        $path = is_string($pathRaw) ? $pathRaw : '';

        if ($path !== '' && DlnaMimeTypes::forPath($path) === DlnaMimeTypes::FALLBACK) {
            return DlnaMimeTypes::FALLBACK;
        }

        $mime = DlnaMimeTypes::forItem($item);
        if (DlnaMimeTypes::isMediaType($mime)) {
            return $mime;
        }

        return $path !== '' ? DlnaMimeTypes::forPath($path) : DlnaMimeTypes::FALLBACK;
    }

    /**
     * The fourth `protocolInfo` field — the `DLNA.ORG_*` parameter list, or `*`
     * when nothing can honestly be claimed.
     *
     * @param array<string, mixed> $item
     */
    private static function fourthField(array $item): string
    {
        $mime = self::mimeFor($item);

        // An unrecognised container is exactly what the stream route answers
        // 415 for. Advertising seek/no-convert flags for bytes that will never
        // be served is a promise this server cannot keep.
        if (!DlnaMimeTypes::isMediaType($mime)) {
            return '*';
        }

        $profile = self::MIME_TO_DLNA_PN[$mime] ?? null;
        if ($profile === null) {
            return self::OPERATION_FLAGS;
        }

        return 'DLNA.ORG_PN=' . $profile . ';' . self::OPERATION_FLAGS;
    }

    /**
     * Prevent instantiation — this class is a static builder only.
     */
    private function __construct()
    {
    }
}
