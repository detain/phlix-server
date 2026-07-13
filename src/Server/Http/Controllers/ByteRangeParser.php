<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

/**
 * Pure parser for HTTP `Range` request headers (RFC 7233).
 *
 * Extracted from {@see TranscodeFileServer} so it can be invoked directly by
 * callers that are not themselves consumers of the trait (notably
 * {@see \Phlix\Server\Workerman\HttpHandler::serveMediaStream()}). Calling a
 * static method on a trait (`Trait::method()`) is deprecated as of PHP 8.1;
 * a standalone final class is the correct home for a stateless utility.
 */
final class ByteRangeParser
{
    /**
     * Parses an HTTP `Range` header into a resolved byte window.
     *
     * Supports the single-range forms this server serves:
     *   - `bytes=A-B` / `bytes=A-` (open-ended → to EOF). An over-long
     *     last-byte-pos (`B >= $fileSize`) is CLAMPED to `$fileSize - 1`, not
     *     rejected — treating it as unsatisfiable would be a spec deviation
     *     that can spuriously fail a conforming client (e.g. `bytes=0-999999999`).
     *   - `bytes=-N` (suffix range: "the last N bytes"). `N <= 0` or an empty
     *     file is unsatisfiable.
     *
     * A multi-range value (`bytes=0-1,4-5`) or anything else that doesn't match
     * either form returns null so the caller falls back to a whole-file 200 — a
     * valid RFC 7233 fallback for a range the server won't otherwise satisfy.
     *
     * @param string|null $rangeHeader The raw `Range` header value, or null.
     * @param int         $fileSize    The size of the file being served, in bytes.
     *
     * @return array{satisfiable: bool, start: int, end: int}|null Null when the
     *         header isn't a supported single-range form (whole-file 200 fallback);
     *         otherwise the resolved window, or `satisfiable: false` for a 416.
     */
    public static function parse(?string $rangeHeader, int $fileSize): ?array
    {
        if (!is_string($rangeHeader)) {
            return null;
        }

        if (preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $rm) === 1) {
            $start = (int) $rm[1];
            $end = $rm[2] !== '' ? (int) $rm[2] : $fileSize - 1;
            // RFC 7233 §2.1: an over-long last-byte-pos is clamped to the actual
            // EOF and answered 206, not rejected with 416.
            if ($end >= $fileSize) {
                $end = $fileSize - 1;
            }
            $satisfiable = $fileSize > 0 && $start < $fileSize && $start <= $end;
            return ['satisfiable' => $satisfiable, 'start' => $start, 'end' => $end];
        }

        if (preg_match('/^bytes=-(\d+)$/', $rangeHeader, $rm) === 1) {
            // Suffix range: "the last N bytes" (RFC 7233 §2.1). A zero-length
            // suffix or an empty file has nothing satisfiable to serve.
            $suffixLength = (int) $rm[1];
            $satisfiable = $fileSize > 0 && $suffixLength > 0;
            $start = $satisfiable ? max(0, $fileSize - $suffixLength) : 0;
            return ['satisfiable' => $satisfiable, 'start' => $start, 'end' => $fileSize - 1];
        }

        return null;
    }
}
