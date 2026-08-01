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
     * @var array<string, string> Bracket openers mapped to their closers, used by
     *      {@see repairBracketBalance()}. Each pair is matched independently.
     */
    private const BRACKET_PAIRS = [
        '(' => ')',
        '[' => ']',
        '【' => '】',
    ];

    /**
     * @var array<string, string> The inverse of {@see BRACKET_PAIRS} (closer =>
     *      opener), kept as its own const so the scan needs no array_search().
     */
    private const BRACKET_CLOSERS = [
        ')' => '(',
        ']' => '[',
        '】' => '【',
    ];

    /**
     * Normalize a dirty release filename.
     *
     * KNOWN LIMIT — the "the returned title always has balanced brackets"
     * guarantee holds only for a BRACKET-FREE noise-suffix list.
     * {@see TitleSuffixStripper::strip()} runs AFTER the last
     * {@see repairBracketBalance()} pass at both call sites below, so an
     * admin-authored `matching.noise_suffixes` entry whose TEXT contains a
     * bracket can peel the closing half of a group back off and re-unbalance the
     * title:
     *
     * ```
     * normalize('Movie 【a]b】')                  // 'Movie 【ab】' — balanced
     * normalize('Movie 【a]b】', ['ab】', ...])    // 'Movie 【'    — unbalanced
     * ```
     *
     * (`'Movie 【a]b】'` survives {@see stripBracketedTags()} in the first place
     * because that method's fullwidth pattern negates the ASCII `]`, not `】`.)
     *
     * This is pre-existing, not introduced by the bracket-repair pass, and is
     * deliberately left as a documented limit rather than closed with a further
     * repair pass: the shipped default list (`config/matching.php`, mirrored by
     * {@see TitleSuffixStripper::NOISE_SUFFIXES}) is bracket-free, so a
     * speculative extra pass would cost more than the escape. Pinned by
     * `SceneFilenameNormalizerTest::testBracketBearingNoiseSuffixCanReUnbalanceTitle()`.
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
                // Repair only AFTER the strip — see the note on the tail call below.
                $title = self::repairBracketBalance($title);
                // Peels AFTER the repair, so the balance guarantee assumes a
                // bracket-free noise list — see the KNOWN LIMIT note on this
                // method's docblock.
                $title = TitleSuffixStripper::strip($title, false, $noiseSuffixes);
                $title = preg_replace('/\s+/', ' ', $title) ?? $title;
                $title = trim($title);

                return [
                    // Falling back to the untouched $cleaned would hand the caller a
                    // title with the very orphan bracket this pass exists to remove
                    // (e.g. "( [2010]"), so repair the fallback too — exactly as the
                    // sibling fallback at the tail of this method does.
                    'title' => $title !== '' ? $title : self::repairBracketBalance($cleaned),
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
        // The year branches above slice $cleaned at the year's byte offset, which
        // can cut INSIDE a bracket group and orphan its opener (e.g.
        // "Gantz O (2016 DUAL Audio - 720p BluRay)" -> "Gantz O ("), and
        // stripBracketedTags() can itself unbalance cross-nested input — removing
        // "[y) z]" from "A (x [y) z]" takes the ")" that closed the "(" with it.
        // Since stripBracketedTags() can only remove a BALANCED group, an orphan
        // would otherwise survive into the stored title.
        //
        // Repair strictly AFTER the strip, never before: dropping an orphan opener
        // first SHORTENS the reach of /\s*\[\s*[^\]]*\]\s*/, so the release-tag junk
        // between that opener and the next closer survives as bare title text
        // ("… Adventure! [DisneyXD Webri [1080p] [x265]" would keep "DisneyXD
        // Webri"). One post-pass is enough to satisfy the balance invariant.
        $title = self::repairBracketBalance($title);
        // Peels AFTER the repair, so the balance guarantee assumes a bracket-free
        // noise list — see the KNOWN LIMIT note on this method's docblock.
        $title = TitleSuffixStripper::strip($title, false, $noiseSuffixes);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title);

        if ($title === '' && $year === null) {
            // Falling back to the untouched $cleaned would re-introduce the very
            // orphan we just removed, so repair the fallback too.
            $title = self::repairBracketBalance($cleaned);
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
     * Remove every UNMATCHED bracket character, leaving matched pairs intact.
     *
     * The year branches in {@see normalize()} truncate the cleaned filename at the
     * year's byte offset, which routinely cuts through a bracket group and leaves a
     * dangling opener ("Gantz O (") — or, for a leading cut, a dangling closer
     * ("Foo) Bar"). {@see stripBracketedTags()} cannot help: its patterns all
     * require a closing delimiter, so an orphan opener survives into the stored
     * title and poisons the metadata lookup.
     *
     * Each pair in {@see BRACKET_PAIRS} is matched independently, so a mismatched
     * type never cancels another type, and a closer is matched to the OUTERMOST
     * still-open opener of its type. That choice matters only when openers
     * outnumber closers, and outermost-wins is the better fit for real filenames:
     * an orphan opener is almost always the head of a release-tag run
     * ("… [DisneyXD Webri [1080p] [x265]"), so the text between it and the next
     * closer is junk that should stay inside a group for
     * {@see stripBracketedTags()} to swallow rather than being promoted to title
     * text. It also reproduces what the same name yields when its brackets are
     * balanced.
     *
     * What outermost-wins does and does not guarantee:
     *
     * - An ALREADY-balanced title (per type, with no prefix that over-closes) is
     *   returned byte-for-byte unchanged, so {@see stripBracketedTags()} still
     *   gets to make its own decision about every group in it.
     * - Only bracket characters are ever removed — never added, moved or
     *   re-ordered — so the surviving text is always a subsequence of the input
     *   and the result is always balanced.
     * - An INDIVIDUAL balanced group is NOT preserved once the title as a whole
     *   is unbalanced. With openers outnumbering closers a closer settles the
     *   EARLIEST open opener, which re-cuts the groups rather than merely
     *   deleting the orphan: `A (b (c) d` becomes `A (b c) d`, destroying the
     *   balanced inner `(c)` and synthesising a wider `(b c)`. That is the
     *   intent, not a defect — it keeps the run that followed the orphan opener
     *   inside a group for {@see stripBracketedTags()} to swallow. Pinned by the
     *   `unbalanced outer breaks balanced inner` data sets.
     *
     * Whitespace is collapsed and trimmed only when something was actually
     * removed; a title with no orphan is returned byte-for-byte unchanged.
     *
     * Note: input that is not valid UTF-8 reaches this method (scene filenames
     * carrying stray Windows-1252 bytes are normalized before
     * {@see \Phlix\Media\Library\MediaScanner} coerces them), so the scan falls
     * back to {@see splitBytesKeepingFullwidthBrackets()}, which still recognises
     * the fullwidth pair by its byte sequence.
     *
     * Public for the same reason {@see stripBracketedTags()} is: it must run
     * IMMEDIATELY AFTER that method at EVERY call site, and
     * {@see \Phlix\Media\Library\EpisodeFilenameParser} is one of them. Leaving it
     * private made the episode path the only consumer of stripBracketedTags()
     * without the balance guarantee, which put an orphan opener straight into
     * `media_items.name` ("Title [720p"). Behaviour is unchanged from when it was
     * private — this is a visibility widening only.
     *
     * ⚠ AFTER, never before. A pre-pass over the raw filename regressed 2 of
     * 39,024 real names when SM-0.1 measured it.
     *
     * @param string $title Title that may contain orphan brackets.
     *
     * @return string Title with all unmatched bracket characters removed.
     */
    public static function repairBracketBalance(string $title): string
    {
        if ($title === '') {
            return $title;
        }

        if (
            strpbrk($title, '()[]') === false
            && !str_contains($title, '【')
            && !str_contains($title, '】')
        ) {
            return $title;
        }

        $chars = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = self::splitBytesKeepingFullwidthBrackets($title);
        }

        /** @var array<string, list<int>> $openStacks Opener => indexes still unclosed. */
        $openStacks = [];
        /** @var array<int, true> $drop Indexes of unmatched bracket characters. */
        $drop = [];

        foreach ($chars as $index => $char) {
            if (isset(self::BRACKET_PAIRS[$char])) {
                $openStacks[$char][] = $index;
                continue;
            }

            if (!isset(self::BRACKET_CLOSERS[$char])) {
                continue;
            }

            $opener = self::BRACKET_CLOSERS[$char];
            if (isset($openStacks[$opener]) && $openStacks[$opener] !== []) {
                // Outermost-wins: close the EARLIEST still-open opener, so a
                // surplus opener is dropped from the inside out.
                array_shift($openStacks[$opener]);
                continue;
            }

            $drop[$index] = true;
        }

        foreach ($openStacks as $stack) {
            foreach ($stack as $index) {
                $drop[$index] = true;
            }
        }

        if ($drop === []) {
            return $title;
        }

        $repaired = '';
        foreach ($chars as $index => $char) {
            if (!isset($drop[$index])) {
                $repaired .= $char;
            }
        }

        $repaired = preg_replace('/\s+/', ' ', $repaired) ?? $repaired;

        return trim($repaired);
    }

    /**
     * Split a NOT-valid-UTF-8 title into scan tokens for {@see repairBracketBalance()}.
     *
     * `preg_split('//u')` returns false on malformed UTF-8, and such filenames do
     * reach the normalizer: {@see \Phlix\Media\Library\MediaScanner} only coerces
     * stray bytes (e.g. a Windows-1252 0x9C) AFTER parsing the name. Splitting on
     * raw bytes would then hide the fullwidth pair, whose UTF-8 encodings are the
     * three-byte sequences E3 80 90 (`【`) and E3 80 91 (`】`), leaving a dangling
     * `【` in the stored title.
     *
     * So: single bytes everywhere, except that those two sequences are emitted as
     * one token each. They can never appear inside another well-formed UTF-8
     * character (a continuation byte is always >= 0x80, and E3 only ever starts a
     * three-byte sequence), so matching them bytewise cannot split a valid
     * character in half.
     *
     * @param string $title Title that is not valid UTF-8.
     *
     * @return list<string> One token per byte, fullwidth brackets kept whole.
     */
    private static function splitBytesKeepingFullwidthBrackets(string $title): array
    {
        $tokens = [];
        $length = strlen($title);

        for ($i = 0; $i < $length; $i++) {
            if (
                $title[$i] === "\xE3"
                && $i + 2 < $length
                && $title[$i + 1] === "\x80"
                && ($title[$i + 2] === "\x90" || $title[$i + 2] === "\x91")
            ) {
                $tokens[] = substr($title, $i, 3);
                $i += 2;
                continue;
            }

            $tokens[] = $title[$i];
        }

        return $tokens;
    }

    /**
     * Strip bracketed and parenthetical tags like [YTS.MX], (YTS), etc.
     *
     * Only a BALANCED group is removed (each pattern requires its closer), and
     * the text around a group is kept — so `Show [480p] Title` collapses to
     * `Show Title` rather than being truncated at the opener.
     *
     * Public because {@see \Phlix\Media\Library\EpisodeFilenameParser} needs the
     * exact same tag-stripping for the EPISODE-title segment; duplicating these
     * three patterns there would let the two drift (the fullwidth `【…】` pair in
     * particular is easy to forget). Behaviour is unchanged from when it was
     * private — this is a visibility widening only.
     *
     * @param string $title Title to clean.
     *
     * @return string Title with tags stripped.
     */
    public static function stripBracketedTags(string $title): string
    {
        $title = preg_replace('/\s*\[\s*[^\]]*\]\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s*\(\s*[^\)]*\)\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s*【\s*[^\]]*\】\s*/', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        return trim($title);
    }
}
