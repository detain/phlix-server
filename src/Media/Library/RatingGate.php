<?php

/**
 * Phlix media server component: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;

/**
 * The single parental-control ACCESS gate shared by every user-facing read and
 * stream path, so the ~15 handlers that must honour a profile's content-rating
 * cap never re-implement (and never drift on) the effective-rating + allow-list
 * decision.
 *
 * It composes two concerns:
 *
 *  1. {@see resolveFilterForUser()} — turn a request's account id into the
 *     active profile's cap `['allowedRatings' => string[], 'allowUnrated' =>
 *     bool]`, or `null` (the permissive default: "no gate"). Null is returned
 *     for the account OWNER/admin, an unauthenticated request, no active
 *     profile, no cap configured, or the most-permissive cap — mirroring
 *     {@see \Phlix\Server\WebPortal\WebPortalRouter}'s existing browse logic so
 *     the gate can never accidentally block the owner.
 *
 *  2. {@see isAllowed()} / {@see filterItems()} — decide whether an item (or
 *     each item in a list) may be shown, using its EFFECTIVE rating
 *     ({@see ItemRepository::effectiveContentRating()}: own `content_rating`
 *     else the inherited series rating). A NULL effective rating is "genuinely
 *     unrated" and is allowed only when the cap permits unrated content.
 *
 * OWNER / NO-CAP NO-OP: with a `null` filter every check is the identity —
 * {@see isAllowed()} returns true and {@see filterItems()} returns the list
 * unchanged — so a caller that always passes the resolved filter through this
 * gate is a strict no-op for the owner and for un-capped profiles.
 */
final class RatingGate
{
    /**
     * @param ItemRepository      $items    Effective-rating resolution (DB walk).
     * @param UserProfileManager  $profiles Active-profile cap resolution.
     * @param UserRepository|null $users    Account-owner (is_admin) lookup; when
     *                                       null the account-owner shortcut is
     *                                       skipped (profile-level guards still
     *                                       apply).
     */
    public function __construct(
        private readonly ItemRepository $items,
        private readonly UserProfileManager $profiles,
        private readonly ?UserRepository $users = null,
    ) {
    }

    /**
     * Resolve the parental content-rating cap that governs the given account's
     * current request, or null when NO gating should apply.
     *
     * Null (permissive) is returned when:
     *   - the account id is empty (unauthenticated);
     *   - the account is an admin (the owner/manager — never gated);
     *   - {@see UserProfileManager::getActiveRatingFilter()} yields null (no
     *     active profile, an `is_admin` profile, no cap configured, or the
     *     most-permissive cap).
     *
     * @param string $userId The authenticated account id ('' if none).
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    public function resolveFilterForUser(string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        // Account owner / admin: never gated, regardless of the active profile.
        if ($this->users !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null && ($user['is_admin'] ?? 0) == 1) {
                return null;
            }
        }

        return $this->profiles->getActiveRatingFilter($userId);
    }

    /**
     * Whether a single item may be shown under the given cap.
     *
     * A null `$filter` is the owner/no-cap no-op → always true. Otherwise the
     * item's EFFECTIVE rating decides: a rated item must be in the allow-list; a
     * genuinely-unrated item (null effective rating) is allowed only when the
     * cap permits unrated content.
     *
     * @param array<string, mixed>|string                                   $item   Row or id.
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null   $filter Active cap.
     */
    public function isAllowed(array|string $item, ?array $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        if (is_array($item)) {
            // Row in hand: decide from its OWN rating with zero DB work; only
            // walk ancestors (via the repository) when it is unrated but nested.
            $own = self::ratingColumnOf($item);
            if ($own !== null) {
                return self::ratingPasses($own, $filter);
            }

            $parentId = self::parentIdOf($item);
            if ($parentId === null) {
                return self::ratingPasses(null, $filter);
            }

            $inherited = $this->items->effectiveContentRatingsForIds([$parentId])[$parentId] ?? null;
            return self::ratingPasses($inherited, $filter);
        }

        $effective = $this->items->effectiveContentRatingsForIds([$item])[$item] ?? null;
        return self::ratingPasses($effective, $filter);
    }

    /**
     * Filter a list of item-bearing rows down to those the cap allows.
     *
     * A null `$filter` is the owner/no-cap no-op → the list is returned
     * unchanged (only re-indexed). Otherwise every row's media id is read from
     * `$idKey`, all effective ratings are resolved in a single batch
     * ({@see ItemRepository::effectiveContentRatingsForIds()} — no N+1), and
     * rows failing the cap are dropped. Rows without a usable id are dropped when
     * a cap is active (fail-closed: an unidentifiable row can't be proven safe).
     *
     * @param array<int, array<string, mixed>>                             $items  Rows to filter.
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter Active cap.
     * @param string                                                       $idKey  Row key holding the media id.
     *
     * @return list<array<string, mixed>> The allowed rows (re-indexed).
     */
    public function filterItems(array $items, ?array $filter, string $idKey = 'id'): array
    {
        if ($filter === null) {
            return array_values($items);
        }

        // First pass: settle every row we can from its OWN columns (rows that
        // carry `content_rating`), and collect the rest for a SINGLE batch
        // ancestor/id resolution (no N+1). `$decided[$i]` holds each row's
        // verdict; `$needLookup[$i]` holds the id to resolve for the undecided.
        $decided = [];
        $needLookup = [];
        foreach ($items as $i => $row) {
            $id = $row[$idKey] ?? null;
            if (!is_string($id) || $id === '') {
                // Fail-closed under an active cap: cannot verify → exclude.
                $decided[$i] = false;
                continue;
            }

            if (array_key_exists('content_rating', $row)) {
                $own = self::ratingColumnOf($row);
                if ($own !== null) {
                    $decided[$i] = self::ratingPasses($own, $filter);
                    continue;
                }
                $parentId = self::parentIdOf($row);
                if ($parentId === null) {
                    $decided[$i] = self::ratingPasses(null, $filter);
                    continue;
                }
                $needLookup[$i] = $parentId;
            } else {
                // Row does not carry the rating column → resolve by its own id.
                $needLookup[$i] = $id;
            }
        }

        if ($needLookup !== []) {
            $effective = $this->items->effectiveContentRatingsForIds(array_values($needLookup));
            foreach ($needLookup as $i => $lookupId) {
                $decided[$i] = self::ratingPasses($effective[$lookupId] ?? null, $filter);
            }
        }

        $out = [];
        foreach ($items as $i => $row) {
            if ($decided[$i] ?? false) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Core allow decision for an already-resolved effective rating.
     *
     * @param array{allowedRatings: list<string>, allowUnrated: bool} $filter
     */
    private static function ratingPasses(?string $effective, array $filter): bool
    {
        if ($effective === null || $effective === '') {
            // Genuinely unrated: only when the cap permits unrated content.
            return ($filter['allowUnrated'] ?? true) === true;
        }

        $allowed = $filter['allowedRatings'] ?? [];

        return is_array($allowed) && in_array($effective, $allowed, true);
    }

    /**
     * The present, non-empty `content_rating` of a row, or null.
     *
     * @param array<string, mixed> $row A media item row.
     */
    private static function ratingColumnOf(array $row): ?string
    {
        $rating = $row['content_rating'] ?? null;
        return (is_string($rating) && $rating !== '') ? $rating : null;
    }

    /**
     * The present, non-empty `parent_id` of a row, or null.
     *
     * @param array<string, mixed> $row A media item row.
     */
    private static function parentIdOf(array $row): ?string
    {
        $parentId = $row['parent_id'] ?? null;
        return (is_string($parentId) && $parentId !== '') ? $parentId : null;
    }
}
