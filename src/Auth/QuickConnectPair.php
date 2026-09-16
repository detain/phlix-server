<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
