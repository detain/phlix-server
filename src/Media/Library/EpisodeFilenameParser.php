<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Media\Metadata\SceneFilenameNormalizer;
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
 *
 * ⚠ The two titles are cleaned DIFFERENTLY on purpose. The SERIES segment is
 * truncated at its first bracket ({@see cleanSeries()}) because everything after
 * it is release noise; the EPISODE segment has its bracket groups removed IN
 * PLACE ({@see extractEpisodeTitle()}) because the title normally sits AFTER the
 * quality tag ("Show S01E23 [480p] Let It Be Me"). Do not unify them.
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
        if (
            preg_match(
                '/^(.+?)[\s._-]*S(\d{1,2})\s*[._x\- ]?\s*(?:EP|SP|E)\s*(\d{1,3})/i',
                $norm,
                $m,
                PREG_OFFSET_CAPTURE
            )
        ) {
            return self::build($m[1][0], (int) $m[2][0], (int) $m[3][0], self::remainder($norm, $m), $noiseSuffixes);
        }

        // 2. 1x02 style.
        if (preg_match('/^(.+?)[\s._-]+(\d{1,2})x(\d{1,3})\b/i', $norm, $m, PREG_OFFSET_CAPTURE)) {
            return self::build($m[1][0], (int) $m[2][0], (int) $m[3][0], self::remainder($norm, $m), $noiseSuffixes);
        }

        if ($allowAbsolute) {
            // 3a. Dash-delimited absolute: "Title - 394", "Title - E29", "Title - Ep. 04".
            if (
                preg_match(
                    '/^(.+?)\s[-–]\s*(?:Episode|Ep\.?|EP|E)?\s*(\d{1,4})(?:v\d+)?(?=$|[\s\[\(.\-])/i',
                    $norm,
                    $m,
                    PREG_OFFSET_CAPTURE
                )
            ) {
                return self::build($m[1][0], 1, (int) $m[2][0], self::remainder($norm, $m), $noiseSuffixes);
            }
            // 3b. Space-delimited trailing number: "Bleach 125", "Show E29".
            if (
                preg_match(
                    '/^(.+?)\s(?:Episode|Ep\.?|EP|E)?\s*(\d{1,4})(?:v\d+)?(?=$|[\s\[\(])/i',
                    $norm,
                    $m,
                    PREG_OFFSET_CAPTURE
                )
            ) {
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
     * Release markers that are NEVER an ordinary English word, used to cut a
     * trailing scene-tag run off an episode title
     * ("Ben.Franklin.720p.WEBRip.x265.HEVC-PSA" → "Ben.Franklin").
     *
     * ⚠ This is deliberately NOT {@see \Phlix\Media\Metadata\SceneFilenameNormalizer}'s
     * `QUALITY_TOKENS`. That list is right for a MOVIE title (where the tokens are
     * dropped word-wise from a name that is mostly proper nouns) and wrong for an
     * EPISODE title, which is a sentence. Measured over the 25,061 provider-titled
     * episodes on the reference library, `QUALITY_TOKENS` fires on 167 tokens, of
     * which 86 are inside GENUINE titles — "And the Final Curtain", "In a DVD
     * Factory", "The Fix-Up", "Dear Ma", "The Limited", "Original Extended
     * Broadcast Pilot". FINAL/MA/DVD/FIX/LIMITED/EXTENDED/THEATRICAL/PROPER-style
     * English words are therefore excluded here; the same measurement puts this
     * narrower list at 65 hits, ALL of them inside strings that are themselves
     * release junk. `EpisodeFilenameParserTest::testEpisodeTitleNotNoiseStripped()`
     * pins the "Extended" half of that decision.
     *
     * @var list<string>
     */
    private const RELEASE_TOKENS = [
        'webrip', 'web-dl', 'webdl', 'bluray', 'blu-ray', 'brrip', 'bdrip', 'hdrip', 'dvdrip',
        'hdtv', 'remux', 'x264', 'x265', 'h264', 'h265', 'hevc', 'xvid', 'divx',
        'ac3', 'eac3', 'ddp5', 'dd5', 'truehd', 'hi10p', 'repack', 'proper',
    ];

    /**
     * A trailing part marker — "(2)", "(Part 1)" — optionally followed by
     * release-tag groups. TMDB spells multi-part episodes exactly this way
     * ("Kobol's Last Gleaming (2)"), and dropping it makes two siblings in the
     * same show share one title, so it is re-appended after the tag strip.
     */
    private const PART_MARKER_PATTERN = '/\(\s*(Part\s*)?(\d{1,2})\s*\)(?:\s*[\[\(][^\]\)]*[\]\)])*\s*$/i';

    /**
     * Pull the episode title from the text following the marker.
     *
     * Bracketed tags are REMOVED IN PLACE and the surrounding text is kept — the
     * old `preg_replace('/\s*[\[\(].*$/', '', …)` cut at the first opener and so
     * deleted the title outright in the dominant `Show SxxEyy [480p] Title`
     * convention. Measured on the reference library: 501 of the 1,328 episodes
     * with no title at all carry one in the filename, and every one of them is
     * that shape.
     *
     * Order matters: the tag strip runs first so a scene run that FOLLOWS a
     * bracket group ("… (1080p AMZN WEB-DL x265) REPACK") is still reachable by
     * {@see truncateAtReleaseTag()}.
     *
     * Returns null when nothing meaningful remains — an empty residue or a bare
     * ordinal, e.g. "Naruto - 394 [720p]" or "Bleach - 160 -". The junk shapes
     * this used to need a separate guard for ("v2", a CRC32 stamp, a trailing
     * "-E17" range marker) are all removed upstream instead, by
     * {@see truncateAtReleaseTag()} and the range strip below.
     *
     * ⚠ DO NOT ADD A "must contain a word" GUARD. It was written, measured and
     * deleted: `/\p{L}\p{L}/u` (two adjacent letters) rejects 88 real files, and
     * ALL 88 are genuine titles — every dotted initialism ("M.I.A.", "P.O.V",
     * "A.W.O.L", "T.A.H.I.T.I", "F.Z.Z.T"), every numeric title ("6,741", "2.0",
     * "13.1", "0-8-4", ".07%", "1:00 A.M. - 2:00 A.M."), and Black Sails' Roman
     * numerals ("I", "V", "X"). 21 of the 88 are titles the PREVIOUS parser
     * already returned, so the guard was a live regression. Pinned by
     * {@see EpisodeFilenameParserTest::testShortAndPunctuationOnlyTitlesAreKept()}.
     *
     * KNOWN LIMITS (deliberate, measured): a part marker with free text after it
     * ("Look at the Princess (3) The Maltese Crichton") still loses the marker;
     * a title that is entirely digits ("11001001") is rejected by the bare-number
     * guard, as it already was before this change.
     */
    private static function extractEpisodeTitle(string $remainder): ?string
    {
        // A multi-episode range continuation glued to the marker: "S04E01-E02 …"
        // leaves "-E02 …". Requires the tight form (no space before the dash), so
        // a real title that merely starts with a number (" - 80's Guy") is safe.
        $title = preg_replace('/^[\x{2013}\x{2014}-]E\d{1,3}(?!\d)/ui', '', $remainder) ?? $remainder;

        $part = '';
        if (preg_match(self::PART_MARKER_PATTERN, $title, $pm) === 1) {
            $part = trim($pm[1]) !== '' ? ' (Part ' . $pm[2] . ')' : ' (' . $pm[2] . ')';
        }

        $title = self::ltrimSeparators($title);
        $title = SceneFilenameNormalizer::stripBracketedTags($title);
        $title = self::truncateAtReleaseTag($title);
        $title = self::trimSeparators($title);

        if ($title === '' || preg_match('/^\d+$/', $title) === 1) {
            return null;
        }

        return $title . $part;
    }

    /**
     * Cut the title at the first {@see RELEASE_TOKENS} token, preserving the
     * original separators of the kept prefix (so "Mr.Monk.Buys.a.House" is not
     * rewritten to spaces and "S.W.A.T." survives intact).
     *
     * Tokens are split on whitespace/dot/underscore only, so a group suffix
     * ("x264-MRSK") arrives as ONE token; its head before the first "-" is tested
     * too, which is what catches that shape.
     */
    private static function truncateAtReleaseTag(string $title): string
    {
        $count = preg_match_all('/[^\s._]+/u', $title, $m, PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return $title;
        }

        foreach ($m[0] as $token) {
            if (self::isReleaseToken($token[0])) {
                return substr($title, 0, $token[1]);
            }
        }

        return $title;
    }

    /** True when a whole token is an unambiguous scene release marker. */
    private static function isReleaseToken(string $token): bool
    {
        $lower = mb_strtolower($token, 'UTF-8');
        $dash = strpos($lower, '-');
        $candidates = $dash === false ? [$lower] : [$lower, substr($lower, 0, $dash)];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (in_array($candidate, self::RELEASE_TOKENS, true)) {
                return true;
            }
            // Resolution (720p, 2160p), bit depth (10bit), revision tag (v2).
            if (preg_match('/^\d{3,4}p$/', $candidate) === 1) {
                return true;
            }
            if (preg_match('/^(?:8|10|12)bit$/', $candidate) === 1) {
                return true;
            }
            if (preg_match('/^v\d{1,2}$/', $candidate) === 1) {
                return true;
            }
            // CRC32 stamp. Requires at least one DIGIT so an eight-letter word
            // that happens to be all hex ("deadface") is never eaten.
            if (preg_match('/^(?=[0-9a-f]{8}$)[a-f]*[0-9][0-9a-f]*$/', $candidate) === 1) {
                return true;
            }
        }

        return false;
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
