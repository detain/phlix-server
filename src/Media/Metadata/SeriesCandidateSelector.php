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
 * a candidate whose title is a NORMALIZED-EXACT match for the query, and they
 * never turn a match into a non-match. A fuzzy similarity threshold was tried
 * first and rejected — measured against all 434 live series it would have
 * un-matched 11 correct romaji/English title pairs (`Nurarihyon no Mago` →
 * *"Nura: Rise of the Yokai Clan"*, `Kaze no Stigma` → *"Stigma of the Wind"*, …)
 * because TMDB matches on alternative titles that no string metric can reproduce.
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
        $value = $title;
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $value = $decomposed;
            }
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);

        return trim($value ?? '');
    }

    /**
     * True when a search candidate's `name` folds to exactly the query's fold.
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
     * Guard 1 — replace a winner the year filter fabricated.
     *
     * Fires only when ALL of the following hold, which is what keeps it quiet:
     *   - the year-scoped winner's title is NOT an exact match for the query;
     *   - the winner does not appear ANYWHERE in the same query's year-less
     *     result list (i.e. TMDB's own unfiltered relevance ranking does not
     *     consider it a match at all — the year filter alone surfaced it);
     *   - the year-less list's top hit IS an exact title match.
     *
     * Measured over all 434 live series this fires exactly twice (`The Big O`,
     * `Blood+`), both corrections, with zero changes to any other series. In
     * particular `Blood-C` — whose TMDB entry carries a Chinese primary title and
     * is therefore also inexact and also absent from the year-less list — is left
     * alone, because that list's top hit (`Crow's Blood`) is not exact either.
     *
     * @param string                      $query           Series title searched for.
     * @param array<string, mixed>        $yearScopedWinner `results[0]` of the year-scoped search.
     * @param array<int, array<string, mixed>> $yearLessResults Results of the same query without a year.
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
                    // TMDB's unfiltered ranking DOES know this entity for this
                    // query (alternative-title match) — trust the year filter.
                    return null;
                }
            }
        }

        $top = $yearLessResults[0];
        if (MetadataValue::asString($top['id'] ?? null) === '') {
            return null;
        }

        return $this->isExactTitleMatch($query, $top) ? $top : null;
    }

    /**
     * Guard 2, part one — same-titled alternatives to a chosen entity.
     *
     * Returns the candidates whose title folds to exactly the query's fold,
     * excluding the one already chosen, in TMDB's own relevance order and capped
     * at {@see MAX_ALTERNATIVES}. The caller decides whether any of them is a
     * better fit; this method only enforces the title-identity precondition.
     *
     * @param string                     $query     Series title searched for.
     * @param array<int, array<string, mixed>> $results Candidate rows from `searchTv()`.
     * @param string                     $excludeId TMDB id already chosen.
     *
     * @return list<array<string, mixed>> Exact-title alternatives (possibly empty).
     */
    public function exactTitleAlternatives(string $query, array $results, string $excludeId): array
    {
        $out = [];
        foreach ($results as $candidate) {
            $id = MetadataValue::asString($candidate['id'] ?? null);
            if ($id === '' || $id === $excludeId) {
                continue;
            }
            if (!$this->isExactTitleMatch($query, $candidate)) {
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
