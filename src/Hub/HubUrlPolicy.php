<?php

/**
 * Hub URL scheme / transport policy (SRV-43).
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Pure helpers deciding whether an operator-supplied hub / JWKS URL may be
 * stored (http or https only) and whether dialling it deserves a cleartext
 * warning (http:// to a non-loopback host).
 */
final class HubUrlPolicy
{
    /**
     * True when the URL parses with an http or https scheme and a host.
     */
    public static function isHttpScheme(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }

    /**
     * True when the URL is cleartext http:// to a host that is not loopback.
     */
    public static function isCleartextNonLoopback(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || strtolower($scheme) !== 'http' || !is_string($host)) {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1) {
            return false;
        }

        return true;
    }
}
