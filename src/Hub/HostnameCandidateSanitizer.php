<?php

/**
 * Hostname candidate sanitizer.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Makes locally-discovered host material fit the hub's host grammar before it
 * is advertised. phlix-shared (>= 0.55.0) `HeartbeatDto` / `ClaimRequest`
 * reject the WHOLE payload when any `hostnameCandidates` entry is not an RFC
 * 1123 hostname, IPv4/IPv6 literal, or absolute http(s) URL over one; a
 * `gethostname()` such as `my_nas` would otherwise make every claim and
 * heartbeat 400. Hostnames are mapped (`_` and other separators become `-`,
 * ASCII-folded, labels trimmed to 63 octets); whole candidates that still do
 * not fit are dropped, never advertised.
 */
final class HostnameCandidateSanitizer
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Map a raw hostname (e.g. `gethostname()`) onto the RFC 1123 grammar.
     *
     * @return string Sanitized hostname, or '' when nothing usable remains.
     */
    public static function sanitizeHostname(string $hostname): string
    {
        $labels = [];
        foreach (explode('.', strtolower(trim($hostname))) as $label) {
            $label = preg_replace('/[^a-z0-9]+/', '-', $label) ?? '';
            $label = rtrim(substr(trim($label, '-'), 0, 63), '-');
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if ($labels === []) {
            return '';
        }
        $result = implode('.', $labels);
        if (strlen($result) > 253) {
            return '';
        }

        // A numeric rightmost label would read as a malformed IP, not a name.
        return ctype_digit($labels[count($labels) - 1]) ? '' : $result;
    }

    /**
     * Whether a candidate is a bare host[:port] or absolute http(s) URL whose
     * host is an RFC 1123 name or IP literal (no userinfo/query/fragment).
     */
    public static function isAcceptable(string $candidate): bool
    {
        if (preg_match('/^[\x21-\x7E]+$/D', $candidate) !== 1 || str_contains($candidate, '\\')) {
            return false;
        }
        if (str_contains($candidate, '://')) {
            if (preg_match('#^https?://#i', $candidate) !== 1 || preg_match('/[?#@]/', $candidate) === 1) {
                return false;
            }
            $parts = parse_url($candidate);
            if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
                return false;
            }

            return self::isHost($parts['host'])
                && (!isset($parts['port']) || ($parts['port'] >= 1 && $parts['port'] <= 65535));
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/D', $candidate, $m) === 1) {
            return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && (!isset($m[2]) || ((int) $m[2] >= 1 && (int) $m[2] <= 65535));
        }
        if (preg_match('/^([^:\[\]]+)(?::(\d{1,5}))?$/D', $candidate, $m) === 1) {
            return self::isHost($m[1])
                && (!isset($m[2]) || ((int) $m[2] >= 1 && (int) $m[2] <= 65535));
        }

        return false;
    }

    /**
     * Drop every candidate that would make the hub reject the payload.
     *
     * @param array<int, string> $candidates
     * @return list<string>
     */
    public static function filter(array $candidates): array
    {
        $out = [];
        foreach ($candidates as $candidate) {
            if (self::isAcceptable($candidate)) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    private static function isHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        if (
            strlen($host) > 253
            || preg_match('/^' . self::LABEL . '(?:\.' . self::LABEL . ')*$/Di', $host) !== 1
        ) {
            return false;
        }
        $labels = explode('.', $host);

        return !ctype_digit($labels[count($labels) - 1]);
    }
}
