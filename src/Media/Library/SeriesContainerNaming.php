<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

/**
 * Deterministic addressing for the synthetic `series` / `season` container rows
 * that group episodes into a series → season → episode hierarchy.
 *
 * Both the live {@see MediaScanner} (building the tree as it scans) and the
 * one-off `scripts/backfill-series-hierarchy.php` (reorganising already-scanned
 * rows in place) MUST produce byte-identical container paths so that a later
 * scan resolves to the SAME rows via {@see ItemRepository::findByPath()} instead
 * of creating duplicate shows. Keeping the scheme in one place guarantees that.
 *
 * Containers carry no real file path, so a synthetic `series:`/`season:` path is
 * used purely as a stable lookup key (real media paths are absolute and start
 * with `/`, so there is no collision).
 */
final class SeriesContainerNaming
{
    /**
     * Stable slug for a series title: lower-cased, with runs of non-alphanumerics
     * collapsed to single hyphens and edge hyphens trimmed.
     *
     * @param string $title Series title.
     * @return string Slug, or 'unknown' when nothing alphanumeric remains.
     */
    public static function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug === '' ? 'unknown' : $slug;
    }

    /**
     * Synthetic path for a series container.
     *
     * @param string      $libraryId  Owning library UUID.
     * @param string      $title      Series title.
     * @param string|null $slugSource When non-null, this string is slugged for
     *        the path instead of $title. Used by the series-per-directory branch
     *        so distinct sibling folders ("The Office (2005)" vs
     *        "The Office (2001)", "Re:Zero" vs "Re Zero") never collide into one
     *        container: passing the FULL directory basename keeps them apart.
     *        The legacy (flag-false) path passes null → byte-identical behaviour.
     * @return string e.g. "series:<libraryId>:<slug>".
     */
    public static function seriesPath(string $libraryId, string $title, ?string $slugSource = null): string
    {
        return "series:{$libraryId}:" . self::slug($slugSource ?? $title);
    }

    /**
     * Synthetic path for a season container.
     *
     * @param string      $libraryId  Owning library UUID.
     * @param string      $title      Series title.
     * @param int         $season     Season number (0 = Specials).
     * @param string|null $slugSource When non-null, slugged instead of $title so
     *        the season path stays bound to the same disambiguated series as its
     *        {@see self::seriesPath()} (see that method's note). null → legacy.
     * @return string e.g. "season:<libraryId>:<slug>:<season>".
     */
    public static function seasonPath(
        string $libraryId,
        string $title,
        int $season,
        ?string $slugSource = null
    ): string {
        return "season:{$libraryId}:" . self::slug($slugSource ?? $title) . ":{$season}";
    }

    /**
     * Human-readable label for a season number.
     *
     * @param int $season Season number (0 or negative = Specials).
     * @return string "Season N" or "Specials".
     */
    public static function seasonLabel(int $season): string
    {
        return $season > 0 ? "Season {$season}" : 'Specials';
    }

    /**
     * Parse a series directory basename into a clean title + optional year.
     *
     * Used when a series library declares "each series lives in its own
     * top-level directory" — the FOLDER name (not the episode filenames) is the
     * authoritative series title/year. Only a TRAILING parenthetical/bracket that
     * is a 4-digit year (or year range) is stripped from the title; non-year
     * trailing tags like "(US)" or "(Uncut)" are LEFT IN PLACE because they help
     * disambiguate distinct shows. Examples:
     *
     *  - "Assassination Classroom (2013)"   → ['title' => 'Assassination Classroom', 'year' => 2013]
     *  - "Cowboy Bebop [1998]"                → ['title' => 'Cowboy Bebop',            'year' => 1998]
     *  - "Foo (US) (2018)"                    → ['title' => 'Foo (US)',                'year' => 2018]
     *  - "The Bridge (US)"                    → ['title' => 'The Bridge (US)',         'year' => null]
     *  - "Bleach"                              → ['title' => 'Bleach',                  'year' => null]
     *
     * Underscores/dots used as scene separators are normalised to spaces.
     *
     * @param string $basename Directory basename (no path).
     * @return array{title: string, year: int|null} Parsed title (falls back to the
     *         trimmed basename) and year (null when none found / out of range).
     */
    public static function fromDirectoryName(string $basename): array
    {
        // Normalise scene separators to spaces, collapse runs.
        $norm = (string) preg_replace('/[._]+/', ' ', $basename);
        $norm = (string) preg_replace('/\s+/', ' ', $norm);
        $norm = trim($norm);

        $year = null;
        $title = $norm;

        // Repeatedly peel a TRAILING ()/[]-wrapped tag, but ONLY when it is a
        // 4-digit year (or a "YYYY-YYYY"/"YYYY–YYYY" range — first year wins).
        // This keeps non-year trailing tags like "(US)" / "(Uncut)" as part of
        // the title (they disambiguate distinct shows). A wrapped year anywhere
        // earlier in the name is left untouched.
        $yearTag = '/\s*[\(\[]\s*(\d{4})(?:\s*[-–]\s*\d{4})?\s*[\)\]]\s*$/';
        while (preg_match($yearTag, $title, $m) === 1) {
            $candidate = (int) $m[1];
            // Strip the tag from the title regardless, but only record it as the
            // year when it is in range; the rightmost (first peeled) wins.
            $title = (string) preg_replace($yearTag, '', $title);
            if ($year === null && $candidate >= 1900 && $candidate <= 2100) {
                $year = $candidate;
            }
        }

        // /u regex, NOT trim() with a byte mask: the en-dash "–" in a trim
        // mask is matched byte-wise and can strip the lead bytes off an
        // adjacent multibyte character, producing invalid UTF-8 (MySQL 1366).
        $title = (string) preg_replace('/^[\s._\x{2013}\x{2014}-]+|[\s._\x{2013}\x{2014}-]+$/u', '', $title);

        if ($title === '') {
            $title = trim($norm);
        }

        return ['title' => $title, 'year' => $year];
    }
}
