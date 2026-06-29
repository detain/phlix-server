<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Aggressive, deterministic dedup key for top-level media items (series and
 * movies). Used to detect that two rows whose synthetic paths differ — because
 * their titles slugged differently (separators, year bleed, a parse failure, or
 * a flat→per-directory re-scan) — are in fact the SAME show/film, so they can be
 * merged into one container.
 *
 * This is INTENTIONALLY more aggressive than {@see SeriesContainerNaming::slug()}:
 * `slug()` is the stable PATH-addressing scheme (it preserves word boundaries as
 * hyphens, so "Hunter x Hunter" and "HunterxHunter" stay DISTINCT paths). A
 * canonical key instead collapses ALL non-alphanumerics AND drops leading
 * articles so those same titles converge to one key. `slug()` is left unchanged;
 * this class is a separate, purely-additive primitive.
 *
 * Pure: no DB, no state, no I/O.
 */
final class CanonicalKey
{
    /**
     * Leading articles stripped before normalising, so "The Matrix" === "Matrix".
     *
     * @var list<string>
     */
    private const LEADING_ARTICLES = ['the', 'a', 'an'];

    /**
     * Aggressively normalise a title into a comparison key: lower-cased, leading
     * article removed, every non-alphanumeric (including spaces) dropped.
     *
     * Examples (all → "hunterxhunter"):
     *   "Hunter x Hunter", "Hunter.x.Hunter", "HunterxHunter".
     * "The Matrix" and "Matrix" both → "matrix".
     *
     * @param string $title Raw title.
     * @return string Normalised key (may be '' when nothing alphanumeric remains).
     */
    public static function forTitle(string $title): string
    {
        $key = strtolower(trim($title));

        // Strip a single leading article followed by a separator, e.g. "the matrix".
        foreach (self::LEADING_ARTICLES as $article) {
            $prefix = $article . ' ';
            if (str_starts_with($key, $prefix)) {
                $key = substr($key, strlen($prefix));
                break;
            }
        }

        // Collapse everything that is not [a-z0-9] to nothing (drops spaces,
        // dots, hyphens, apostrophes, colons, etc.).
        return (string) preg_replace('/[^a-z0-9]+/', '', $key);
    }

    /**
     * Strongest available dedup key for a top-level item.
     *
     * Preference order:
     *   1. A matched provider id — `imdb:<id>` wins over `tmdb:<id>` (imdb ids
     *      are globally unique; tmdb ids are per-namespace but still strong).
     *   2. `<title-key>:<year>` when a year is known.
     *   3. `<title-key>` alone.
     *
     * @param string                     $title       Raw title.
     * @param int|null                   $year         Release year, when known.
     * @param array<string, string|int|null> $externalIds Assoc map of provider
     *        ids, e.g. ['imdb' => 'tt0123', 'tmdb' => 456]. Missing/empty keys
     *        are ignored.
     * @return string The strongest non-empty key.
     */
    public static function forItem(string $title, ?int $year, array $externalIds): string
    {
        // Prefer an imdb id, then tmdb — strongest signal regardless of title.
        foreach (['imdb', 'tmdb'] as $provider) {
            $id = self::cleanExternalId($externalIds[$provider] ?? null);
            if ($id !== '') {
                return $provider . ':' . $id;
            }
        }

        $titleKey = self::forTitle($title);

        if ($year !== null) {
            return $titleKey . ':' . $year;
        }

        return $titleKey;
    }

    /**
     * Normalise an external-id value to a trimmed string, treating
     * null/empty/whitespace-only as absent.
     *
     * @param string|int|null $value Raw provider id.
     * @return string Trimmed id, or '' when absent.
     */
    private static function cleanExternalId(string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
