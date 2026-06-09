<?php

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
     * @param string $libraryId Owning library UUID.
     * @param string $title     Series title.
     * @return string e.g. "series:<libraryId>:<slug>".
     */
    public static function seriesPath(string $libraryId, string $title): string
    {
        return "series:{$libraryId}:" . self::slug($title);
    }

    /**
     * Synthetic path for a season container.
     *
     * @param string $libraryId Owning library UUID.
     * @param string $title     Series title.
     * @param int    $season    Season number (0 = Specials).
     * @return string e.g. "season:<libraryId>:<slug>:<season>".
     */
    public static function seasonPath(string $libraryId, string $title, int $season): string
    {
        return "season:{$libraryId}:" . self::slug($title) . ":{$season}";
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
}
