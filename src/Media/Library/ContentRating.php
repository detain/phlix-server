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
 * Canonical content-rating vocabulary + normalization, shared by every rating
 * rank list in the codebase so they can never drift apart.
 *
 * Historically only the seven MPAA movie ratings were recognized
 * (G/PG/PG-13/R/NC-17/X/UNRATED). Phase C adds the US TV ratings and
 * interleaves them with the movie ratings on a single ascending-restrictiveness
 * scale so a parental cap expressed in movie terms (e.g. "PG-13") also gates the
 * equivalently-rated TV content ("TV-14"):
 *
 *   rank 0: G, TV-Y, TV-G
 *   rank 1: TV-Y7
 *   rank 2: PG, TV-PG
 *   rank 3: PG-13, TV-14
 *   rank 4: R, TV-MA
 *   rank 5: NC-17
 *   rank 6: X
 *   rank 7: UNRATED
 *
 * The rank is the number the `<= maxRank` parental comparison uses; ratings that
 * share a rank are treated as equally restrictive.
 *
 * `NR` ("Not Rated") is treated as an ALIAS of `UNRATED` (the simpler of the two
 * options in the spec): {@see self::normalize()} folds it into `UNRATED` on
 * ingest, so `NR` is never stored and is deliberately absent from the rank map
 * and from the `user_profiles` / `user_settings` ENUMs.
 */
final class ContentRating
{
    /**
     * Canonical rating → rank map (ascending restrictiveness). This is THE
     * source of truth; the per-class `RATING_ORDER` constants derive from it.
     *
     * @var array<string, int>
     */
    public const RANKS = [
        'G' => 0,
        'TV-Y' => 0,
        'TV-G' => 0,
        'TV-Y7' => 1,
        'PG' => 2,
        'TV-PG' => 2,
        'PG-13' => 3,
        'TV-14' => 3,
        'R' => 4,
        'TV-MA' => 4,
        'NC-17' => 5,
        'X' => 6,
        'UNRATED' => 7,
    ];

    /**
     * Normalize a raw certification string to one of the canonical values, or
     * null when it is empty / unrecognized.
     *
     * Handles the common TMDB/NFO spellings: case + surrounding whitespace are
     * ignored; `NR` / `NOT RATED` / `UR` fold into `UNRATED`; the FfV-programming
     * suffix (`TV-Y7-FV`) collapses to its base rating. Anything not in the
     * canonical set yields null.
     *
     * NOTE: this method CANNOT distinguish "genuinely unrated" (empty/absent)
     * from "present but unrecognized" (e.g. old MPAA `M`/`GP`/`Approved`, or a
     * foreign `FSK 16`) — both collapse to null. At the storage boundary use
     * {@see self::normalizeOrRestrict()} instead, which maps an unrecognized
     * NON-empty cert to the most-restrictive `UNRATED` so a stray value can
     * never widen the parental gate (see that method for the rationale).
     *
     * @param mixed $value Raw certification (string) or anything else (→ null).
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $v = strtoupper(trim($value));
        if ($v === '') {
            return null;
        }

        if (in_array($v, ['NR', 'NOT RATED', 'UR', 'UNRATED'], true)) {
            return 'UNRATED';
        }

        if ($v === 'TV-Y7-FV') {
            $v = 'TV-Y7';
        }

        return isset(self::RANKS[$v]) ? $v : null;
    }

    /**
     * Normalize a raw certification for STORAGE, distinguishing "no rating" from
     * an "unrecognized rating" so the parental filter stays safe:
     *
     *   - empty / absent / non-string input  → null  (genuinely UNRATED — the
     *     column stays NULL, meaning "truly no rating")
     *   - present, non-empty, but unrecognized (old MPAA `M`/`GP`/`Approved`/
     *     `Passed`, foreign `FSK 16`/`18`, etc.) → `'UNRATED'` (rank 7, the most
     *     restrictive value) so a restrictive parental cap HIDES it
     *   - recognized → the canonical mapped value (via {@see self::normalize()})
     *
     * This is the correct choice for {@see \Phlix\Media\Library\ItemRepository::extractContentRating()}:
     * storing null for an unrecognized-but-present cert would (combined with a
     * NULL-inclusive rating filter) leak the item to EVERY profile, including
     * kids. Routing it to `UNRATED` instead keeps such items behind an
     * `allow_unrated` gate / restrictive cap.
     *
     * @param mixed $value Raw certification (string) or anything else.
     */
    public static function normalizeOrRestrict(mixed $value): ?string
    {
        // Genuinely no rating: absent / non-string / blank → NULL.
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        // Present but unrecognized → the most-restrictive UNRATED, never NULL.
        return self::normalize($value) ?? 'UNRATED';
    }

    /**
     * Whether a value is a canonical content rating (after normalization).
     */
    public static function isValid(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * The ascending-restrictiveness rank for a rating, or null when unknown.
     */
    public static function rank(string $rating): ?int
    {
        $normalized = self::normalize($rating);
        return $normalized === null ? null : self::RANKS[$normalized];
    }
}
