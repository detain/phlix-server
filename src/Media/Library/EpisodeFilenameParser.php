<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Media\Metadata\TitleSuffixStripper;

/**
 * Parses a media filename into series / season / episode parts.
 *
 * Real-world libraries (especially anime) name episodes many ways, so a single
 * `S01E02` regex misses most of them. This parser recognises, in priority order:
 *
 *  1. Season+episode markers — `S01E02`, `S01 E02`, `S1EP17`, `S02.E03`,
 *     `S05 E16-E17` (range → first), `S02SP03` (specials).
 *  2. `1x02` style markers.
 *  3. Absolute / sequential numbering — `Naruto Shippuden - 394`,
 *     `Ranma_-_098`, `Bleach 125`, `Show - E29`, `Show - Ep. 04`. These have no
 *     season, so they bucket into season 1. Only attempted when `$allowAbsolute`
 *     is set (true for series libraries) so a movie like `Blade Runner 2049`
 *     in a movie library is never mistaken for episode 2049.
 *
 * Returns null when the name is not recognisably an episode (a movie, a special
 * with no number, etc.). Group tags (`[AnimeRG]`) and quality tags
 * (`[720p]`, `(BD1080p…)`) are stripped from the series title and episode title.
 */
final class EpisodeFilenameParser
{
    /**
     * Media-container extensions this parser will strip from a filename. Kept
     * deliberately small: a blind {@see pathinfo()} `PATHINFO_FILENAME` truncates
     * at the LAST dot, so a series whose title contains a dot ("Dr. Stone",
     * "D.Gray-man", "Gangsta.") loses everything after it ("Dr. Stone S01E05 …" →
     * "Dr") and never matches a SxxExx marker — every episode then files as a
     * stray movie. Stripping only a recognised trailing extension avoids that,
     * and is also idempotent: the scanner already strips the extension before
     * calling parse(), so the second pass here is a harmless no-op.
     *
     * @var list<string>
     */
    private const MEDIA_EXTENSIONS = [
        'mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'm2ts', 'mts',
        'mpg', 'mpeg', 'ts', '3gp', 'ogm', 'ogv', 'divx', 'xvid', 'vob', 'rmvb',
        'asf', 'm4a', 'mp3', 'flac', 'aac', 'ogg', 'opus', 'wav', 'wma',
    ];

    /**
     * @param string            $filename      Raw filename (with or without extension).
     * @param bool              $allowAbsolute Allow absolute-numbering fallbacks.
     * @param list<string>|null $noiseSuffixes Effective trailing-edition noise list
     *                                          applied to the SERIES segment via
     *                                          {@see TitleSuffixStripper::strip()}.
     *                                          When null (default) the built-in
     *                                          {@see TitleSuffixStripper::NOISE_SUFFIXES}
     *                                          const is used, so callers that do not
     *                                          inject an admin-extended list keep the
     *                                          canonical behavior. Episode titles are
     *                                          never noise-stripped.
     *
     * @return array{series: string, season: int, episode: int, episode_title: ?string}|null
     */
    public static function parse(string $filename, bool $allowAbsolute = false, ?array $noiseSuffixes = null): ?array
    {
        $base = self::stripExtension($filename);
        // Underscores are a scene separator; normalise so "Ranma_-_098" parses
        // like "Ranma - 098". Keep a normalised copy for matching.
        $norm = (string) preg_replace('/_+/', ' ', $base);
        $norm = (string) preg_replace('/\s+/', ' ', $norm);
        $norm = trim($norm);

        // Strip a leading release-group tag: "[AnimeRG] Pokémon - ..." → "Pokémon - ...".
        $norm = (string) preg_replace('/^\s*\[[^\]]*\]\s*/', '', $norm);

        // 1. Season + episode: S01E02 / S01 E02 / S1EP17 / S02.E03 / S02SP03 / S05 E16-E17.
        if (preg_match('/^(.+?)[\s._-]*S(\d{1,2})\s*[._x\- ]?\s*(?:EP|SP|E)\s*(\d{1,3})/i', $norm, $m, PREG_OFFSET_CAPTURE)) {
            return self::build($m[1][0], (int) $m[2][0], (int) $m[3][0], self::remainder($norm, $m), $noiseSuffixes);
        }

        // 2. 1x02 style.
        if (preg_match('/^(.+?)[\s._-]+(\d{1,2})x(\d{1,3})\b/i', $norm, $m, PREG_OFFSET_CAPTURE)) {
            return self::build($m[1][0], (int) $m[2][0], (int) $m[3][0], self::remainder($norm, $m), $noiseSuffixes);
        }

        if ($allowAbsolute) {
            // 3a. Dash-delimited absolute: "Title - 394", "Title - E29", "Title - Ep. 04".
            if (preg_match('/^(.+?)\s[-–]\s*(?:Episode|Ep\.?|EP|E)?\s*(\d{1,4})(?:v\d+)?(?=$|[\s\[\(.\-])/i', $norm, $m, PREG_OFFSET_CAPTURE)) {
                return self::build($m[1][0], 1, (int) $m[2][0], self::remainder($norm, $m), $noiseSuffixes);
            }
            // 3b. Space-delimited trailing number: "Bleach 125", "Show E29".
            if (preg_match('/^(.+?)\s(?:Episode|Ep\.?|EP|E)?\s*(\d{1,4})(?:v\d+)?(?=$|[\s\[\(])/i', $norm, $m, PREG_OFFSET_CAPTURE)) {
                return self::build($m[1][0], 1, (int) $m[2][0], self::remainder($norm, $m), $noiseSuffixes);
            }
        }

        return null;
    }

    /**
     * Strip a trailing media-container extension, and ONLY that — never a dot
     * inside the title. Returns the name unchanged when the trailing token is
     * not a recognised media extension (see {@see MEDIA_EXTENSIONS}).
     */
    private static function stripExtension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return $filename;
        }

        $ext = strtolower(substr($filename, $dot + 1));
        if (in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            return substr($filename, 0, $dot);
        }

        return $filename;
    }

    /**
     * The text following the matched marker, used to pull the episode title.
     *
     * @param array<int, array{0: string, 1: int}> $m preg match with offsets.
     */
    private static function remainder(string $norm, array $m): string
    {
        $end = $m[0][1] + strlen($m[0][0]);
        return substr($norm, $end);
    }

    /**
     * Assemble a result, cleaning the series title and pulling an episode title
     * (the free text that follows the marker, minus quality tags) if present.
     *
     * @param list<string>|null $noiseSuffixes Effective noise list for the series segment.
     *
     * @return array{series: string, season: int, episode: int, episode_title: ?string}
     */
    private static function build(
        string $rawSeries,
        int $season,
        int $episode,
        string $remainder,
        ?array $noiseSuffixes = null
    ): array {
        return [
            'series' => self::cleanSeries($rawSeries, $noiseSuffixes),
            'season' => $season,
            'episode' => $episode,
            'episode_title' => self::extractEpisodeTitle($remainder),
        ];
    }

    /**
     * Clean a series title: drop trailing separators/markers, any quality tag
     * that bled into the capture, and trailing edition/noise suffixes
     * ("Directors Cut", "UNCUT & UNRATED", "YIFY"…) via the shared
     * {@see TitleSuffixStripper} so the show title matches metadata cleanly.
     *
     * @param list<string>|null $noiseSuffixes Effective noise list (null → const default).
     */
    private static function cleanSeries(string $raw, ?array $noiseSuffixes = null): string
    {
        $title = trim($raw);
        // Cut anything from the first bracket/paren tag onward.
        $title = (string) preg_replace('/\s*[\[\(].*$/', '', $title);
        $title = self::trimSeparators($title);
        // Peel trailing edition/noise phrases (never emptying the title, so a
        // show literally named after a noise token survives).
        $title = TitleSuffixStripper::strip($title, false, $noiseSuffixes);
        return $title;
    }

    /**
     * Pull the episode title from the text following the marker: strip the
     * leading separator, cut at the first bracket/paren tag, and trim. Returns
     * null when nothing meaningful remains (or it is just a number/tag), e.g.
     * "Naruto - 394 [720p]" or "Bleach - 160 -".
     */
    private static function extractEpisodeTitle(string $remainder): ?string
    {
        $title = self::ltrimSeparators($remainder);
        // Cut at the first bracket/paren tag.
        $title = (string) preg_replace('/\s*[\[\(].*$/', '', $title);
        $title = self::trimSeparators($title);
        if ($title === '' || preg_match('/^\d+$/', $title)) {
            return null;
        }
        return $title;
    }

    /**
     * Character class for the separators we strip from the ends of a title:
     * whitespace, ASCII hyphen, en/em dash, period, underscore.
     */
    private const SEPARATOR_CLASS = '[\s._\x{2013}\x{2014}-]';

    /**
     * Trim leading + trailing separators. Uses a `/u` regex (NOT trim() with a
     * byte mask): trim()'s mask is matched byte-by-byte, so a multibyte
     * character in the mask — the en-dash "–" (E2 80 93) — lets it strip the
     * E2/80 lead bytes off an adjacent multibyte character (e.g. a curly quote
     * " = E2 80 9C), leaving an invalid lone byte that then fails to insert
     * into a utf8mb4 column with MySQL error 1366.
     */
    private static function trimSeparators(string $value): string
    {
        return (string) preg_replace(
            '/^' . self::SEPARATOR_CLASS . '+|' . self::SEPARATOR_CLASS . '+$/u',
            '',
            $value
        );
    }

    /** Trim leading separators only (see {@see trimSeparators()}). */
    private static function ltrimSeparators(string $value): string
    {
        return (string) preg_replace('/^' . self::SEPARATOR_CLASS . '+/u', '', $value);
    }
}
