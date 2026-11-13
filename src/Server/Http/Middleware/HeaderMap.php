<?php

/**
 * Phlix media server: case-insensitive header-map lookup shared by middleware.
 *
 * @package Phlix\Server\Http\Middleware
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

/**
 * Case-insensitive lookups over a response header map (RFC 9110 §4.2).
 *
 * Response::header() stores keys in the exact case the caller used, while HTTP
 * field names are case-insensitive, so a plain isset()/index miss would let a
 * middleware decorate a second copy beside e.g. `content-security-policy`.
 * {@see SecurityHeaders} and {@see CorsManager} both use this single helper.
 */
final class HeaderMap
{
    /**
     * Return the value stored under $name (any case), or null when absent.
     *
     * @param array<array-key, string> $headers Header map.
     * @param string                   $name    Field name to look up.
     */
    public static function get(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether a header named $name (any case) is present.
     *
     * @param array<array-key, string> $headers Header map.
     * @param string                   $name    Field name to look up.
     */
    public static function has(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
