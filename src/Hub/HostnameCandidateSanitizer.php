<?php

/**
 * Hostname candidate sanitizer.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Shared\Hub\HostGrammar;

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
     *
     * v0.57 adoption (srv-C5b): this is a pure delegation to the estate's one
     * host/URL grammar, {@see HostGrammar} — the same law
     * the hub's HeartbeatDto/ClaimRequest boundaries apply to these very
     * candidates, so pre-filter and intake can never disagree. The retired
     * in-repo twin measured 0 verdict deltas against the shared grammar on a
     * 55,938-vector both-direction corpus (generated + curated wire shapes;
     * gates/C1SRV-4b-differential.log); the local lowercase-only LABEL carried
     * a /i modifier, so case tolerance was never actually divergent. The
     * tripwire in tests/Unit/Hub/HostnameCandidateSanitizerTest.php pins the
     * delegation voice print; re-growing a local grammar reddens it.
     */
    public static function isAcceptable(string $candidate): bool
    {
        return HostGrammar::isValidCandidate($candidate);
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
}
