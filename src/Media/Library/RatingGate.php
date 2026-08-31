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
use Phlix\Server\Http\RequestContext;

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
 *     for the account OWNER/admin, no active profile, no cap configured, or the
 *     most-permissive cap — mirroring
 *     {@see \Phlix\Server\WebPortal\WebPortalRouter}'s existing browse logic so
 *     the gate can never accidentally block the owner. An **unidentified**
 *     request is NOT in that list: see the S235 note below.
 *
 * 🚨 S235 — "no user" is NOT "no cap". Until S235 a `null` filter meant BOTH
 * "the owner/an un-capped profile: do not gate" AND "there is no user at all",
 * and every one of the ~26 `if ($filter !== null && …)` guards across the server
 * therefore skipped the check entirely for an anonymous caller. On the one
 * deliberately-public route that mints a signed URL
 * (`GET /api/v1/media/{id}/download`) that was a live parental bypass: a
 * restricted profile got a playable URL for ANY item simply by not sending its
 * Bearer token. The two states now have DIFFERENT representations —
 * {@see resolveFilterForUser()} answers {@see denyAll()} (a real, non-null cap
 * that allows nothing) for an empty account id, so every existing guard fails
 * CLOSED without being touched. The handful of paths where an unidentified
 * request is legitimately authorised by a signed URL / token opt out
 * EXPLICITLY through {@see resolveFilterForSignedRequest()}.
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
     * The single allow-list entry carried by the "no identified user" cap
     * ({@see denyAll()}).
     *
     * ⚠️ It is a real, improbable STRING rather than the obvious empty
     * allow-list `[]`, and that is load-bearing in the SQL enforcement path:
     * {@see ItemRepository::ratingCapClause()} documents (and implements) "an
     * empty allow-list yields an empty clause (NO filtering)", so a `[]` cap
     * merged into the browse/index params by
     * {@see \Phlix\Server\WebPortal\WebPortalRouter::applyRatingFilter()} would
     * silently fail OPEN — the exact inversion of what this cap means. A
     * one-element list yields `content_rating IN ('…')`, which matches no real
     * row (`content_rating` holds 'G' / 'PG-13' / 'TV-MA' / …), so the cap is
     * deny-all in the SQL path and in {@see ratingPasses()} alike.
     */
    public const NO_USER_RATING = '__phlix_no_user__';

    /**
     * The cap that allows NOTHING — the representation of "there is no
     * identified user" (S235), as distinct from `null` ("no gating applies").
     *
     * Nothing rated passes ({@see NO_USER_RATING} matches no real row) and
     * nothing unrated passes (`allowUnrated: false`), in both the PHP
     * ({@see isAllowed()} / {@see filterItems()}) and SQL
     * ({@see ItemRepository::query()}) enforcement paths.
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    public static function denyAll(): array
    {
        return ['allowedRatings' => [self::NO_USER_RATING], 'allowUnrated' => false];
    }

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
     *   - the account is an admin (the owner/manager — never gated);
     *   - {@see UserProfileManager::getActiveRatingFilter()} yields null (no
     *     active profile, an `is_admin` profile, no cap configured, or the
     *     most-permissive cap).
     *
     * 🚨 An EMPTY account id is NOT permissive (S235): it yields
     * {@see denyAll()}, so a caller that cannot name its user cannot obtain what
     * a capped profile is refused. Callers that legitimately serve an
     * unidentified request — because a signed URL or an OPDS token is the access
     * control on that route — must say so by calling
     * {@see resolveFilterForSignedRequest()} instead.
     *
     * @param string $userId The authenticated account id ('' if none).
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    public function resolveFilterForUser(string $userId, ?string $profileId = null): ?array
    {
        if ($userId === '') {
            return self::denyAll();
        }

        // Account owner / admin: never gated, regardless of the active profile.
        if ($this->users !== null) {
            $user = $this->users->findById($userId);
            if ($user !== null && ($user['is_admin'] ?? 0) == 1) {
                return null;
            }
        }

        // S81: a switched session rides a non-default profile; the rating cap
        // must follow the SESSION's profile, not the account-wide active one.
        // RequestContext carries the verified profile_id claim (S80) on every
        // request path; outside a request (CLI, tests) it is null and the
        // account-wide active profile applies exactly as before. A profile id
        // that is not owned resolves to null inside getActiveRatingFilter(),
        // so an unverifiable claim can never widen the filter.
        $profileId ??= RequestContext::getProfileId();

        return $this->profiles->getActiveRatingFilter($userId, $profileId);
    }

    /**
     * The EXPLICIT opt-out from {@see resolveFilterForUser()}'s fail-closed
     * treatment of an unidentified request (S235), for the routes where the
     * access control is a signed URL / OPDS token rather than a session.
     *
     * Returns null (no gating) for an empty account id, and is otherwise
     * identical to {@see resolveFilterForUser()}.
     *
     * ⚠️ Use this ONLY where an unidentified caller is genuinely authorised by
     * something other than a session, i.e. behind
     * {@see \Phlix\Server\Http\Middleware\SignedUrlMiddleware}. Today that is:
     *
     *   - {@see \Phlix\Server\Http\Controllers\TranscodeFileServer::transcodeJobOverCap()}
     *     — `/hls/{job}/…` and `/dash/{job}/…`. The `<video>`/hls.js manifest
     *     fetch carries only the signature (no Bearer header can be attached to
     *     a bare manifest URL), so failing closed here would break ALL
     *     transcoded playback. The mint paths (start/status/detail) are gated.
     *   - {@see \Phlix\Server\Http\Controllers\BookController::bookOverCap()} —
     *     `/opds/v1.2/…` and `/api/v1/books/{id}/{read,cover,download}`, fetched
     *     by an e-reader or an `<img>`/`<a download>` with the signature alone.
     *     (An OPDS client that authenticates with HTTP Basic DOES get a userId —
     *     {@see \Phlix\Server\Http\Middleware\SignedUrlMiddleware} sets it — so
     *     it is still capped.)
     *
     * A route with NO such middleware must NOT use this: an unsigned anonymous
     * request there is exactly the S235 bypass.
     *
     * @param string $userId The authenticated account id ('' if none).
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    public function resolveFilterForSignedRequest(string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        return $this->resolveFilterForUser($userId);
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
