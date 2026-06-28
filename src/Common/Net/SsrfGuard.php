<?php

declare(strict_types=1);

namespace Phlix\Common\Net;

use InvalidArgumentException;

/**
 * Shared SSRF (Server-Side Request Forgery) guard for outbound, admin-supplied
 * URLs.
 *
 * Several admin surfaces accept a URL or host that the server then fetches
 * server-side: outbound webhooks ({@see \Phlix\Webhooks\WebhookDispatcher}),
 * the MQTT notification bridge ({@see \Phlix\Webhooks\Plugins\MqttPlugin} — a
 * raw `file_get_contents()` against an operator-supplied host), the plugin
 * catalog fetcher ({@see \Phlix\Plugins\Catalog\PluginCatalogService}) and the
 * plugin source resolver ({@see \Phlix\Plugins\Installer\SourceUrlResolver}).
 * Without validation an operator (or anyone who can reach an admin endpoint)
 * could point the server at `http://169.254.169.254/...` (cloud instance
 * metadata), `http://127.0.0.1:…` (loopback admin services) or RFC1918
 * internal hosts and pivot through the server's network position.
 *
 * {@see self::assertPublicUrl()} enforces that a URL:
 *   1. uses an `http`/`https` scheme;
 *   2. resolves (via DNS) to at least one IP address; and
 *   3. resolves ONLY to public/global-scope addresses — rejecting loopback
 *      (127.0.0.0/8, ::1), the "any" address (0.0.0.0 / ::), link-local
 *      (169.254.0.0/16 incl. the 169.254.169.254 cloud-metadata endpoint, and
 *      IPv6 fe80::/10), RFC1918 private ranges (10/8, 172.16/12, 192.168/16),
 *      carrier-grade NAT (100.64.0.0/10) and IPv6 unique-local (fc00::/7).
 *
 * Operators with legitimate internal brokers/endpoints can punch through the
 * private-range deny-list with an explicit CIDR allowlist via the
 * `PHLIX_SSRF_ALLOW_CIDRS` environment variable (comma- or `PATH_SEPARATOR`-
 * separated list of CIDRs, e.g. `10.10.0.0/24,192.168.50.5/32`). An address
 * that matches an allowlisted CIDR is permitted even if it falls in a denied
 * range. The allowlist can also be injected programmatically via
 * {@see self::setAllowedCidrs()} (boot/test seam).
 *
 * ## Async / event-loop placement (CARDINAL: no blocking DNS on the hot path)
 *
 * DNS resolution ({@see gethostbyname()} / {@see dns_get_record()}) is a
 * BLOCKING syscall. On phlix-server's Swoole event loop the file/proc hooks are
 * deliberately OFF, so these calls genuinely block the worker. This guard is
 * therefore ONLY invoked from admin **configuration** time (webhook create,
 * plugin catalog add) and **dispatch/fetch** time for outbound notifications —
 * i.e. operator-triggered admin actions and the background notification path,
 * NOT the per-request media-serving hot path that normal viewers hit. No call
 * site added here runs inside the media direct-play / streaming routes. The DNS
 * resolution step is injected via {@see self::setResolver()} so unit tests stay
 * deterministic and offline (no real network lookups in the suite).
 *
 * @since 2.2.0
 */
final class SsrfGuard
{
    /**
     * Optional DNS-resolution seam. When null, {@see self::defaultResolver()}
     * is used. Injectable so tests never hit the network.
     *
     * @var (callable(string): list<string>)|null
     */
    private static $resolver = null;

    /**
     * Explicitly configured allowlist of CIDRs that override the private-range
     * deny-list, or null when not configured (fall back to the
     * `PHLIX_SSRF_ALLOW_CIDRS` env var).
     *
     * @var list<string>|null
     */
    private static ?array $allowedCidrs = null;

    /**
     * Asserts that the URL is safe to fetch server-side: an http(s) URL whose
     * host resolves only to public (or explicitly allowlisted) IP addresses.
     *
     * @param string $url The untrusted, admin-supplied URL.
     *
     * @throws InvalidArgumentException When the scheme is not http(s), the host
     *         is missing or unresolvable, or any resolved address is in a denied
     *         (loopback/link-local/private/ULA) range and not allowlisted.
     *
     * @return void
     */
    public static function assertPublicUrl(string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('URL is required.');
        }

        $scheme = strtolower((string) (parse_url($trimmed, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException('URL must use the http:// or https:// scheme.');
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('URL must contain a host.');
        }

        // Strip an IPv6 literal's brackets (parse_url keeps them).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $addresses = self::resolveHost($host);
        if ($addresses === []) {
            throw new InvalidArgumentException(
                sprintf('Could not resolve host "%s".', $host),
            );
        }

        $allowed = self::allowedCidrs();
        foreach ($addresses as $ip) {
            if ($allowed !== [] && self::ipMatchesAnyCidr($ip, $allowed)) {
                continue;
            }
            if (!self::isPublicIp($ip)) {
                throw new InvalidArgumentException(sprintf(
                    'Refusing to fetch "%s": host "%s" resolves to a non-public address (%s).',
                    $trimmed,
                    $host,
                    $ip,
                ));
            }
        }
    }

    /**
     * Convenience predicate wrapping {@see self::assertPublicUrl()}; returns
     * false instead of throwing.
     */
    public static function isPublicUrl(string $url): bool
    {
        try {
            self::assertPublicUrl($url);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Injects a DNS-resolution seam. The callable receives a host (hostname or
     * IP literal) and returns a list of resolved IP-address strings.
     *
     * @param (callable(string): list<string>)|null $resolver Pass null to reset
     *        to the default resolver.
     *
     * @return void
     */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Explicitly sets the CIDR allowlist (boot/test seam), overriding the
     * `PHLIX_SSRF_ALLOW_CIDRS` env var. Pass null to restore env resolution.
     *
     * @param list<string>|null $cidrs
     *
     * @return void
     */
    public static function setAllowedCidrs(?array $cidrs): void
    {
        self::$allowedCidrs = $cidrs === null ? null : self::filterCidrs($cidrs);
    }

    /**
     * Clears all injected state (resolver + allowlist). Primarily a test seam.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resolver = null;
        self::$allowedCidrs = null;
    }

    /**
     * Resolves a host to its list of IP addresses. An IP literal resolves to
     * itself; a hostname is resolved via the injected/default resolver.
     *
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolver = self::$resolver ?? self::defaultResolver();
        $resolved = $resolver($host);

        $out = [];
        foreach ($resolved as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !in_array($ip, $out, true)) {
                $out[] = $ip;
            }
        }
        return $out;
    }

    /**
     * The default, BLOCKING DNS resolver (gethostbyname for A records +
     * dns_get_record for AAAA). Only ever invoked off the media hot path — see
     * the class-level async note. Injectable via {@see self::setResolver()}.
     *
     * @return callable(string): list<string>
     */
    private static function defaultResolver(): callable
    {
        return static function (string $host): array {
            $addresses = [];

            // IPv4 (A records). gethostbyname returns the input unchanged on
            // failure, which FILTER_VALIDATE_IP then rejects in resolveHost().
            $ipv4 = gethostbyname($host);
            if ($ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $addresses[] = $ipv4;
            }

            // All A records (gethostbyname only yields one) + AAAA records.
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) {
                        $addresses[] = $record['ip'];
                    }
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }

            return array_values(array_unique($addresses));
        };
    }

    /**
     * Whether an IP address is a public (global-scope) address — i.e. NOT in
     * any of the SSRF-denied ranges.
     */
    private static function isPublicIp(string $ip): bool
    {
        // PHP's own private/reserved-range filter covers loopback, RFC1918,
        // link-local, ULA, and most reserved blocks for both v4 and v6.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($isPublic === false) {
            return false;
        }

        // Defence in depth: explicitly reject the ranges that matter most for
        // SSRF even if a future PHP/filter change loosens the reserved sets.
        foreach (self::deniedCidrs() as $cidr) {
            if (self::ipMatchesCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The explicit SSRF deny-list (defence in depth atop PHP's filter flags).
     *
     * @return list<string>
     */
    private static function deniedCidrs(): array
    {
        return [
            '0.0.0.0/8',          // "this network" / unspecified
            '10.0.0.0/8',         // RFC1918
            '100.64.0.0/10',      // CGNAT (RFC6598)
            '127.0.0.0/8',        // loopback
            '169.254.0.0/16',     // link-local incl. 169.254.169.254 metadata
            '172.16.0.0/12',      // RFC1918
            '192.168.0.0/16',     // RFC1918
            '::1/128',            // IPv6 loopback
            '::/128',             // IPv6 unspecified
            'fc00::/7',           // IPv6 unique-local
            'fe80::/10',          // IPv6 link-local
        ];
    }

    /**
     * The effective CIDR allowlist (explicit injection wins over env).
     *
     * @return list<string>
     */
    private static function allowedCidrs(): array
    {
        if (self::$allowedCidrs !== null) {
            return self::$allowedCidrs;
        }

        $env = getenv('PHLIX_SSRF_ALLOW_CIDRS');
        if (is_string($env) && trim($env) !== '') {
            $parts = preg_split('/[' . preg_quote(PATH_SEPARATOR, '/') . ',]/', $env);
            return self::filterCidrs($parts === false ? [] : $parts);
        }

        return [];
    }

    /**
     * Trim/validate a list of CIDR strings (drops blanks and malformed entries).
     *
     * @param list<string> $cidrs
     *
     * @return list<string>
     */
    private static function filterCidrs(array $cidrs): array
    {
        $out = [];
        foreach ($cidrs as $cidr) {
            $trimmed = trim($cidr);
            if ($trimmed === '' || !str_contains($trimmed, '/')) {
                continue;
            }
            [$subnet] = explode('/', $trimmed, 2);
            if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!in_array($trimmed, $out, true)) {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    /**
     * Whether an IP matches any CIDR in the list.
     *
     * @param list<string> $cidrs
     */
    private static function ipMatchesAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a single IP address falls within a CIDR block. Handles both IPv4
     * and IPv6 via binary prefix comparison.
     */
    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $bitsRaw] = explode('/', $cidr, 2);
        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Different address families (v4 vs v6) can never match.
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

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainderBits) & 0xFF;
        $ipByte = ord($ipBin[$fullBytes]);
        $subnetByte = ord($subnetBin[$fullBytes]);

        return ($ipByte & $mask) === ($subnetByte & $mask);
    }
}
