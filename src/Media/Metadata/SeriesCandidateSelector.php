<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Normalizer;
use Phlix\Media\Metadata\Dto\MetadataValue;

/**
 * Series-identification guards for the TMDB TV search.
 *
 * `/search/tv` is fuzzy: it always returns *something*, and
 * {@see SeriesMetadataResolver} historically accepted `results[0]` verbatim. Two
 * measured failure modes follow from that, both of which bind the WRONG entity to
 * a whole series subtree (every episode under it inherits the mistake):
 *
 *  1. **Spurious year match.** A `first_air_date_year` filter can strip the real
 *     show out of the result set while leaving unrelated titles that merely share
 *     a word. Measured on the live library: `The Big O` + 2001 →
 *     *"The Big Battles Of World War II"*; `Blood+` + 2000 →
 *     *"Sincerely Yours in Cold Blood"*.
 *  2. **Wrong incarnation.** The year filter picks a real same-titled entity that
 *     cannot possibly hold the local tree — `Battlestar Galactica (2003)` resolves
 *     to the 2-episode 2003 *miniseries* rather than the 4-season 2004 series;
 *     `Avatar - The Last Airbender` resolves to the 2024 live-action remake rather
 *     than the 2005 animated series.
 *
 * Both guards are deliberately **narrow and fail-closed**: they only ever move to
 * a candidate whose title is a STRICT-EXACT match for the query, and they never
 * turn a match into a non-match. A fuzzy similarity threshold was tried first and
 * rejected — measured against all 434 live series it would have un-matched 11
 * correct romaji/English title pairs (`Nurarihyon no Mago` →
 * *"Nura: Rise of the Yokai Clan"*, `Kaze no Stigma` → *"Stigma of the Wind"*, …)
 * because TMDB matches on alternative titles that no string metric can reproduce.
 *
 * ## Two folds, used in OPPOSITE senses
 *
 * {@see normalize()} (permissive: every non-alphanumeric run collapses) answers
 * *"is the winner close enough that we should trust it?"* — a **trust** test, so
 * being permissive keeps the guards quiet.
 * {@see normalizeStrict()} (keeps `+ ! ? * # @ % .` as their own tokens) answers
 * *"is this candidate the same title?"* — an **action** test that re-points every
 * file under a series, so it must be strict. Using the permissive fold for the
 * action test is unsound: `Blood+` and `Blood` collapse to the same key, and TMDB
 * **84768 `Blood`** (an unrelated 2018 Irish drama) really is offered as a
 * same-title alternative for the live `Blood+` folder.
 *
 * Pure: no I/O, no state, no persistence.
 *
 * @package Phlix\Media\Metadata
 * @since   0.42.0
 */
final class SeriesCandidateSelector
{
    /**
     * @var int Hard cap on same-title alternatives considered by
     *     {@see exactTitleAlternatives()}. Each one costs the caller a
     *     `/tv/{id}` details call, so the budget is bounded; measured against the
     *     live library the cap is never reached (max observed: 2).
     */
    public const MAX_ALTERNATIVES = 3;

    /**
     * @var string Punctuation that {@see normalizeStrict()} keeps as its own
     *     token because it distinguishes one show from another (`Blood+` vs
     *     `Blood`, `Eureka!` vs `Eureka`, `Once Upon a Time...` vs `Once Upon a
     *     Time`). Written as the inside of a character class; every character
     *     here is literal inside `[...]`, so nothing needs escaping.
     */
    private const SIGNIFICANT_CLASS = '+!?*#@%.';

    /**
     * Fold a title to its comparison form.
     *
     * Lowercase, `&` → `and`, then every non-alphanumeric run collapses to a
     * single space. Accents are decomposed first when ext-intl is present; when
     * it is not, the fold still applies IDENTICALLY to both sides of every
     * comparison, so exactness is preserved either way (a title that folds to a
     * different string simply fails the exact test and the guard stays silent).
     *
     * @param string $title Raw title.
     *
     * @return string Folded comparison key (may be empty).
     */
    public function normalize(string $title): string
    {
        $value = mb_strtolower($this->decompose($title), 'UTF-8');
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);

        return trim($value ?? '');
    }

    /**
     * Fold a title to its **strict** comparison form.
     *
     * Identical to {@see normalize()} except that {@see self::SIGNIFICANT_CLASS}
     * survive as standalone tokens instead of being deleted. That is the whole
     * difference between `Blood+` (`blood +`) and `Blood` (`blood`), between
     * `Eureka!` and `Eureka`, and between `Once Upon a Time...` and
     * `Once Upon a Time` — pairs the permissive fold cannot tell apart. Pure
     * separators (space `-` `:` `,` `'` `/` `(` `)` …) and non-Latin script still
     * collapse, so `Avatar - The Last Airbender` still folds onto TMDB's
     * `Avatar: The Last Airbender`.
     *
     * @param string $title Raw title.
     *
     * @return string Strict comparison key (may be empty).
     */
    public function normalizeStrict(string $title): string
    {
        $value = mb_strtolower($this->decompose($title), 'UTF-8');
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9' . self::SIGNIFICANT_CLASS . ']+/u', ' ', $value);
        $value = preg_replace('/([' . self::SIGNIFICANT_CLASS . '])/u', ' $1 ', $value ?? '');
        $value = preg_replace('/\s+/', ' ', $value ?? '');

        return trim($value ?? '');
    }

    /**
     * NFKD-decompose when ext-intl is present.
     *
     * When it is not, the identity fold still applies IDENTICALLY to both sides of
     * every comparison, so exactness is preserved either way (a title that folds
     * differently simply fails the test and the guard stays silent).
     *
     * @param string $title Raw title.
     *
     * @return string Decomposed title, or the input unchanged.
     */
    private function decompose(string $title): string
    {
        if (!class_exists(Normalizer::class)) {
            return $title;
        }
        $decomposed = Normalizer::normalize($title, Normalizer::FORM_KD);

        return is_string($decomposed) ? $decomposed : $title;
    }

    /**
     * True when a search candidate's `name` folds to exactly the query's
     * PERMISSIVE fold.
     *
     * This is the **trust** test — "the year filter produced a title-identical
     * hit, so leave it alone". It must stay permissive: tightening it here would
     * widen the set of winners the guards distrust, which is the opposite of what
     * is wanted. Use {@see isStrictTitleMatch()} for anything that MOVES a match.
     *
     * `TmdbProvider::searchTv()` already collapses `name`/`original_name` into a
     * single `name` key, so that is the only field to compare.
     *
     * @param string               $query     Series title being searched for.
     * @param array<string, mixed> $candidate One `searchTv()` result row.
     *
     * @return bool True on a normalized-exact title match.
     */
    public function isExactTitleMatch(string $query, array $candidate): bool
    {
        $folded = $this->normalize($query);
        if ($folded === '') {
            return false;
        }

        return $folded === $this->normalize(MetadataValue::asString($candidate['name'] ?? null));
    }

    /**
     * True when a candidate's `name` folds to exactly the query's STRICT fold.
     *
     * The **action** test: every guard that re-points a series to a different
     * TMDB entity gates on this, never on {@see isExactTitleMatch()}.
     *
     * @param string               $query     Series title being searched for.
     * @param array<string, mixed> $candidate One `searchTv()` result row.
     *
     * @return bool True on a strict-exact title match.
     */
    public function isStrictTitleMatch(string $query, array $candidate): bool
    {
        $folded = $this->normalizeStrict($query);
        if ($folded === '') {
            return false;
        }

        return $folded === $this->normalizeStrict(MetadataValue::asString($candidate['name'] ?? null));
    }

    /**
     * True when TMDB itself knows an entity under the queried title.
     *
     * Checks the entity's `name`, `original_name` and every
     * `alternative_titles` entry `TmdbProvider::getTvDetails()` returns, under the
     * PERMISSIVE fold (a loose "yes" must win, because a "yes" makes the caller
     * stand down). This is the bounded, POSITIVE replacement for the
     * unverifiable claim that a candidate "appears nowhere in the year-less
     * result list" — see {@see spuriousYearMatchReplacement()}.
     *
     * Measured against the live corpus: `Stigma of the Wind` (61333) carries the
     * alternative title `Kaze no Stigma`, so the folder of that name corroborates
     * as the SAME show; `Sincerely Yours in Cold Blood` (40895) and
     * `The Big Battles Of World War II` (101843) carry no title resembling
     * `Blood+` / `The Big O` in any language, so those two do not.
     *
     * @param string               $query   Series title being searched for.
     * @param array<string, mixed> $details A `getTvDetails()` payload.
     *
     * @return bool True when some title of the entity folds onto the query.
     */
    public function knowsTitle(string $query, array $details): bool
    {
        $folded = $this->normalize($query);
        if ($folded === '') {
            return false;
        }

        $titles = [
            MetadataValue::asString($details['name'] ?? null),
            MetadataValue::asString($details['original_name'] ?? null),
        ];
        foreach (MetadataValue::asList($details['alternative_titles'] ?? null) as $alternative) {
            $titles[] = MetadataValue::asString($alternative);
        }

        foreach ($titles as $title) {
            if ($title !== '' && $this->normalize($title) === $folded) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when two TMDB entities share a production origin.
     *
     * Corroboration that is independent of BOTH the local tree and TMDB's search
     * ranking: a same-titled entity produced in a different language and country
     * is a different show, not another incarnation of the same one. The live
     * collision this exists for is `Blood+`: TMDB **19849** (`ja`, `JP`) versus
     * TMDB **84768 `Blood`** (`en`, `IE`).
     *
     * Fail-closed — an unknown language or an unknown country on EITHER side is
     * "not corroborated", not "assume yes".
     *
     * @param array<string, mixed> $chosen      Details of the entity currently held.
     * @param array<string, mixed> $alternative Details of the candidate replacement.
     *
     * @return bool True when both the original language and at least one origin
     *     country agree.
     */
    public function sharesProductionOrigin(array $chosen, array $alternative): bool
    {
        $chosenLanguage = mb_strtolower(MetadataValue::asString($chosen['original_language'] ?? null), 'UTF-8');
        $otherLanguage = mb_strtolower(MetadataValue::asString($alternative['original_language'] ?? null), 'UTF-8');
        if ($chosenLanguage === '' || $chosenLanguage !== $otherLanguage) {
            return false;
        }

        $chosenCountries = $this->countries($chosen);
        $otherCountries = $this->countries($alternative);
        if ($chosenCountries === [] || $otherCountries === []) {
            return false;
        }

        return array_intersect($chosenCountries, $otherCountries) !== [];
    }

    /**
     * Upper-cased, non-empty `origin_country` codes of a details payload.
     *
     * @param array<string, mixed> $details A `getTvDetails()` payload.
     *
     * @return list<string> Country codes (possibly empty).
     */
    private function countries(array $details): array
    {
        $out = [];
        foreach (MetadataValue::asList($details['origin_country'] ?? null) as $country) {
            $code = mb_strtoupper(trim(MetadataValue::asString($country)), 'UTF-8');
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * Guard 1 — replace a winner the year filter fabricated.
     *
     * Proposes a replacement only when ALL of the following hold:
     *   - the year-scoped winner's title is not even a PERMISSIVE match for the
     *     query;
     *   - the winner is not among `$yearLessResults` — **the page of top-ranked
     *     unfiltered hits TMDB returned for the same query**, nothing more (see
     *     the truncation note below);
     *   - the year-less list's top hit is a STRICT title match.
     *
     * The caller must then corroborate with {@see knowsTitle()} before acting;
     * this method establishes only that TMDB's unfiltered ranking prefers a
     * strictly-title-identical show over the winner by at least a full page.
     *
     * ⚠ **Truncation — measured, not assumed.** `/search/tv` returns 20 rows per
     * page and `TmdbProvider::searchTv()` fetches only the first, so this can
     * never mean "absent from the whole result set". It was documented that way
     * and the documentation was WRONG: replaying the live corpus with every page
     * fetched, all three winners that are missing from page 1 are present deeper
     * in the same list (`Blood+` → 40895 at global rank 112 of 414; `The Big O` →
     * 101843 at 28 of 75; `Blood-C` → 43270 at 21 of 108). A year filter only
     * restricts, so the year-scoped winner is ALWAYS somewhere in the year-less
     * list and an "absent anywhere" test would fire never. Paginating is also
     * unaffordable — the live corpus contains year-less searches with 500 pages —
     * and settling for a bounded scan is worse than one page: at 3 pages
     * `The Big O`'s winner is found and the correction is lost. So the test is
     * stated as what it can actually establish: **not in TMDB's top-ranked page**.
     *
     * Measured over all 434 live series this proposes exactly twice (`The Big O`,
     * `Blood+`), both corrections, with zero changes to any other series. In
     * particular `Blood-C` — whose TMDB entry carries a Chinese primary title and
     * is likewise inexact and likewise off page 1 — is left alone, because that
     * list's top hit (`Crow's Blood`) is not a strict match either.
     *
     * @param string                      $query           Series title searched for.
     * @param array<string, mixed>        $yearScopedWinner `results[0]` of the year-scoped search.
     * @param array<int, array<string, mixed>> $yearLessResults Top-ranked unfiltered hits for the
     *     same query (one page).
     *
     * @return array<string, mixed>|null The replacement candidate, or null to keep the winner.
     */
    public function spuriousYearMatchReplacement(
        string $query,
        array $yearScopedWinner,
        array $yearLessResults
    ): ?array {
        if ($yearLessResults === []) {
            return null;
        }
        if ($this->isExactTitleMatch($query, $yearScopedWinner)) {
            return null;
        }

        $winnerId = MetadataValue::asString($yearScopedWinner['id'] ?? null);
        if ($winnerId !== '') {
            foreach ($yearLessResults as $candidate) {
                if (MetadataValue::asString($candidate['id'] ?? null) === $winnerId) {
                    // TMDB's unfiltered ranking puts this entity on the FIRST page
                    // for this query (usually an alternative-title match) — trust
                    // the year filter. This is what keeps `XIII (2011)` bound to
                    // `XIII: The Series` (34639, rank 1) instead of the unrelated
                    // `XIII` (6971) at rank 0.
                    return null;
                }
            }
        }

        $top = $yearLessResults[0];
        if (MetadataValue::asString($top['id'] ?? null) === '') {
            return null;
        }

        return $this->isStrictTitleMatch($query, $top) ? $top : null;
    }

    /**
     * Guard 2, part one — same-titled alternatives to a chosen entity.
     *
     * Returns the candidates whose title folds to exactly the query's STRICT
     * fold, excluding the one already chosen, in TMDB's own relevance order and
     * capped at {@see MAX_ALTERNATIVES}. The caller decides whether any of them is
     * a better fit; this method only enforces the title-identity precondition —
     * and the caller must ALSO corroborate production origin
     * ({@see sharesProductionOrigin()}), because a title-identity precondition
     * alone is not an identity.
     *
     * The strict fold is load-bearing here: under the permissive one this method
     * offers TMDB **84768 `Blood`** (2018, Irish, `en`/`IE`) as a same-title
     * alternative for the live `Blood+` folder, and the only thing that rejected
     * it was a season count.
     *
     * @param string                     $query     Series title searched for.
     * @param array<int, array<string, mixed>> $results Candidate rows from `searchTv()`.
     * @param string                     $excludeId TMDB id already chosen.
     *
     * @return list<array<string, mixed>> Strict-title alternatives (possibly empty).
     */
    public function exactTitleAlternatives(string $query, array $results, string $excludeId): array
    {
        $out = [];
        foreach ($results as $candidate) {
            $id = MetadataValue::asString($candidate['id'] ?? null);
            if ($id === '' || $id === $excludeId) {
                continue;
            }
            if (!$this->isStrictTitleMatch($query, $candidate)) {
                continue;
            }
            $out[] = $candidate;
            if (count($out) >= self::MAX_ALTERNATIVES) {
                break;
            }
        }

        return $out;
    }
}
