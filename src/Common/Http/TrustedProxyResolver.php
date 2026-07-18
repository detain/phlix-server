<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Http;

/**
 * Trusted-proxy-aware client-IP resolver (SV-4.15 HIGH fix).
 *
 * Every IP-keyed rate limiter (register / refresh / jwks / WebAuthn IP-fallback /
 * WS-connect) needs the REAL client address, not one an attacker can forge. The
 * naive "leftmost `X-Forwarded-For` entry" is fully client-controlled: the shipped
 * nginx front sets `X-Forwarded-For $proxy_add_x_forwarded_for` (which APPENDS the
 * connecting address to whatever the client sent), so a forged leftmost value
 * survives and every request lands in a fresh rate-limit bucket — defeating the
 * limiter entirely.
 *
 * This resolver derives the client IP from a TRUSTED chain instead:
 *
 * 1. If the direct TCP peer (`$remoteIp`) is NOT a trusted proxy, the forwarding
 *    headers are attacker-controlled and are IGNORED — the peer address is used.
 *    This is the safe default when Phlix is exposed directly (dev / misconfig).
 * 2. If the peer IS a trusted proxy, `X-Forwarded-For` is walked RIGHT-TO-LEFT,
 *    skipping entries that are themselves trusted proxies. The first untrusted,
 *    well-formed IP is the real client (the address the edge proxy observed and
 *    appended). Any client-forged values sit to the LEFT of the appended hop and
 *    are never reached.
 * 3. If no untrusted XFF hop is present, the proxy-set `X-Real-IP` (nginx
 *    overwrites it with `$remote_addr`, so it is not client-spoofable) is used.
 * 4. Failing all of that, the sanitised peer address is returned.
 *
 * ## Deployment contract (why the default is loopback-only)
 *
 * The shipped reverse proxies front Phlix over loopback — nginx →
 * `127.0.0.1:8080`, HAProxy → `127.0.0.1:8097` — so the peer Phlix observes is
 * always `127.0.0.1`, and the edge proxy appends the real client as the RIGHTMOST
 * XFF entry. The default trusted set is therefore LOOPBACK ONLY. Adding RFC1918
 * ranges by default would be actively wrong for this topology: a client on the
 * same LAN (e.g. `192.168.1.50`) would be treated as a proxy, skipped, and the
 * NEXT-left (client-forged) XFF entry would be trusted instead — reopening the
 * spoof. When a NON-loopback proxy fronts the server, add its address/CIDR via the
 * bare `TRUSTED_PROXIES` env (comma-separated IPs and/or CIDRs).
 *
 * The resolver ALWAYS returns a value validated as a well-formed IP (≤45 chars for
 * IPv6), or a hard-truncated fallback, so a forged/oversized header can never
 * produce a key longer than the `rate_limit_buckets.rate_key` VARCHAR(191) PK.
 *
 * @package Phlix\Common\Http
 */
final class TrustedProxyResolver
{
    /**
     * Default trusted proxies: loopback only. Matches the shipped nginx/HAProxy
     * deployment, where both proxies connect to Phlix over `127.0.0.1` / `::1`.
     *
     * @var list<string>
     */
    public const array DEFAULT_TRUSTED_PROXIES = ['127.0.0.0/8', '::1/128'];

    /** Maximum length of any resolved key fragment (IPv6 max is 45 chars). */
    private const int MAX_IP_LENGTH = 45;

    /** @var list<string> Trusted-proxy IPs and/or CIDR ranges. */
    private array $trustedProxies;

    /**
     * @param list<string>|null $trustedProxies Explicit trusted-proxy list, or
     *        null to source it from the `TRUSTED_PROXIES` env (default: loopback).
     */
    public function __construct(?array $trustedProxies = null)
    {
        $this->trustedProxies = $trustedProxies ?? self::configuredProxies();
    }

    /**
     * The effective trusted-proxy list: the bare `TRUSTED_PROXIES` env parsed as a
     * comma-separated list of IPs/CIDRs, or {@see DEFAULT_TRUSTED_PROXIES} when the
     * env is unset/empty. Also consumed by `config/server.php` for introspection.
     *
     * @return list<string>
     */
    public static function configuredProxies(): array
    {
        $raw = getenv('TRUSTED_PROXIES');
        if (!is_string($raw) || trim($raw) === '') {
            return self::DEFAULT_TRUSTED_PROXIES;
        }
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $p): bool => $p !== '',
        ));
        return $parts === [] ? self::DEFAULT_TRUSTED_PROXIES : $parts;
    }

    /**
     * Resolve the real client IP from a possibly-proxied request.
     *
     * @param string      $remoteIp     The direct TCP peer address.
     * @param string|null $forwardedFor The `X-Forwarded-For` header value (comma
     *                                  list of hops), or null when absent.
     * @param string|null $realIp       The `X-Real-IP` header value, or null.
     *
     * @return string A validated, normalised client IP — never longer than
     *                {@see MAX_IP_LENGTH} chars.
     */
    public function resolve(string $remoteIp, ?string $forwardedFor, ?string $realIp = null): string
    {
        $peer = self::sanitizeIp($remoteIp);

        // The direct peer is not a trusted proxy: forwarding headers cannot be
        // trusted (direct exposure / spoof), so use the peer verbatim.
        if (!$this->isTrusted($peer)) {
            return $peer;
        }

        // Peer is a trusted proxy: walk X-Forwarded-For right-to-left, skipping
        // trusted hops; the first untrusted well-formed IP is the real client.
        if ($forwardedFor !== null && $forwardedFor !== '') {
            $hops = explode(',', $forwardedFor);
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $candidate = trim($hops[$i]);
                if ($candidate === '') {
                    continue;
                }
                $validated = self::validateIp($candidate);
                if ($validated === null) {
                    // A non-IP token in the chain: stop trusting the remainder.
                    break;
                }
                if ($this->isTrusted($validated)) {
                    continue;
                }
                return $validated;
            }
        }

        // No untrusted XFF hop: fall back to the proxy-set X-Real-IP (nginx
        // overwrites it with $remote_addr, so it is not client-spoofable).
        if ($realIp !== null) {
            $validated = self::validateIp(trim($realIp));
            if ($validated !== null) {
                return $validated;
            }
        }

        return $peer;
    }

    /**
     * Whether `$ip` falls within any configured trusted-proxy IP or CIDR range.
     */
    private function isTrusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $range) {
            if (self::ipMatches($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return `$ip` when it is a well-formed IPv4/IPv6 address, else null.
     */
    private static function validateIp(string $ip): ?string
    {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /**
     * Return the peer address as a validated IP, or a hard-truncated fallback so
     * an unexpected/malformed peer value can never overflow the VARCHAR(191) key.
     */
    private static function sanitizeIp(string $ip): string
    {
        $validated = self::validateIp($ip);
        if ($validated !== null) {
            return $validated;
        }
        return substr(trim($ip), 0, self::MAX_IP_LENGTH);
    }

    /**
     * Match an IP against an exact address or a CIDR range (IPv4 and IPv6).
     *
     * @param string $ip    A candidate IP address.
     * @param string $range An exact IP or a `subnet/bits` CIDR range.
     */
    public static function ipMatches(string $ip, string $range): bool
    {
        $ip = trim($ip);
        $range = trim($range);
        if ($range === '') {
            return false;
        }

        // Exact-match form (no CIDR suffix).
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bitsRaw] = explode('/', $range, 2);
        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton(trim($subnet));
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Different address families (4 vs 16 bytes) never match.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        $remainderBits = $bits % 8;
        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;
        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
