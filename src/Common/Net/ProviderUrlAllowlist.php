<?php

/**
 * Phlix media server component: Common\Net.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Net;

use InvalidArgumentException;

/**
 * The two-layer SSRF gate for every provider-image URL the server fetches (S73).
 *
 * Layer one is a HOST ALLOWLIST: only the TMDB image CDN, anchored exactly the
 * way {@see \Phlix\Media\Metadata\BackdropSrcset::TMDB_URL()} anchors it for
 * CSP — strict `https`, exact host, fixed `/t/p/{size}/{path}` shape. Anything
 * else is refused BEFORE any socket, DNS lookup, or temp file is touched, so an
 * attacker-supplied host never reaches the network at all.
 *
 * Layer two is {@see SsrfGuard::assertPublicUrl()} on every hop: it resolves
 * the (already allowlisted) hostname and rejects private/reserved/metadata
 * addresses, which closes the DNS-rebinding hole layer one cannot see —
 * `image.tmdb.org` is allowed by name yet could be resolved to
 * `169.254.169.254` by a poisoned resolver.
 *
 * ⚠ Why this is NOT `MediaPosterController::isKnownPosterUrl()`: that method
 * is a CANDIDATE-MEMBERSHIP check (the URL must equal one already stored in
 * the item's metadata), not a host allowlist — it constrains nothing about
 * where the bytes come from once a hostile path is in the metadata. The plan
 * audit (updates.md #47) is explicit that the correct construction is the two
 * controls here, applied PER HOP on both download paths, and `ArtworkStorage`
 * does exactly that.
 *
 * Redirects are never auto-followed on either download path: the caller must
 * feed every `Location` header through {@see self::resolveRedirect()} and then
 * {@see self::assertFetchable()} before issuing the next request, so a 302 to
 * an internal address cannot smuggle itself past layer one's hostname check.
 *
 * @package Phlix\Common\Net
 */
final class ProviderUrlAllowlist
{
    /**
     * Provider image hosts allowed for server-side fetches. TMDB is the only
     * provider today; widening this list is a security review, not a convenience.
     */
    public const HOSTS = ['image.tmdb.org'];

    /**
     * The anchored TMDB image-CDN URL shape — the same host + `/t/p/` structure
     * {@see \Phlix\Media\Metadata\BackdropSrcset} uses, tightened to https-only
     * because every fetch here is server-side and has no http-only fallback.
     */
    private const TMDB_URL_PATTERN = '#^https://image\.tmdb\.org/t/p/(?:w\d+|original)/(\S+)$#';

    /** Non-exhaustive: the class is a gate, not a value holder. */
    private function __construct()
    {
    }

    /**
     * Reject any URL the server must not fetch, before a byte of I/O happens.
     *
     * Runs layer one (host allowlist) first because it is free and strict, then
     * layer two (DNS-rebind defence). Both throw the same exception type so
     * callers treat "wrong host" and "resolves internal" as one outcome: refuse.
     *
     * @throws InvalidArgumentException when the URL is not a fetchable provider URL.
     */
    public static function assertFetchable(string $url): void
    {
        if (preg_match(self::TMDB_URL_PATTERN, $url) !== 1) {
            throw new InvalidArgumentException(
                'Artwork fetch refused: URL is not an allowlisted provider image URL.',
            );
        }

        // Layer two: the hostname is allowlisted, but a poisoned resolver can
        // still point it at a loopback/link-local/metadata address.
        SsrfGuard::assertPublicUrl($url);
    }

    /**
     * Absolutise a redirect `Location` against the URL that produced it.
     *
     * Minimal RFC 3986 reference resolution for the only shapes real CDNs emit:
     * absolute http(s) URLs are returned normalised (`https://host/p`, no
     * userinfo — layer two re-checks every hop anyway), root-relative and
     * relative targets are resolved against the current URL's origin and path,
     * with `.`/`..` dot-segment removal.
     *
     * @return non-empty-string|null The absolute URL to fetch next, or null when
     *                     the target is unusable or uses a scheme other than
     *                     http(s) — null means STOP, never attempt the hop.
     */
    public static function resolveRedirect(string $base, string $location): ?string
    {
        $target = trim($location);
        if ($target === '' || str_contains($target, "\0")) {
            return null;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = strtolower($baseParts['scheme']);
        $authority = $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        // Absolute target: only http(s) may be followed; userinfo is dropped so
        // "https://internal@evil/" cannot masquerade as the allowed host later.
        if (preg_match('#^https?://#i', $target) === 1) {
            $targetParts = parse_url($target);
            if (!is_array($targetParts) || !isset($targetParts['host'], $targetParts['scheme'])) {
                return null;
            }

            $targetScheme = strtolower((string) $targetParts['scheme']);
            $targetAuthority = $targetParts['host']
                . (isset($targetParts['port']) ? ':' . $targetParts['port'] : '');
            $targetPath = isset($targetParts['path']) && $targetParts['path'] !== '' ? $targetParts['path'] : '/';

            return $targetScheme . '://' . $targetAuthority . $targetPath
                . (isset($targetParts['query']) ? '?' . $targetParts['query'] : '');
        }

        // Any OTHER explicit scheme (file:, gopher:, jar:, data:) is a STOP —
        // it must never fall through to relative resolution and be laundered
        // into a same-origin URL (a `file:///etc/passwd` Location that resolves
        // to `https://image.tmdb.org/.../file:/etc/passwd` would pass the
        // allowlist while the fetch itself is harmless; the inverse reader of
        // this code must not have to prove which case curls into which).
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) === 1) {
            return null;
        }

        // Scheme-relative "//host/path" — absolute it under the base scheme.
        if (str_starts_with($target, '//')) {
            return $scheme . ':' . $target;
        }

        // Root-relative: origin + target path, dot segments removed.
        if (str_starts_with($target, '/')) {
            return $scheme . '://' . $authority . self::removeDotSegments($target);
        }

        // Query- or fragment-only targets stay on the current document.
        if (str_starts_with($target, '?') || str_starts_with($target, '#')) {
            $currentPath = isset($baseParts['path']) && $baseParts['path'] !== '' ? $baseParts['path'] : '/';
            return $scheme . '://' . $authority . $currentPath . $target;
        }

        // Relative: resolve against the base path's directory.
        $basePath = isset($baseParts['path']) ? $baseParts['path'] : '/';
        $directory = str_ends_with($basePath, '/') ? $basePath : (dirname($basePath) . '/');

        return $scheme . '://' . $authority . self::removeDotSegments($directory . $target);
    }

    /**
     * RFC 3986 §5.2.4 dot-segment removal for an absolute path.
     */
    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($stack);
            } elseif ($segment !== '.' && $segment !== '') {
                $stack[] = $segment;
            }
        }

        $result = '/' . implode('/', $stack);
        return str_ends_with($path, '/') && $result !== '/' ? $result . '/' : $result;
    }
}
