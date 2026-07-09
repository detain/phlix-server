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
 * Parses dirty scene release filenames into a clean {title, year} tuple.
 *
 * This normalizer handles a wide variety of release naming conventions used
 * by scene groups, P2P networks, and aggregators (YTS,RARBG,EV0LVE,etc.).
 * Its output is suitable for TMDB/IMDb lookup, dramatically improving match
 * rates for files that would otherwise fail due to noisy filenames.
 *
 * The normalizer is a pure utility — no side effects, no DB access, no
 * external dependencies.
 *
 * @package Phlix\Media\Metadata
 * @since 0.21.0
 */
final class SceneFilenameNormalizer
{
    /**
     * @var list<string> Quality/codec tokens that signal release info.
     */
    private const QUALITY_TOKENS = [
        '1080p',
        '720p',
        '2160p',
        '4k',
        'UHD',
        'HDTV',
        'WEBRip',
        'WEB-DL',
        'BluRay',
        'BRRip',
        'HDRip',
        'DVDRip',
        'DVDrip',
        'DVD',
        'x264',
        'x265',
        'X264',
        'X265',
        'HEVC',
        'H264',
        'H265',
        'AVC',
        'AAC',
        'AC3',
        'DTS',
        'DDP5',
        'DD5',
        'TRUEHD',
        'MA',
        '10bit',
        '8bit',
        '12bit',
        'REMUX',
        'PROPER',
        'REPACK',
        'EXTENDED',
        'THEATRICAL',
        'FINAL',
        'REMASTERED',
        'UNRATED',
        'LIMITED',
        'RERIP',
        'NFO',
        'FIX',
    ];

    /**
     * @var list<string> File extensions to strip before processing.
     */
    private const EXTENSIONS = [
        'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v',
        'mpg', 'mpeg', 'ts', '3gp', 'ogm', 'divx', 'xvid',
    ];

    /**
     * @var string Pattern matching a realistic movie year (1900-2099).
     */
    private const YEAR_PATTERN = '/\b(19\d{2}|20\d{2})\b/';

    /**
     * Normalize a dirty release filename.
     *
     * @param string            $filename      Raw filename (with or without extension).
     * @param list<string>|null $noiseSuffixes Effective trailing-edition noise list
     *                                          to peel (passed straight to
     *                                          {@see TitleSuffixStripper::strip()}).
     *                                          When null (default) the built-in
     *                                          {@see TitleSuffixStripper::NOISE_SUFFIXES}
     *                                          const is used, so callers that do not
     *                                          inject an admin-extended list keep the
     *                                          canonical behavior.
     *
     * @return array{title: string, year: int|null, raw: string} Cleaned
     *         title, extracted year (or null), and the original filename.
     */
    public static function normalize(string $filename, ?array $noiseSuffixes = null): array
    {
        $raw = $filename;
        $lower = mb_strtolower($filename, 'UTF-8');

        foreach (self::EXTENSIONS as $ext) {
            if (str_ends_with($lower, '.' . $ext)) {
                $filename = substr($filename, 0, -strlen($ext) - 1);
                $lower = mb_strtolower($filename, 'UTF-8');
                break;
            }
        }

        $cleaned = $filename;
        $cleaned = preg_replace('/[._]/', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        if (preg_match('/^(.+?)\s*[\(\[]\s*(\d{4})\s*[\)\]]$/', $cleaned, $bracketMatch)) {
            $bracketYear = (int) $bracketMatch[2];
            // Only treat a bracketed (YYYY) as the year when it is plausible — an
            // out-of-range value (e.g. "Avatar (1899)") is noise that would poison the
            // IMDb ±1-year window, so fall through to the general handling below.
            if ($bracketYear >= 1900 && $bracketYear <= 2099) {
                $titlePart = trim($bracketMatch[1]);
                $titlePart = self::stripGroupSuffix($titlePart);
                $title = self::stripBracketedTags($titlePart);
                $title = TitleSuffixStripper::strip($title, false, $noiseSuffixes);
                $title = preg_replace('/\s+/', ' ', $title) ?? $title;
                $title = trim($title);

                return [
                    'title' => $title !== '' ? $title : $cleaned,
                    'year' => $bracketYear,
                    'raw' => $raw,
                ];
            }
        }

        $yearPositions = [];
        if (preg_match_all(self::YEAR_PATTERN, $cleaned, $yearMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($yearMatches[1] as $match) {
                $year = (int) $match[0];
                if ($year >= 1900 && $year <= 2099) {
                    $yearPositions[] = [
                        'year' => $year,
                        'offset' => $match[1],
                    ];
                }
            }
        }

        $year = null;
        $title = $cleaned;

        if (count($yearPositions) >= 2) {
            $year1 = $yearPositions[0];
            $year2 = $yearPositions[1];

            $afterYear1 = trim(substr($cleaned, $year1['offset'] + 4));
            $afterYear2 = trim(substr($cleaned, $year2['offset'] + 4));

            $year1FirstToken = self::getFirstToken($afterYear1);
            $year2FirstToken = self::getFirstToken($afterYear2);

            $year1FollowedByQuality = $year1FirstToken !== null && self::isQualityToken($year1FirstToken);
            $year2FollowedByQuality = $year2FirstToken !== null && self::isQualityToken($year2FirstToken);

            if ($year1FollowedByQuality && !$year2FollowedByQuality) {
                $title = trim(substr($cleaned, 0, $year1['offset']));
                $year = $year1['year'];
            } elseif (!$year1FollowedByQuality && $year2FollowedByQuality) {
                $title = trim(substr($cleaned, 0, $year2['offset']));
                $year = $year2['year'];
            } else {
                $title = trim(substr($cleaned, 0, $year1['offset']));
                $year = $year1['year'];
            }
        } elseif (count($yearPositions) === 1) {
            $year1 = $yearPositions[0];
            $candidateTitle = trim(substr($cleaned, 0, $year1['offset']));
            $afterYear1 = trim(substr($cleaned, $year1['offset'] + 4));
            if ($candidateTitle === '' && $afterYear1 === '') {
                // The four digits ARE the entire name (e.g. "1917", "2012"): stripping to
                // the year offset would leave an empty title that fails lookup. Keep the
                // numeric title and treat it as having no detected year.
                $title = $cleaned;
                $year = null;
            } else {
                // "Title 2024 …" (text precedes the year) — or year-then-junk, where the
                // empty pre-year title is the existing, intended behavior.
                $title = $candidateTitle;
                $year = $year1['year'];
            }
        } else {
            $parts = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false) {
                $parts = [];
            }
            $cleanParts = [];

            foreach ($parts as $part) {
                if (self::isQualityToken($part)) {
                    continue;
                }
                if (self::isYearLikeToken($part)) {
                    continue;
                }
                $cleanParts[] = $part;
            }

            $title = implode(' ', $cleanParts);
        }

        $title = self::stripGroupSuffix($title);
        $title = self::stripBracketedTags($title);
        $title = TitleSuffixStripper::strip($title, false, $noiseSuffixes);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title);

        if ($title === '' && $year === null) {
            $title = $cleaned;
        }

        return [
            'title' => $title,
            'year' => $year,
            'raw' => $raw,
        ];
    }

    /**
     * Get the first token (non-whitespace word) from a string.
     *
     * @param string $str Input string.
     *
     * @return string|null First token or null if none.
     */
    private static function getFirstToken(string $str): ?string
    {
        if ($str === '') {
            return null;
        }

        $tokens = preg_split('/[\s\-.]+/', $str, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return null;
        }
        return $tokens[0];
    }

    /**
     * Check if a token is a quality/codec/release marker.
     *
     * @param string $token Token to check.
     *
     * @return bool True if token is a quality marker.
     */
    private static function isQualityToken(string $token): bool
    {
        $lower = mb_strtolower($token, 'UTF-8');

        foreach (self::QUALITY_TOKENS as $qt) {
            if ($lower === mb_strtolower($qt, 'UTF-8')) {
                return true;
            }
        }

        if (preg_match('/^\d{4}p$/', $lower)) {
            return true;
        }

        if (preg_match('/^(xvid|divx|mp4|mkv|avi)$/i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a token looks like a year but is outside the valid movie year range.
     *
     * Matches 4-digit numeric tokens that could be mistaken for movie years
     * but are outside the valid range 1900-2099. This prevents tokens like
     * "1899", "2100" from being kept as title content.
     *
     * @param string $token Token to check.
     *
     * @return bool True if token is a 4-digit number outside valid year range.
     */
    private static function isYearLikeToken(string $token): bool
    {
        if (!preg_match('/^\d{4}$/', $token)) {
            return false;
        }
        $year = (int) $token;
        return $year < 1900 || $year > 2099;
    }

    /**
     * Strip trailing release-group suffix (-GROUP, etc.) and group tokens.
     *
     * @param string $title Processed title string.
     *
     * @return string Title with group suffix removed.
     */
    private static function stripGroupSuffix(string $title): string
    {
        $title = preg_replace('/\s*-\s*[A-Z0-9]{2,}$/', '', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        return trim($title);
    }

    /**
     * Strip bracketed and parenthetical tags like [YTS.MX], (YTS), etc.
     *
     * @param string $title Title to clean.
     *
     * @return string Title with tags stripped.
     */
    private static function stripBracketedTags(string $title): string
    {
        $title = preg_replace('/\s*\[\s*[^\]]*\]\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s*\(\s*[^\)]*\)\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s*【\s*[^\]]*\】\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        return trim($title);
    }
}
