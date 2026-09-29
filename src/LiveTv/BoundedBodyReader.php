<?php

/**
 * Phlix media server component: LiveTv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv;

/**
 * Reads at most `maxBytes + 1` bytes from an open stream handle.
 *
 * The `+ 1` over-read is the mechanism that lets a caller distinguish
 * "payload exactly at the limit" from "payload exceeded the limit" without
 * ever buffering an attacker-controlled amount of memory into the worker —
 * the idiom {@see \Phlix\LiveTv\Tuners\Iptv\XmlTvParser::parseUrl()} pioneered
 * and every bounded LiveTv fetch now shares.
 *
 * Purely atomic (Law 3): same handle position + same cap in, same bytes out,
 * no hidden state. The caller owns the handle lifecycle.
 *
 * @since 2.3.0
 */
final class BoundedBodyReader
{
    /**
     * Read up to $maxBytes + 1 bytes from $handle.
     *
     * A return value whose strlen() exceeds $maxBytes means the source was
     * bigger than the cap — the caller decides the failure policy (named
     * exception, drop-with-log, refuse-to-cache).
     *
     * @param resource $handle Readable stream handle positioned at the body start.
     * @param int      $maxBytes Maximum body size the caller is willing to trust.
     *
     * @return string At most $maxBytes + 1 bytes of body content.
     *
     * @throws \RuntimeException When the stream read itself fails.
     */
    public static function read($handle, int $maxBytes): string
    {
        $content = stream_get_contents($handle, $maxBytes + 1);

        if ($content === false) {
            throw new \RuntimeException('Failed to read response body from stream.');
        }

        return $content;
    }
}
