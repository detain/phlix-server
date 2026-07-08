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
 * Single source of truth for peeling trailing edition/quality "noise" phrases
 * off a media title before it is used as a metadata-lookup query.
 *
 * Filenames frequently carry multi-word edition markers — "Directors Cut",
 * "UNCUT & UNRATED", "ALTERNATE ENDING", "YIFY", "DC" — that survive simple
 * token-level quality stripping and depress TMDB/IMDb hit-rates. This class
 * peels any such trailing phrase (end-anchored, case-insensitive, on a word
 * boundary, after any optional ` -._` separators), repeating to catch stacked
 * suffixes (e.g. "Foo Extended Uncut").
 *
 * Both {@see SceneFilenameNormalizer} (movies) and
 * {@see \Phlix\Media\Library\EpisodeFilenameParser} (series) delegate here so
 * the noise list and peeling semantics stay identical across the codebase.
 *
 * The class is a pure utility — no side effects, no DB access, no external
 * dependencies, no mutable state.
 *
 * @package Phlix\Media\Metadata
 * @since 0.22.0
 */
final class TitleSuffixStripper
{
    /**
     * @var list<string> Trailing edition/noise phrases to peel off a title, ordered
     *      LONGEST-FIRST so multi-word phrases match before their shorter prefixes.
     *      Matched end-anchored, case-insensitively, on word boundaries, after any
     *      optional ` -._` separators. Repeatedly peeled by {@see strip()}.
     */
    public const NOISE_SUFFIXES = [
        'unrated directors cut',
        'uncut & unrated',
        'alternate ending',
        'extended cut',
        'directors cut',
        "director's cut",
        'theatrical cut',
        'remastered',
        'extended',
        'uncut',
        'yify',
        'dc',
    ];

    /**
     * Repeatedly peel any trailing edition/noise phrase from a title.
     *
     * Each iteration strips the LONGEST matching {@see NOISE_SUFFIXES} phrase that
     * sits at the very end of the title (case-insensitive, on a word boundary,
     * preceded by optional ` -._` separators), then loops to catch stacked
     * suffixes. A dangling trailing `&` connector (left after token-level quality
     * stripping removed one half of e.g. "UNCUT & UNRATED") is also dropped.
     *
     * By default a single-token noise phrase is never allowed to consume the
     * entire title — if peeling it would leave the title empty, the original is
     * returned so a film literally named "DC" stays "DC". Pass
     * `$allowEmpty = true` to permit an empty result (callers that have their own
     * fallback can opt in).
     *
     * @param string             $title      Title to clean (ideally already
     *                                        group/bracket-stripped).
     * @param bool               $allowEmpty When false (default) a noise phrase
     *                                        never empties the title; when true
     *                                        an empty result is permitted.
     * @param list<string>|null  $suffixes   Effective noise-suffix list to peel.
     *                                        When null (default) the built-in
     *                                        {@see NOISE_SUFFIXES} const is used,
     *                                        so any caller that does not inject an
     *                                        admin-extended list still works. Pass
     *                                        a non-empty list to override (the
     *                                        list should already be ordered
     *                                        longest-first by the caller). An
     *                                        empty array falls back to the const.
     *
     * @return string Title with trailing noise suffixes removed.
     */
    public static function strip(string $title, bool $allowEmpty = false, ?array $suffixes = null): string
    {
        // An empty or absent override never blanks the noise list: fall back to
        // the built-in const so un-wired callers and empty admin overrides keep
        // the canonical behavior.
        $effective = ($suffixes === null || $suffixes === []) ? self::NOISE_SUFFIXES : $suffixes;

        $title = trim($title);

        $changed = true;
        while ($changed) {
            $changed = false;

            // Drop a dangling connector left after token-level quality stripping
            // (e.g. "Dune UNCUT &" once "UNRATED" was filtered out as a quality token).
            $title = trim(preg_replace('/\s*&\s*$/', '', $title) ?? $title);

            foreach ($effective as $suffix) {
                $pattern = '/[\s\-._]*\b' . preg_quote($suffix, '/') . '[\s\-._&]*$/i';
                $stripped = preg_replace($pattern, '', $title);
                if ($stripped === null) {
                    continue;
                }
                $stripped = trim($stripped);
                if ($stripped === $title) {
                    continue;
                }
                // Unless the caller opts in, never let a noise token empty the
                // title (e.g. a film literally named "DC"): keep the original.
                if ($stripped === '' && !$allowEmpty) {
                    continue;
                }
                $title = $stripped;
                $changed = true;
                break;
            }
        }

        return $title;
    }
}
