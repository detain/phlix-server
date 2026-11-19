<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common\Time;

/**
 * The ONE mint for RFC 1123 HTTP-date stamps (E-16).
 *
 * `Last-Modified`, the matching `If-Modified-Since` comparison, the `If-Range`
 * HTTP-date validator and the SSDP `DATE` header all compare these stamps
 * BYTE-EXACTLY — the server itself does (`trim($ims) === $lastModified` in
 * TranscodeFileServer, `strtotime($ifModifiedSince) >= $mtime` in
 * MediaItemController) and so do caches and control points. That makes the
 * grammar wire-critical, not cosmetic: every hand-built copy was a silent
 * cache-correctness risk (3-letter vs full day name, padded vs bare day,
 * missing or escaped `GMT`), which is exactly the drift class E-16 recorded.
 *
 * The format string is the historical inline literal reproduced verbatim —
 * `gmdate('D, d M Y H:i:s', $t) . ' GMT'` — deliberately NOT "improved" to
 * DATE_RFC7231, whose identical bytes are relied on here just as much as a
 * nicer spelling would be disallowed. Tests pin the bytes at fixed instants
 * (tests/Unit/Common/Time/HttpDateTest.php).
 *
 * @package Phlix\Common\Time
 * @since   in-lane (SRV-MICRO / E-16)
 */
final class HttpDate
{
    /**
     * RFC 1123 IMF-fixdate grammar without the zone: 3-letter day, padded
     * day-of-month, 3-letter month, 4-digit year, 24h padded time, UTC.
     */
    private const GRAMMAR = 'D, d M Y H:i:s';

    /**
     * Static-entry helper — like {@see \Phlix\Network\LocalAddressProbe},
     * there is nothing to instantiate and the private constructor says so.
     */
    private function __construct()
    {
    }

    /**
     * Format a unix timestamp as an RFC 1123 GMT date.
     *
     * Timezone-independent by construction (gmdate, never date): the same
     * instant formats identically on a host with `date.timezone` set to
     * anything. Negative timestamps are not rejected — pre-epoch file mtimes
     * are meaningless, but throwing here would add a failure mode the old
     * inline `gmdate(...)` never had, and callers already guard their
     * `filemtime() === false` cases.
     *
     * @param int $unixTime Seconds since the Unix epoch (a `filemtime()`/`time()` value).
     *
     * @return string e.g. 'Wed, 21 Oct 2015 07:28:00 GMT'
     */
    public static function gmt(int $unixTime): string
    {
        return gmdate(self::GRAMMAR, $unixTime) . ' GMT';
    }

    /**
     * The current instant as an RFC 1123 GMT date (SSDP `DATE:`, Expires
     * companions, and any other "stamp as of now" header).
     *
     * Byte-identical to the historical `gmdate('D, d M Y H:i:s') . ' GMT'`
     * one-argument form — `gmdate()`'s default timestamp is `time()`, which is
     * exactly what is passed here.
     */
    public static function fromNow(): string
    {
        return self::gmt(time());
    }
}
