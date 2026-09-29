<?php

/**
 * LanEndpointGuard — boundary parser for LAN device-integration endpoints.
 *
 * Device discovery (SSDP/UPnP/HdHomeRun/IGD) learns a control-plane URL from an
 * untrusted network reply — a LOCATION header or a <controlURL>/<presentationURL>
 * element. That URL is later fetched, or POSTed SOAP/DIDL to, sometimes in the same
 * HTTP worker. Without a boundary check the reply steers server-side requests to any
 * host the attacker names (SSRF: cloud metadata at 169.254.169.254, the loopback admin
 * surface, arbitrary internet hosts).
 *
 * The device-integration threat model is narrower than public-URL SSRF: a legitimate
 * LAN device's LOCATION host is ALWAYS the literal IP that sent the datagram, and its
 * advertised control endpoints live on that SAME host. So the primary law enforced here
 * is SOURCE PINNING — the LOCATION host must equal the received datagram's source
 * address, no resolution, no indirection. Where no source is available (a controlURL
 * read later from an already-pinned document) the secondary law is SAME-HOST: the derived
 * URL's authority must match the pinned origin. A LAN-CIDR gate is the fallback when
 * neither pin nor same-host can be established architecturally.
 *
 * Pure helpers, zero I/O — every method resolves nothing and touches no socket, so the
 * rules are fully unit-testable and refusal is provable BEFORE a socket opens.
 */

declare(strict_types=1);

namespace Phlix\Common\Net;

/**
 * Parses and pins LAN device endpoint URLs. Illegal shapes return null (early exit)
 * rather than throwing, so callers branch uniformly; a hard misuse throws in the caller.
 */
final class LanEndpointGuard
{
    /** RFC1918 private space — the only place a home-LAN device may live. */
    public const ALLOWED_LAN_CIDRS = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /**
     * Non-routable / dangerous destinations that must never be fetched or POSTed to,
     * even though some (loopback, CGNAT) are not public.
     */
    public const REFUSED_CIDRS = [
        '0.0.0.0/8',        // "this host" / wildcard
        '127.0.0.0/8',      // loopback → local admin surface
        '169.254.0.0/16',   // link-local + cloud metadata endpoint
        '100.64.0.0/10',    // CGNAT / many cloud metadata proxies
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved / broadcast
        '::/128',           // IPv6 unspecified
        '::1/128',          // IPv6 loopback
        'fe80::/10',        // IPv6 link-local
        'fc00::/7',         // IPv6 unique-local (mirrors the refused set for v6)
        'ff00::/8',         // IPv6 multicast (mirrors v4 224.0.0.0/4)
    ];

    /** Hard ceiling on any single device-document/control response body. */
    public const MAX_RESPONSE_BYTES = 1048576; // 1 MiB

    /** Per-request socket timeout for LAN control-plane fetches. */
    public const FETCH_TIMEOUT_SECONDS = 5;

    private function __construct()
    {
    }

    /**
     * Parse an untrusted URL into a safe absolute http(s) endpoint, or null.
     *
     * Accepts bare "host:port/path" (SSDP LOCATION sometimes omits the scheme) by
     * prepending http://. Rejects: empty, embedded NUL, missing host, any scheme other
     * than http/https (file/gopher/ssrf-laundering), and userinfo smuggling.
     */
    public static function normalizeHttpUrl(string $raw): ?string
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            return null;
        }

        $candidate = $trimmed;

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) !== 1) {
            $candidate = 'http://' . $candidate;
        }

        $parts = parse_url($candidate);

        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // Userinfo is never legitimate on a device endpoint and can smuggle hosts.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $trimmed === $candidate ? $trimmed : $candidate;
    }

    /**
     * Lowercased authority host of a URL, IPv6 brackets stripped. Null if absent.
     */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return strtolower($host);
    }

    /**
     * Is the host an IP literal? (Never a hostname — pinning must not resolve.)
     */
    public static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Primary law: the endpoint host is a literal IP numerically equal to the datagram
     * source. Hostnames and embedded-IPv4-in-IPv6 forms are collapsed before comparison.
     */
    public static function literalIpEqualsSource(string $host, string $source): bool
    {
        if (!self::isIpLiteral($host) || !self::isIpLiteral($source)) {
            return false;
        }

        $hostV4 = SsrfGuard::embeddedIpv4($host) ?? $host;
        $sourceV4 = SsrfGuard::embeddedIpv4($source) ?? $source;

        $hostBin = filter_var($hostV4, FILTER_VALIDATE_IP) !== false ? inet_pton($hostV4) : false;
        $sourceBin = filter_var($sourceV4, FILTER_VALIDATE_IP) !== false ? inet_pton($sourceV4) : false;

        if ($hostBin === false || $sourceBin === false) {
            return false;
        }

        return hash_equals($hostBin, $sourceBin);
    }

    /**
     * Is an IP literal inside RFC1918 LAN space? (Fallback gate where pinning is
     * architecturally impossible — a controlURL read from a document, never a datagram.)
     */
    public static function isLanAddress(string $ip): bool
    {
        if (!self::isIpLiteral($ip)) {
            return false;
        }

        $effective = SsrfGuard::embeddedIpv4($ip) ?? $ip;

        return SsrfGuard::ipMatchesAnyCidr($effective, self::ALLOWED_LAN_CIDRS);
    }

    /**
     * Is an IP literal in a refused (non-device) range — loopback, any, link-local
     * metadata, CGNAT, multicast, reserved? Collapses IPv4-mapped IPv6 first.
     */
    public static function isRefusedAddress(string $ip): bool
    {
        if (!self::isIpLiteral($ip)) {
            return true;
        }

        if (SsrfGuard::ipMatchesAnyCidr($ip, self::REFUSED_CIDRS)) {
            return true;
        }

        $effective = SsrfGuard::embeddedIpv4($ip);

        if ($effective !== null && SsrfGuard::ipMatchesAnyCidr($effective, self::REFUSED_CIDRS)) {
            return true;
        }

        return false;
    }

    /**
     * Secondary law: a derived URL (controlURL/presentationURL) must resolve to the SAME
     * authority as the pinned base. Absolute cross-host and non-http(s) forms return null.
     */
    public static function sameHostAbsolute(string $base, string $candidate): ?string
    {
        $baseHost = self::hostOf($base);

        if ($baseHost === null) {
            return null;
        }

        $absolute = ProviderUrlAllowlist::resolveRedirect($base, $candidate);

        if ($absolute === null) {
            return null;
        }

        $candidateHost = self::hostOf($absolute);

        if ($candidateHost === null || $candidateHost !== $baseHost) {
            return null;
        }

        return $absolute;
    }

    /**
     * Build a same-origin absolute URL from a possibly-relative control path and the
     * authority of a pinned base, rejecting any candidate that names a different host.
     * Delegates authority comparison to sameHostAbsolute so relative joins, absolute
     * same-host and absolute cross-host all flow through one rule.
     */
    public static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
