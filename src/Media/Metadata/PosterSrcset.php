<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata;

/**
 * Derives a responsive poster `srcset` from a single TMDB poster URL.
 *
 * TMDB serves the same poster at many widths via the size path segment
 * (`.../t/p/w500/abc.jpg`), so we can advertise several resolutions to the
 * browser — it fetches the one that fits the device — without any image proxy or
 * re-encoding. Pure + side-effect-free, so it is directly unit-testable.
 *
 * Non-TMDB poster URLs (local files, other providers) return null, so the client
 * keeps the single `poster_url` and the rendered markup is byte-identical. The
 * host is anchored to `image.tmdb.org`, so a spoofed URL
 * (`https://evil.com/image.tmdb.org/...`) is rejected.
 *
 * @see \Phlix\Media\Metadata\MovieMetadataResolver poster_url is built as
 *      "https://image.tmdb.org/t/p/w500" . posterPath
 */
final class PosterSrcset
{
    /**
     * TMDB poster widths to advertise. The browse cards render ~160–200 CSS px,
     * so this spans roughly 1× through 3× device-pixel-ratio.
     *
     * @var list<int>
     */
    private const WIDTHS = [185, 342, 500, 780];

    /**
     * Anchored to the TMDB image host + `/t/p/<size>/` so only genuine TMDB
     * image URLs are rewritten. `\S+` for the file segment forbids whitespace
     * (which would break a `srcset` candidate).
     */
    private const TMDB_URL = '#^(https?://image\.tmdb\.org/t/p)/(?:w\d+|original)/(\S+)$#';

    /**
     * Build a width-descriptor `srcset` string for a TMDB poster URL, or null
     * when the URL is absent or not a TMDB image URL.
     */
    public static function forPosterUrl(?string $posterUrl): ?string
    {
        if ($posterUrl === null || $posterUrl === '') {
            return null;
        }

        if (preg_match(self::TMDB_URL, $posterUrl, $matches) !== 1) {
            return null;
        }

        $base = $matches[1];
        $file = $matches[2];

        // `srcset` candidates are comma+space separated — a file segment must
        // carry neither. TMDB segments never do; bail defensively if one ever
        // does so we never emit a malformed srcset.
        if (str_contains($file, ',') || str_contains($file, ' ')) {
            return null;
        }

        $candidates = [];
        foreach (self::WIDTHS as $width) {
            $candidates[] = sprintf('%s/w%d/%s %dw', $base, $width, $file, $width);
        }

        return implode(', ', $candidates);
    }
}
