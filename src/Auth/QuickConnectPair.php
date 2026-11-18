<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

/**
 * One quick-connect pairing attempt (S518 / AD-25, server half).
 *
 * The transient record the TV mints via `POST /api/v1/auth/quick-connect/initiate`
 * and later redeems via `.../token`. It is deliberately a small immutable value
 * object: the store hydrates rows, the controller reads fields, nobody mutates a
 * pairing in place — the state machine (`pending → approved | denied`) advances
 * by the store persisting a new state, never by callers poking this object.
 *
 * Field contract (the wire half lives in
 * {@see \Phlix\Server\Http\Controllers\Auth\QuickConnectController}):
 * - `code`   — short human-typeable/QR payload, 6 chars from the unambiguous
 *              alphabet (see {@see QuickConnectStateStore::ALPHABET}).
 * - `secret` — 256-bit bearer credential of the pairing, returned at initiate
 *              ONLY to the initiating device and sent back on approve/token.
 * - `state`  — one of {@see STATE_PENDING}, {@see STATE_APPROVED},
 *              {@see STATE_DENIED}. There is intentionally NO `expired` state
 *              stored: expiry is a function of `expiresAt`, and the controller
 *              reports unknown-or-expired uniformly so an attacker cannot probe
 *              which pairing codes exist.
 * - `userId` — set only once a real authenticated session approved the pairing;
 *              this is the identity the token endpoint later mints for.
 * - `expiresAt` — unix second after which the row is dead to every read.
 * - `profileId` — the profile the APPROVING session was running as, resolved and
 *              stored at APPROVE time (never at redemption). The token endpoint
 *              binds the minted session to this profile, not to the account default,
 *              so a TV paired from an open profile P cannot end up holding a session
 *              on an unscheduled default profile D. It is refused at redemption when
 *              the account no longer owns it; it is never degraded to the default.
 *              Null means the approval stored no profile. That is refused, except
 *              for `legacyNoProfileField` below.
 * - `legacyNoProfileField` — true only for a pairing whose stored blob has NO
 *              `profile_id` key at all, i.e. one approved before the profile field
 *              existed. Only this shape redeems onto the account default, and that is
 *              the documented bound on the fallback. A stored null is not legacy.
 *
 * @package Phlix\Auth
 * @since 1.2.3
 */
final class QuickConnectPair
{
    /** Pairing created, awaiting phone/app approval. */
    public const string STATE_PENDING = 'pending';

    /** Pairing approved by an authenticated session; `userId` is the identity to mint. */
    public const string STATE_APPROVED = 'approved';

    /** Pairing explicitly refused. Terminal; indistinguishable to outsiders from expiry. */
    public const string STATE_DENIED = 'denied';

    public function __construct(
        public readonly string $code,
        public readonly string $secret,
        public readonly string $state,
        public readonly ?string $userId,
        public readonly int $expiresAt,
        public readonly ?string $profileId = null,
        public readonly bool $legacyNoProfileField = false,
    ) {
    }

    /**
     * Has the pairing window closed at `$now` (unix seconds)?
     *
     * Single source of truth for expiry so the store's SQL (`expires_at > NOW()`)
     * and the controller's `expiresIn` arithmetic can never disagree by one tick:
     * both derive the window from the SAME `expiresAt` this object carries.
     */
    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
