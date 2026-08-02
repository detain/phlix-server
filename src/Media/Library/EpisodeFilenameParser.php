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
     * Release markers that are NEVER an ordinary English word. ONLY these may
     * ANCHOR a cut ("Ben.Franklin.720p.WEBRip.x265.HEVC-PSA" → "Ben.Franklin");
     * anything that could also be English lives in {@see ABSORBED_TOKENS}.
     *
     * ⚠ That split is the whole contract of this constant, and the list below is
     * the claim. "repack" and "proper" used to sit here while this very docblock
     * promised they did not, and because the cut was a PREFIX cut one mid-title
     * hit destroyed the tail: "A Proper Sendoff" → "A", "The Repack" → "The".
     * If you add a word here, it must be a word no episode title can contain.
     *
     * ⚠ This is deliberately NOT {@see \Phlix\Media\Metadata\SceneFilenameNormalizer}'s
     * `QUALITY_TOKENS`. That list is right for a MOVIE title (where the tokens are
     * dropped word-wise from a name that is mostly proper nouns) and wrong for an
     * EPISODE title, which is a sentence. Measured over the 25,061 provider-titled
     * episodes on the reference library, `QUALITY_TOKENS` fires on 167 tokens, of
     * which 86 are inside GENUINE titles — "And the Final Curtain", "In a DVD
     * Factory", "The Fix-Up", "Dear Ma", "The Limited", "Original Extended
     * Broadcast Pilot". FINAL/MA/DVD/FIX/LIMITED/EXTENDED/THEATRICAL/PROPER-style
     * English words are therefore excluded here; re-measured after the run-rule
     * rewrite, this list truncates 36 of those 25,061 provider titles and every
     * one of the 36 is itself release junk ("720p.WEB.x264-GalaxyTV", "1080p").
     * `EpisodeFilenameParserTest::testEpisodeTitleNotNoiseStripped()` pins the
     * "Extended" half of that decision.
     *
     * @var list<string>
     */
    private const RELEASE_TOKENS = [
        'webrip', 'web-dl', 'webdl', 'bluray', 'blu-ray', 'brrip', 'bdrip', 'hdrip', 'dvdrip',
        'hdtv', 'remux', 'x264', 'x265', 'h264', 'h265', 'hevc', 'xvid', 'divx',
        'ac3', 'eac3', 'ddp5', 'dd5', 'truehd', 'hi10p',
    ];

    /**
     * Markers that MAY also be an ordinary English word or a real title word.
     *
     * These are ABSORBED into a release run that some {@see RELEASE_TOKENS}
     * anchor has already established; they may never start one. That asymmetry
     * is the whole point: "PROPER"/"REPACK"/"INTERNAL"/"WEB" are all real scene
     * markers AND real English, so letting one start the cut destroys the title
     * it sits inside ("A Proper Sendoff" → "A"), while absorbing one only ever
     * extends a run that is already provably junk
     * ("INTERNAL.1080p.WEB.x264-STRiFE" → nothing).
     *
     * Only two release-group names are listed, and only because a group can stand
     * alone after a tag group ("(1080p) YIFY"). The far commoner dash-attached
     * spelling ("x264-MRSK", "[1080p]-RARBG", "HEVC-PSA") needs no name list at
     * all: it is caught structurally by the dash-head test in
     * {@see isExactReleaseToken()} and by the scrap rule in
     * {@see classifyToken()}. Do not grow this into a group directory.
     *
     * Every entry is load-bearing. Rows of the 26,389-name reference library that
     * change if the entry is removed: web 497 · amzn 485 · nf 229 · hmax 165 ·
     * hd 13 · repack 62 · dsnp 21 · internal 8 · max 1. The four with no corpus
     * row — proper, dl, yify, rarbg — are each pinned by a test for the exact
     * shape that motivated them; "atvp" and "yts" were dropped because they had
     * neither.
     *
     * @var list<string>
     */
    private const ABSORBED_TOKENS = [
        'proper', 'repack', 'internal', 'web', 'dl', 'nf', 'amzn', 'hmax', 'dsnp',
        'max', 'hd', 'rarbg', 'yify',
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
     * Order matters, and it is the REVERSE of what it was:
     * {@see truncateAtReleaseRun()} runs on the text that still HAS its bracket
     * groups, because the group is the only evidence that the text after it is a
     * release-group suffix ("[1080p]-RARBG", "(1080p) YIFY"). Stripping first
     * deleted that evidence and promoted the suffix to a title. The tag strip
     * then runs, and {@see SceneFilenameNormalizer::repairBracketBalance()}
     * IMMEDIATELY AFTER it — never before, and never at any other point — which
     * is the ordering SM-0.1 established for every other caller.
     *
     * Returns null when nothing meaningful remains — an empty residue or a bare
     * ordinal, e.g. "Naruto - 394 [720p]" or "Bleach - 160 -". The junk shapes
     * this used to need a separate guard for ("v2", a CRC32 stamp, a trailing
     * "-E17" range marker, the "~" a removed "[720p]" leaves behind) are all
     * removed upstream instead, by {@see truncateAtReleaseRun()} and the range
     * strip below.
     *
     * ⚠ DO NOT ADD A "must contain a letter or a digit" GUARD either. It was
     * written to close the punctuation-residue class ("Show S01E01 [720p] ~"),
     * then measured: 1 of the 25,061 stored provider titles is exactly that shape
     * — Person of Interest S03E17 is literally titled "/". The run rule already
     * nulls every anchored punctuation residue without it.
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
     * guard, as it already was before this change; a dot-spelled "H.264" defeats
     * the tokenizer, because the separator split that protects "M.I.A." and
     * "Mr.Monk.Buys.a.House" also splits "H" from "264"
     * ("Show.S01E01.NF.WEB.DDP5.1.H.264-NTb" keeps its junk — unchanged from
     * before this step, 0 rows on the reference library); and an all-letter CRC32
     * stamp ("[720p] DEADBEEF") is kept, because the alternative deletes the real
     * title "Deadface".
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
        $title = self::truncateAtReleaseRun($title);
        $title = SceneFilenameNormalizer::stripBracketedTags($title);
        $title = SceneFilenameNormalizer::repairBracketBalance($title);
        $title = self::trimSeparators($title);

        if ($title === '' || preg_match('/^\d+$/', $title) === 1) {
            return null;
        }

        return $title . $part;
    }

    /** A token that is ordinary title text — a release run cannot span it. */
    private const KIND_SOLID = 0;
    /** Debris a release run may swallow, but which cannot anchor one. */
    private const KIND_SCRAP = 1;
    /** A marker that may be an English word: absorbed into a run, never anchors one. */
    private const KIND_ABSORBED = 2;
    /** An unambiguous release marker: may anchor a run. */
    private const KIND_RELEASE = 3;

    /**
     * Cut the release run off an episode title, preserving the original
     * separators of the kept prefix (so "Mr.Monk.Buys.a.House" is not rewritten
     * to spaces and "S.W.A.T." survives intact).
     *
     * ⚠ It cuts at a RUN, not at the first marker it sees, and that is
     * load-bearing. The earlier "cut at the first release token anywhere" rule
     * turned every mid-title collision into total loss — "A Proper Sendoff" → "A",
     * "The Hdtv Years" → "The", "Divx and Conquer" → null. A run qualifies only
     * when it holds a {@see RELEASE_TOKENS} anchor AND one of: it holds a bare
     * PATTERN marker ({@see isHardReleaseMarker()}), it reaches the end of the
     * name, it has two BARE markers (bracket groups do not count), or it
     * contains the scene's tag-GROUP spelling. So one stray WORD marker between
     * real words can never fire. That is also what makes {@see ABSORBED_TOKENS}
     * listable at all.
     *
     * ⚠ The pattern-marker qualification exists because a run BREAKS on any
     * token in neither marker list, and modern scene names are full of them
     * ("AAC", "Atmos", "HDR", "AVC", "DV", "MULTi"). A broken run failed the
     * two-marker test and the cut fired at the NEXT run instead, stranding the
     * anchor inside the title: "The Title 1080p AAC x264-GRP" produced
     * "The Title 1080p AAC". Only the SHAPE half of the marker test is trusted
     * for this; see {@see isHardReleaseMarker()} for why the vocabulary half is
     * not.
     *
     * Runs on the text BEFORE {@see SceneFilenameNormalizer::stripBracketedTags()}
     * so a bracket group is still visible: the group is the only evidence that
     * what follows it is a release-group suffix ("[1080p]-RARBG", "(1080p) YIFY"),
     * and stripping first deleted that evidence and promoted the suffix to a
     * title. The run rule keeps the equally dominant
     * "Show S01E23 [480p] Let It Be Me" intact — one tag, real words after it.
     *
     * ⚠ A digits-only token is SOLID and breaks a run, deliberately. "2.0",
     * "3.0", "13.1" and "1.28" are genuine episode titles that sit immediately
     * after a "[720p]" tag; nothing about a bare number says junk. The scene
     * channel counts ("DDP5.1") need no rule of their own because the run that
     * carries them always starts to their LEFT, and the cut takes the whole tail.
     *
     * @param string $title Episode-title text, bracket groups still present.
     *
     * @return string Title with the release run and everything after it removed.
     */
    private static function truncateAtReleaseRun(string $title): string
    {
        $tokens = self::tokenize($title);
        $count = count($tokens);
        if ($count === 0) {
            return $title;
        }

        $kinds = [];
        foreach ($tokens as $token) {
            $kinds[] = self::classifyToken($token[0]);
        }

        // An ambiguous token counts only when it is not touching real title text.
        // "[576p] Internal Affairs" and "Spins a Web (480p - DVDRip)" are genuine
        // titles that sit right next to a tag; "INTERNAL.1080p.WEB.x264-STRiFE"
        // and "(1080p AMZN WEB-DL x265) REPACK" are the scene's own prefix/suffix
        // markers, and there the marker touches nothing but more junk. Same test
        // for scrap, which is what keeps "Tag! We're It...! [480p]" whole.
        $effective = $kinds;
        for ($i = 0; $i < $count; $i++) {
            if ($kinds[$i] !== self::KIND_ABSORBED && $kinds[$i] !== self::KIND_SCRAP) {
                continue;
            }
            $left = $i > 0 ? $kinds[$i - 1] : null;
            $right = $i < $count - 1 ? $kinds[$i + 1] : null;
            if (
                ($left === null && $right === null)
                || $left === self::KIND_SOLID
                || $right === self::KIND_SOLID
            ) {
                $effective[$i] = self::KIND_SOLID;
            }
        }
        $kinds = $effective;

        for ($start = 0; $start < $count; $start++) {
            if ($kinds[$start] === self::KIND_SOLID) {
                continue;
            }

            $end = $start;
            $anchored = false;
            $tagged = false;
            $hard = false;
            $bare = 0;
            while ($end < $count && $kinds[$end] !== self::KIND_SOLID) {
                $anchored = $anchored || $kinds[$end] === self::KIND_RELEASE;
                $tagged = $tagged || self::isGroupTaggedToken($tokens[$end][0]);
                if (!self::isBracketGroup($tokens[$end][0])) {
                    $bare++;
                    $hard = $hard || self::isHardReleaseMarker($tokens[$end][0]);
                }
                $end++;
            }

            // A run that stops short of the end has to prove itself: a BARE
            // resolution or bit-depth marker (see isHardReleaseMarker() — the
            // revision tag and the CRC32 stamp are NOT hard), two BARE markers, or
            // the scene's tag-GROUP spelling ("x264-MRSK", "DVDRip-TV-Series"),
            // which no English phrase produces. One WORD marker alone never cuts
            // — that is what saves "The Hdtv Years".
            //
            // ⚠ A bracket group does not count toward the two, and cannot be the
            // hard marker either. It is deleted by stripBracketedTags() anyway,
            // so letting it lend mass to its neighbour made
            // "[720p] Remux and Remaster" a two-token run and deleted a real
            // title, and letting it prove a run outright would delete the whole
            // 501-file recovery this step exists for ("[480p] Let It Be Me").
            if ($anchored && ($hard || $end === $count || $bare >= 2 || $tagged)) {
                return substr($title, 0, $tokens[$start][1]);
            }

            $start = $end;
        }

        return $title;
    }

    /**
     * Split a title into scan tokens, keeping bracket groups whole.
     *
     * A balanced group swallows any chunk glued to its closer, which is what
     * catches the two shapes that used to leak: the release-group suffix
     * ("[1080p]-RARBG") and the duplicate-file artefact
     * ("(1080p HMAX WEB-DL x265 YOGI)1", which otherwise left a stray "1" welded
     * to a real title). An UNCLOSED opener swallows the rest of the string, which
     * is the same reading {@see SceneFilenameNormalizer::repairBracketBalance()}
     * documents — an orphan opener is nearly always the head of a tag run
     * ("Title [720p").
     *
     * @return list<array{0: string, 1: int<-1, max>}> Token text and byte offset.
     */
    private static function tokenize(string $title): array
    {
        $pattern = '/\[[^\]]*\][^\s._\[\(\x{3010}]*'
            . '|\([^\)]*\)[^\s._\[\(\x{3010}]*'
            . '|\x{3010}[^\]]*\x{3011}[^\s._\[\(\x{3010}]*'
            . '|[\[\(\x{3010}].*$'
            . '|[^\s._\[\(\x{3010}]+/u';

        if (preg_match_all($pattern, $title, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        return $m[0];
    }

    /**
     * Grade one token for {@see truncateAtReleaseRun()}.
     *
     * A bracket group is ALWAYS absorbable because
     * {@see SceneFilenameNormalizer::stripBracketedTags()} is about to delete it
     * anyway; it anchors a run only when it holds a release marker, so
     * "[Dual-Audio]" is swallowed while "[1080p]" can start the cut.
     */
    private static function classifyToken(string $token): int
    {
        if (self::isBracketGroup($token)) {
            return self::groupHoldsReleaseToken($token) ? self::KIND_RELEASE : self::KIND_SCRAP;
        }

        // Residue a removed group leaves behind: the "-GalaxyTV" of "[1080p]-GalaxyTV",
        // or a lone "~" / "&" / ",".
        //
        // ⚠ DASH-led only, plus anything with no letter AND no digit at all. A
        // broader "does not start with a letter or a digit" test looked identical
        // and cost 26 real titles on the reference library: "#THINMAN",
        // "#TeamLucifer", "…Ye Who Enter Here", "%3F IRL, %3F Online",
        // "'Til Death Do Us Part", "'Twas the Day after Christmas", and every
        // clipped-apostrophe word — "Almost Got 'Im", "Don't Touch That 'Dile",
        // "Oldies but Young 'Uns", "'Teers in the 'Hood".
        if (
            preg_match('/[\p{L}\p{N}]/u', $token) !== 1
            || preg_match('/^[-~\x{2013}\x{2014}]/u', $token) === 1
        ) {
            return self::KIND_SCRAP;
        }

        // A digits-only token is title text ("2.0", "13.1", "3.0", "1.28" are all
        // real), so it breaks a run rather than joining one.
        if (preg_match('/^\d+$/', $token) === 1) {
            return self::KIND_SOLID;
        }

        if (self::isReleaseToken($token)) {
            return self::KIND_RELEASE;
        }

        if (self::isAbsorbedToken($token)) {
            return self::KIND_ABSORBED;
        }

        return self::KIND_SOLID;
    }

    /** True when a token opens with a bracket, i.e. {@see tokenize()} kept a group whole. */
    private static function isBracketGroup(string $token): bool
    {
        $first = mb_substr($token, 0, 1, 'UTF-8');

        return $first === '[' || $first === '(' || $first === '【';
    }

    /** True when a bracket group contains an unambiguous release marker. */
    private static function groupHoldsReleaseToken(string $group): bool
    {
        if (preg_match_all('/[^\s._\[\]\(\)\x{3010}\x{3011}]+/u', $group, $m) === false) {
            return false;
        }

        foreach ($m[0] as $inner) {
            if (self::isReleaseToken($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate spellings of a token: the token itself and, for a dash-joined
     * scene tag, its head ("x264-MRSK" → "x264"). That head test is what catches
     * an unknown release-group suffix without any list of group names.
     *
     * @return list<string>
     */
    private static function tokenCandidates(string $token): array
    {
        $lower = mb_strtolower($token, 'UTF-8');
        $dash = strpos($lower, '-');
        $candidates = $dash === false ? [$lower] : [$lower, substr($lower, 0, $dash)];

        return array_values(array_filter($candidates, static fn(string $c): bool => $c !== ''));
    }

    /** True when a token — or the head of a dash-joined scene tag — is a marker. */
    private static function isReleaseToken(string $token): bool
    {
        foreach (self::tokenCandidates($token) as $candidate) {
            if (self::isExactReleaseToken($candidate)) {
                return true;
            }
        }

        return false;
    }

    /** True when this exact lower-cased spelling is an unambiguous marker. */
    private static function isExactReleaseToken(string $candidate): bool
    {
        return in_array($candidate, self::RELEASE_TOKENS, true)
            || self::isPatternReleaseMarker($candidate);
    }

    /**
     * The PATTERN half of {@see isExactReleaseToken()}: a marker recognised by
     * SHAPE rather than by vocabulary — resolution (720p, 2160p), bit depth
     * (10bit), revision tag (v2), CRC32 stamp.
     *
     * ⚠ A shape is NOT automatically safer than a word. An earlier revision of
     * this docblock claimed "none of these four patterns can be an English word,
     * so they can never legitimately sit inside an episode title" and spent that
     * guarantee in {@see isHardReleaseMarker()}. It is FALSE for two of the four,
     * and the counter-evidence is in this estate: scanning all 85,763 real
     * basenames for bare pattern-marker tokens turns up "07 deadmau5 - Tau V2",
     * "04 deadmau5 - Tau V1", "16 Superspy - Sumo V2", "08 8bit",
     * "28 Just Before 8bit" and "07 Ghettochip Malfunction (Hell Yes) (8bit
     * remix)" — human-authored titles, not release junk. "V1"/"V2"/"V8" are
     * ordinary title vocabulary (rocket designations, engine layouts) and an
     * eight-hex-with-a-digit word is reachable by ordinary spelling
     * ("Cafe1234", "Deadbe3f"). Only the resolution and bit-depth members are
     * trusted as HARD; see {@see isHardPatternMarker()}.
     *
     * All four still count as ordinary markers here, which is what closes defect
     * D6 (70 episode rows literally named "v2") — a marker only needs a run to
     * cut, and a run needs corroboration.
     */
    private static function isPatternReleaseMarker(string $candidate): bool
    {
        if (self::isHardPatternMarker($candidate)) {
            return true;
        }
        // ⚠ ONE digit. "v10"-"v99" do not exist: the highest revision marker on
        // the whole 26,389-name reference library is v2, so the second digit the
        // rule used to allow was unreachable code.
        if (preg_match('/^v\d$/', $candidate) === 1) {
            return true;
        }
        // CRC32 stamp. Requires at least one DIGIT so an eight-letter word that
        // happens to be all hex ("Deadface") is never eaten. Measured: dropping
        // the digit requirement costs a real title and buys nothing on the
        // reference library.
        return preg_match('/^(?=[0-9a-f]{8}$)[a-f]*[0-9][0-9a-f]*$/', $candidate) === 1;
    }

    /**
     * The two pattern markers a BARE token may prove a run with on its own —
     * resolution ("720p", "2160p") and bit depth ("8bit", "10bit", "12bit").
     *
     * ⚠ Deliberately NOT the whole of {@see isPatternReleaseMarker()}. The
     * revision tag ("v2") and the CRC32 stamp are ordinary title vocabulary in
     * the wild (see that method's census), and because the hard rule cuts a run
     * that neither reaches the end of the name nor has any second marker, one
     * such token between two real words truncates the title outright:
     * "Show S01E01 V2 Rocket Base" → null, "Show S01E01 The V8 Interceptor" →
     * "The". Measured, the narrowing is FREE: identical output to the wide
     * version across every name measured — 26,389 real episode basenames +
     * 85,763 whole-estate basenames + 60,000 modern-scene fuzz names (two seeds
     * x 30,000) + 319,256 planted-title probes = 491,408 — with the
     * resolution/bit-depth-in-title count still 0 and D6 still closed.
     *
     * ⚠ Do NOT narrow this to the resolution alone. Measured over 30,000 modern
     * scene names, dropping bit depth leaves 574 (seed 4242) / 533 (seed 90210)
     * titles carrying a resolution or bit-depth token — i.e. 1.9% / 1.8% of the
     * corpus, the very class the hard rule exists to close. That figure was
     * derived independently twice, and the same two numbers pin the two
     * "bit depth alone" / "8bit alone" rows in
     * EpisodeFilenameParserTest::brokenReleaseRunCases(). "8bit" as genuine
     * title text is a real but far smaller exposure (3 music files in this
     * estate, none of which reach this parser) than the one it buys down.
     */
    private static function isHardPatternMarker(string $candidate): bool
    {
        return preg_match('/^\d{3,4}p$/', $candidate) === 1
            || preg_match('/^(?:8|10|12)bit$/', $candidate) === 1;
    }

    /**
     * True when a BARE token — or the head of a dash-joined scene tag
     * ("1080p-GRP") — is a HARD pattern marker, i.e. one that proves its run on
     * its own in {@see truncateAtReleaseRun()}.
     *
     * ⚠ Why this exists. The run rule needs two bare markers, or the end of the
     * name, before it cuts; that is what stops one stray English-shaped marker
     * from destroying a title. But a run is broken by any token in NEITHER
     * marker list, and modern scene names are full of them — "AAC", "Atmos",
     * "DTS", "AVC", "HDR", "HDR10", "SDR", "DV", "MULTi", and "H.265", whose dot
     * splits "H" from "265". A broken run failed the two-marker test and the cut
     * then fired at the NEXT qualifying run, stranding its own anchor inside the
     * title: "Show S01E01 The Title 1080p AAC x264-GRP" produced
     * "The Title 1080p AAC". Measured over 30,000 realistic modern scene names,
     * 2,047 of them (6.8%) kept a resolution or bit-depth token in the title.
     * It matters because MediaScanner::episodeName() writes this string into
     * `media_items.name`, which LibraryMetadataMatcher::enrichEpisode() never
     * rewrites — only `metadata_json` — so it is permanent.
     *
     * ⚠ The fix is deliberately NOT a wider vocabulary. Adding "aac"/"dts"/"hdr"/
     * "dv"/"sdr"/"multi" as words re-opens exactly what §2.1 of this step
     * measured and rejected: "DV", "SDR" and "MULTi" are plausible title
     * substrings, and QUALITY_TOKENS-style lists fire inside 86 genuine titles.
     *
     * ⚠ Nor is it the whole PATTERN half. {@see isHardPatternMarker()} carries
     * the census: "V2"/"V8" and eight-hex-with-a-digit words ARE ordinary title
     * text, and a hard marker cuts with no corroboration at all, so trusting
     * them deleted real titles ("V2 Rocket Base" → null). Only resolution and
     * bit depth are hard, and that narrowing was measured free over all
     * 26,389 + 85,763 + 60,000 + 319,256 = 491,408 names enumerated by
     * {@see isHardPatternMarker()}.
     *
     * ⚠ A BRACKET GROUP is never hard, even when it holds a pattern marker. The
     * dominant recovery convention IS "Show S01E23 [480p] Let It Be Me"; letting
     * "[480p]" prove its own run would delete all 501 recovered titles.
     */
    private static function isHardReleaseMarker(string $token): bool
    {
        foreach (self::tokenCandidates($token) as $candidate) {
            if (self::isHardPatternMarker($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for the scene's "tag-GROUP" spelling: the token is not a release
     * marker itself, but its head before the first "-" is ("x264-MRSK",
     * "HEVC-PSA", "DVDRip-TV-Series"). Deliberately excludes the marker
     * dictionary's own hyphenated entries ("web-dl", "blu-ray"), which match
     * whole.
     */
    private static function isGroupTaggedToken(string $token): bool
    {
        $lower = mb_strtolower($token, 'UTF-8');
        $dash = strpos($lower, '-');
        if ($dash === false || $dash === 0 || $dash === strlen($lower) - 1) {
            return false;
        }

        return !self::isExactReleaseToken($lower) && self::isExactReleaseToken(substr($lower, 0, $dash));
    }

    /**
     * True when a token may be swallowed by a run that is already anchored.
     *
     * ⚠ An all-letter CRC32 stamp ("DEADBEEF") is deliberately NOT listed. It
     * was added, measured and removed: it makes "Show S01E01 [480p] Deadface"
     * return null, and "Deadface" is a title, not a checksum. The eight-hex-with-
     * a-digit rule in {@see isExactReleaseToken()} is where the line sits, and
     * `testCrcStampIsDroppedButAnAllLetterHexWordSurvives()` pins it.
     */
    private static function isAbsorbedToken(string $token): bool
    {
        foreach (self::tokenCandidates($token) as $candidate) {
            if (in_array($candidate, self::ABSORBED_TOKENS, true)) {
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
