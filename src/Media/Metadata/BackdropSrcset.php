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
 * Derives a responsive backdrop `srcset` (and a single large URL) from one TMDB
 * backdrop URL.
 *
 * Backdrops are stored as TMDB `/w500` URLs (see
 * {@see \Phlix\Media\Metadata\LibraryMetadataMatcher::imageUrl()}), which is too
 * small to fill a full-bleed detail-page background. TMDB serves the same
 * backdrop at many widths via the size path segment (`.../t/p/w1280/abc.jpg`),
 * so we can advertise larger resolutions — and surface a single large URL — with
 * no image proxy or re-encoding.
 *
 * The design mirrors {@see PosterSrcset} exactly: pure + side-effect-free,
 * host-anchored to `image.tmdb.org` (a spoofed URL is rejected), non-TMDB URLs
 * pass through as null so the client keeps the original `backdrop_url`.
 *
 * Backdrops are 16:9 landscape, so the advertised widths are larger than the
 * poster set (posters are ~2:3 portrait cards) — sized for hero/background use.
 *
 * TWO SIZE BUDGETS live here, and they are deliberately different:
 *
 *  - **Hero / full-bleed page background** ({@see self::largeUrl()},
 *    {@see self::forBackdropUrl()}) — the DETAIL response. ONE image fills the
 *    viewport, so `/original` is worth its 1.5–4 MB.
 *  - **List row strip** ({@see self::rowUrl()}, {@see self::rowSrcset()}) — the
 *    LIST response (`GET /api/v1/media`), which returns up to
 *    {@see \Phlix\Common\Http\PageLimit::MAX} rows per page. `/original` is
 *    NEVER advertised there: 100 rows of it is 150–400 MB of image traffic for
 *    a ~300 px-tall strip that can never use 2160 lines of detail. The row
 *    ladder therefore tops out at `w1280`, the largest TMDB step below
 *    `/original`.
 *
 * The two budgets share ONE width ladder ({@see self::WIDTHS}) and ONE srcset
 * code path: the hero srcset IS the row srcset with the `/original` candidate
 * appended ({@see self::forBackdropUrl()} delegates to {@see self::rowSrcset()}),
 * so tuning the ladder can never move one budget without the other.
 *
 * @see PosterSrcset The poster-side counterpart this mirrors.
 */
final class BackdropSrcset
{
    /**
     * The ONE TMDB backdrop width ladder, shared by BOTH size budgets (hero and
     * list row — see the class docblock). TMDB's whole backdrop ladder is
     * `w300, w780, w1280, original`, so these are the only two steps that land
     * in range:
     *
     *  - `w780` — a full-bleed phone row at 2× DPR (390 CSS px × 2 = 780), a
     *    780 CSS px row at 1×, and the tablet step of a full-bleed background.
     *  - `w1280` — a wide desktop row (≈1280–1400 CSS px) at 1×, a ~640 CSS px
     *    row at 2×, and the crispest step available without `/original`.
     *
     * `w300` is excluded (too small for even a phone row at 1×). `original` is
     * deliberately NOT a member: it is appended by {@see self::forBackdropUrl()}
     * alone, which is exactly what makes the hero ladder "this list PLUS
     * `/original`". Keep this list SHORT — it is serialised once per row on the
     * main library listing.
     *
     * @var list<int>
     */
    private const WIDTHS = [780, 1280];

    /**
     * Nominal width descriptor for the `/original` candidate that only the HERO
     * srcset carries ({@see self::forBackdropUrl()}). TMDB's `original` is the
     * full-resolution asset (typically ≥1920px wide for backdrops), so this is
     * advertised as the top width step.
     */
    private const ORIGINAL_WIDTH = 1920;

    /**
     * Width used for the single-URL list-row base ({@see self::rowUrl()}) — the
     * `src` a non-`srcset` client (Roku, the console TUI's `<img>` loader) ends
     * up fetching. Derived from the smallest {@see self::WIDTHS} step (so the
     * fallback is cheap, and so it cannot drift from the ladder).
     */
    private const ROW_WIDTH = self::WIDTHS[0];

    /**
     * Anchored to the TMDB image host + `/t/p/<size>/` so only genuine TMDB
     * image URLs are rewritten. `\S+` for the file segment forbids whitespace
     * (which would break a `srcset` candidate).
     */
    private const TMDB_URL = '#^(https?://image\.tmdb\.org/t/p)/(?:w\d+|original)/(\S+)$#';

    /**
     * Build the HERO width-descriptor `srcset` string (w780, w1280, original) for
     * a TMDB backdrop URL, or null when the URL is absent or not a TMDB image URL.
     *
     * This is EXACTLY {@see self::rowSrcset()} plus the `/original` candidate, and
     * it is written as delegation on purpose: the hero and row ladders must never
     * drift apart, and `/original` is the ONLY difference between the two budgets
     * (see the class docblock). The `original` candidate is advertised at a
     * nominal {@see self::ORIGINAL_WIDTH} so the browser treats it as the largest
     * step.
     */
    public static function forBackdropUrl(?string $backdropUrl): ?string
    {
        $rowSrcset = self::rowSrcset($backdropUrl);
        $original = self::largeUrl($backdropUrl);
        // Both derive from the same parse(), so they are null together; the
        // explicit pair-check keeps the concatenation below provably string-typed.
        if ($rowSrcset === null || $original === null) {
            return null;
        }

        return $rowSrcset . sprintf(', %s %dw', $original, self::ORIGINAL_WIDTH);
    }

    /**
     * Width-swap a TMDB backdrop URL to its full-resolution `/original` variant
     * for a full-bleed page background, or null when the URL is absent or not a
     * TMDB image URL (the client then falls back to the original `backdrop_url`).
     */
    public static function largeUrl(?string $backdropUrl): ?string
    {
        $parts = self::parse($backdropUrl);
        if ($parts === null) {
            return null;
        }
        [$base, $file] = $parts;

        return sprintf('%s/original/%s', $base, $file);
    }

    /**
     * Build the SHORT, row-appropriate `srcset` (w780 + w1280 — no `/original`)
     * for a LIST-row backdrop strip, or null when the URL is absent or not a TMDB
     * image URL (the client then uses {@see self::rowUrl()}'s single URL).
     *
     * This is the SINGLE ladder-building code path: {@see self::forBackdropUrl()}
     * calls it and appends `/original` for the hero budget. It advertises
     * `/original` itself NEVER, because it serves up to
     * {@see \Phlix\Common\Http\PageLimit::MAX} rows per response — see the class
     * docblock for the payload argument.
     */
    public static function rowSrcset(?string $backdropUrl): ?string
    {
        $parts = self::parse($backdropUrl);
        if ($parts === null) {
            return null;
        }
        [$base, $file] = $parts;

        $candidates = [];
        foreach (self::WIDTHS as $width) {
            $candidates[] = sprintf('%s/w%d/%s %dw', $base, $width, $file, $width);
        }

        return implode(', ', $candidates);
    }

    /**
     * Width-swap a TMDB backdrop URL to the row-sized {@see self::ROW_WIDTH}
     * variant for a list-row backdrop strip, or null when the URL is absent or
     * not a TMDB image URL.
     *
     * Backdrops are STORED at `/w500` (see
     * {@see \Phlix\Media\Metadata\LibraryMetadataMatcher::imageUrl()}), which is
     * narrower than a row strip needs, so the base is stepped up to `w780`
     * rather than left as-is. TMDB resizes from the master asset, so stepping a
     * `/w500` URL UP to `/w780` is a genuine higher-resolution render, not an
     * upscale. Callers fall back to the stored URL on null so a non-TMDB
     * (fanart.tv, locally-cached) backdrop still renders.
     */
    public static function rowUrl(?string $backdropUrl): ?string
    {
        $parts = self::parse($backdropUrl);
        if ($parts === null) {
            return null;
        }
        [$base, $file] = $parts;

        return sprintf('%s/w%d/%s', $base, self::ROW_WIDTH, $file);
    }

    /**
     * Parse a TMDB backdrop URL into its `[base, file]` segments, or null when it
     * is absent / not a TMDB image URL / carries a `srcset`-hostile file segment.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parse(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match(self::TMDB_URL, $url, $matches) !== 1) {
            return null;
        }

        $file = $matches[2];
        // `srcset` candidates are comma+space separated — a file segment must
        // carry neither. TMDB segments never do; bail defensively otherwise so we
        // never emit a malformed srcset.
        if (str_contains($file, ',') || str_contains($file, ' ')) {
            return null;
        }

        return [$matches[1], $file];
    }
}
