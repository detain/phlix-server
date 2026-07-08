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
 * Classifies an immediate subdirectory of a per-series directory into the role
 * it plays in the series → season → episode hierarchy.
 *
 * A series library organised "one directory per series" often nests season
 * subdirectories inside each series directory:
 *
 *   Series (2000)/
 *     Season 1/            → ['type' => 'season',   'season' => 1]
 *     Season 02 - Baby Saga/ → ['type' => 'season', 'season' => 2]
 *     Specials/            → ['type' => 'specials']            (season 0)
 *     OVAs/                → ['type' => 'specials']            (season 0)
 *     Movies (1993-98)/    → ['type' => 'loose']  (holds media, no forced season)
 *     Other Shows You'd Like, HERE/ → ['type' => 'skip']      (junk pointer dir)
 *
 * The classifier is a PURE function of the directory basename PLUS an optional
 * "does this directory contain any media?" hint (used only to disambiguate the
 * junk-vs-loose case): a name that matches no season/specials pattern and looks
 * like a "you might also like" pointer folder — or has no media beneath it at
 * all — is skipped; anything else that carries media is treated as loose.
 *
 * Season-number extraction is deliberately forgiving: a trailing year range,
 * subtitle, or letter suffix ("3b", "3a") is ignored — only the numeric season
 * index is kept ("Season 3b - Movie (1990)" → 3).
 *
 * Pure static helpers, no I/O, no state — safe under Workerman resident memory.
 * {@see MediaScanner::scanSeriesPerDirectory()} delegates to this so the same
 * logic is directly unit-testable.
 */
final class SeasonDirectoryClassifier
{
    /**
     * Directory-name substrings that mark a subdirectory as a "you might also
     * like" / pointer / related-content folder rather than a real season. Used
     * only as one input to the junk heuristic (see {@see self::classify()}).
     *
     * @var array<int, string>
     */
    private const JUNK_KEYWORDS = [
        'like',
        'other',
        'others',
        'themes',
        'related',
        'shows with',
        'cartoons you',
        'you might',
    ];

    private function __construct()
    {
    }

    /**
     * Classify a subdirectory basename.
     *
     * @param string    $dirName  Immediate-subdirectory basename (no path).
     * @param bool|null $hasMedia Whether the directory (recursively) contains any
     *                            scannable media file. When null, the media check
     *                            is skipped and a non-season/non-junk directory is
     *                            treated as 'loose' (conservative default). When
     *                            false AND the name is not a season/specials, the
     *                            directory is 'skip' (empty → nothing to scan).
     *
     * @return array{type: string, season?: int} One of:
     *   - `['type' => 'season', 'season' => N]` (N >= 1),
     *   - `['type' => 'specials']` (season 0),
     *   - `['type' => 'loose']` (scan its files without a forced season),
     *   - `['type' => 'skip']` (junk / empty — do not scan).
     */
    public static function classify(string $dirName, ?bool $hasMedia = null): array
    {
        // Normalise scene separators (dots/underscores) to spaces and collapse
        // runs so "Law.And.Order...S01.COMPLETE" tokenises like a spaced name.
        $norm = (string) preg_replace('/[._]+/', ' ', $dirName);
        $norm = (string) preg_replace('/\s+/', ' ', $norm);
        $norm = trim($norm);
        $lower = strtolower($norm);

        // --- Specials (season 0) ------------------------------------------
        // Explicit "Season 0"/"Season 00"/"S00", the words Special(s)/Extras,
        // and OVA(s) all map to the Specials container (season 0). Checked
        // BEFORE the numeric-season branch so "Season 00" is not read as N=0
        // via the general parser and then re-classified.
        if (self::isSpecials($lower)) {
            return ['type' => 'specials'];
        }

        // --- Season N (N >= 1) --------------------------------------------
        $season = self::extractSeasonNumber($lower);
        if ($season !== null && $season >= 1) {
            return ['type' => 'season', 'season' => $season];
        }

        // --- Junk / pointer dirs ------------------------------------------
        // Not a season/specials. Skip when it strongly looks like a "you might
        // also like" pointer folder, OR when it provably holds no media.
        if (self::looksLikeJunk($lower) || $hasMedia === false) {
            return ['type' => 'skip'];
        }

        // --- Loose ---------------------------------------------------------
        // Contains (or may contain) media but is not a season — scan its files
        // without forcing a season (falls back to filename parsing as today).
        return ['type' => 'loose'];
    }

    /**
     * Whether a normalised, lower-cased directory name denotes the Specials
     * (season 0) container: explicit season-zero markers, Special(s)/Extras, or
     * OVA(s).
     */
    private static function isSpecials(string $lower): bool
    {
        // Explicit zero-season markers: "season 0", "season 00", "s0", "s00".
        if (preg_match('/^(?:season\s*0+|s0+)(?:\b|$)/', $lower) === 1) {
            return true;
        }

        // Keyword-based specials buckets. Word-boundary anchored so "specialist"
        // or "novable" can't false-match; "ova"/"ovas" as a standalone token.
        if (preg_match('/\b(?:specials?|extras?|ovas?)\b/', $lower) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Extract the numeric season index from a normalised, lower-cased directory
     * name, ignoring trailing letter suffixes ("3b" → 3), year ranges, and
     * subtitles. Returns null when no season marker is present.
     *
     * Recognised forms (case-insensitive on the already-lowered input):
     *   - "season 1", "season 01", "season 13 zanpakutō the alternate tale"
     *   - "season 3b - movie (1990)", "season 3a (1989)"  (letter suffix dropped)
     *   - "s01", "s1", "pokémon s18 xy kalos quest"
     *   - "law and order organized crime s01 complete 720p"
     */
    private static function extractSeasonNumber(string $lower): ?int
    {
        // "season <N>" anywhere in the name (a leading show title is fine). The
        // optional trailing letter ("3b"/"3a") is matched-and-discarded so only
        // the numeric index is captured.
        if (preg_match('/\bseason\s*(\d{1,3})[a-z]?\b/', $lower, $m) === 1) {
            return (int) $m[1];
        }

        // "s<NN>" scene-style marker ("s01", "s18"). Require at least one digit;
        // a bare "s" is not a season. Anchored on a word boundary so it does not
        // fire mid-word (e.g. "gutsy").
        if (preg_match('/\bs(\d{1,3})\b/', $lower, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Whether a normalised, lower-cased directory name strongly resembles a junk
     * / "you might also like" pointer folder rather than a season.
     *
     * Heuristic (conservative): the name carries a comma-delimited "…, here"/
     * "…, HERE" tail OR a trailing ", here", OR it contains one of the pointer
     * keywords ({@see self::JUNK_KEYWORDS}). A dir that carries media but matches
     * none of these is treated as 'loose', never skipped, by the caller.
     */
    private static function looksLikeJunk(string $lower): bool
    {
        // Trailing ", here" tail (e.g. "Related Cartoons, Here",
        // "Themes, HERE") — a strong pointer-folder signal.
        if (preg_match('/,\s*here\b/', $lower) === 1) {
            return true;
        }

        foreach (self::JUNK_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
