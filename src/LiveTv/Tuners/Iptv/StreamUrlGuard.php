<?php

/**
 * Phlix media server component: Iptv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

use Phlix\Common\Net\SsrfGuard;

/**
 * Safety jail for IPTV stream/playlist URLs coming from M3U content.
 *
 * M3U playlist bodies are THIRD-PARTY, ROTATING content: even a playlist URL
 * an operator configured benignly can serve hostile entries on the next
 * fetch. Entries flow straight into `ffmpeg -i <url>` (Recorder) and into
 * player-facing tune responses, so an unchecked `file:///etc/passwd` becomes
 * a file-read exfiltration into the DVR and an unchecked literal
 * `http://169.254.169.254/...` becomes an SSRF tunnel into cloud metadata.
 *
 * ## The policy (default mode)
 *
 * 1. **Scheme jail:** only `http` and `https` may ever be tuned. Everything
 *    else (`file://`, `gopher://`, `udp://`, …) is rejected at parse time AND
 *    again at tune time (playlist content can change between the two).
 * 2. **Literal-host deny-list:** a URL whose host is an IP literal pointing at
 *    loopback (127/8, ::1), the "this network"/unspecified block (0/8, ::), or
 *    link-local incl. the 169.254.169.254 metadata endpoint (169.254/16,
 *    fe80::/10) is refused — after collapsing IPv4-mapped/NAT64 IPv6 forms via
 *    {@see SsrfGuard::embeddedIpv4()} so `::ffff:127.0.0.1` cannot smuggle.
 * 3. **LAN stays legal:** RFC1918/tailnet IPTV servers behind a hostname (or a
 *    private literal) are deliberately NOT denied in default mode — operators
 *    intentionally run private IPTV servers on the LAN and the whole point of
 *    the module is watching them. The DNS-rebound/hostname-to-metadata hole
 *    this tolerance leaves open is closed by strict mode (below) and by
 *    running the deny-list on the resolved address rather than the literal.
 *
 * ## Strict mode (`$strict = true`)
 *
 * Delegates the full {@see SsrfGuard::assertPublicUrl()} treatment — blocking
 * DNS resolution plus the complete private-range deny-list, overridable per
 * operator via `PHLIX_SSRF_ALLOW_CIDRS`. Enable it (`iptv.strict_stream_policy`
 * in config/livetv.php) on hosts where the server must never dial any private
 * address. Note the SsrfGuard placement law: strict mode performs a BLOCKING
 * DNS lookup, so it is only suitable where tunes happen off the media-serving
 * hot path (the tuner-driver entry points already are).
 *
 * No host-name allowlist is attempted: rotating CDN hostnames are the norm for
 * IPTV providers and any such list would either be empty in practice or push
 * operators into disabling the module.
 *
 * @since 2.3.0
 */
final class StreamUrlGuard
{
    /**
     * Address blocks that are NEVER a legitimate IPTV stream target, in any
     * mode. Deliberately narrower than SsrfGuard's full deny-list — see the
     * policy docblock for why RFC1918 is excluded from this default jail.
     *
     * @var list<string>
     */
    public const DENIED_CIDRS = [
        '0.0.0.0/8',      // "this network" / unspecified
        '127.0.0.0/8',    // loopback
        '169.254.0.0/16', // link-local incl. 169.254.169.254 cloud metadata
        '::1/128',        // IPv6 loopback
        '::/128',         // IPv6 unspecified
        'fe80::/10',      // IPv6 link-local
    ];

    /**
     * Assert a stream/playlist URL satisfies the jail, throwing otherwise.
     *
     * @param string $url   Candidate URL from playlist content or device config.
     * @param bool   $strict When true, additionally require the URL resolve
     *                       (via DNS) to public-only addresses
     *                       ({@see SsrfGuard::assertPublicUrl()}).
     *
     * @throws \RuntimeException With a specific, log-safe reason when refused.
     *
     * @return void
     */
    public static function assertTunable(string $url, bool $strict = false): void
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException(sprintf(
                'Stream URL rejected: scheme "%s" is not allowed (only http/https may be tuned).',
                $scheme === '' ? 'none' : $scheme
            ));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('Stream URL rejected: URL contains no host.');
        }

        // Strip an IPv6 literal's brackets (parse_url keeps them).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // Literal-host jail: evaluated on the collapsed IPv4 form so IPv4-mapped
        // / NAT64 spellings of loopback/link-local cannot smuggle past.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $effective = SsrfGuard::embeddedIpv4($host) ?? $host;
            if (SsrfGuard::ipMatchesAnyCidr($effective, self::DENIED_CIDRS)) {
                throw new \RuntimeException(sprintf(
                    'Stream URL rejected: host "%s" is a loopback/link-local/unspecified address.',
                    $host
                ));
            }
        }

        if (!$strict) {
            return;
        }

        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Stream URL rejected under strict policy: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convenience predicate wrapping {@see self::assertTunable()}; returns
     * false instead of throwing. Use at parse time, where a refused entry is
     * dropped (not fatal); use the assert form at tune time.
     */
    public static function isTunable(string $url, bool $strict = false): bool
    {
        try {
            self::assertTunable($url, $strict);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * A log-safe reason string for a refusal (null when the URL passes).
     *
     * Kept separate from logging decisions at the call sites so the message
     * stays out of the exception-vs-return contract debates.
     */
    public static function refusalReason(string $url, bool $strict = false): ?string
    {
        try {
            self::assertTunable($url, $strict);
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
