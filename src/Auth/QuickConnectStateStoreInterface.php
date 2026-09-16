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
 * Contract for the quick-connect pairing store (S518 / AD-25).
 *
 * The seam exists for the same doctrine every other state store in the estate
 * follows (`OAuth2StateStore`, `HubJwtValidatorInterface`): the controller
 * depends on the STATE MACHINE, not on MySQL, so unit venues can double the
 * transitions while the real-MySQL file proves the SQL. The only production
 * implementation is {@see QuickConnectStateStore} — under Workerman a
 * process-local pairing store would strand legs of a two-device flow across
 * the ~14 resident HTTP workers, so an in-memory sibling is deliberately NOT
 * offered.
 *
 * Every `RESULT_*` code below is part of the store↔controller wire vocabulary;
 * the controller maps them to HTTP statuses, never the reverse.
 *
 * @package Phlix\Auth
 * @since 1.2.3
 */
interface QuickConnectStateStoreInterface
{
    /** approve() outcome: row missing/expired/swept, or state JSON unreadable. */
    public const string RESULT_UNKNOWN = 'unknown';

    /** approve()/consumeApproved() outcome: the presented secret did not match (constant-time). */
    public const string RESULT_BAD_SECRET = 'bad_secret';

    /** approve()/consumeApproved() outcome: the row is not in the required state. */
    public const string RESULT_NOT_READY = 'not_ready';

    /** approve() success transition; consumeApproved() successful one-shot redemption. */
    public const string RESULT_APPROVED = 'approved';

    /** consumeApproved() outcome: the one-shot delete was refused — fail closed, mint nothing. */
    public const string RESULT_STORAGE = 'storage';

    /**
     * Mint a fresh pending pairing (unambiguous short code + 256-bit secret).
     *
     * @throws \RuntimeException when the pairing could not be persisted — a
     *                           caller must never receive a pairing the store
     *                           does not hold.
     */
    public function issue(): QuickConnectPair;

    /** Non-destructive read by code, INCLUDING expired-but-unswept rows. */
    public function find(string $code): ?QuickConnectPair;

    /**
     * Secret-gated pending → approved transition under a row lock.
     *
     * @return string One of RESULT_APPROVED / RESULT_UNKNOWN / RESULT_BAD_SECRET / RESULT_NOT_READY.
     */
    public function approve(string $code, string $secret, string $userId): string;

    /**
     * One-shot redemption of an APPROVED pairing: verify secret, delete row,
     * return the pair whose userId is the identity to mint.
     *
     * @return array{0: string, 1: ?QuickConnectPair}
     */
    public function consumeApproved(string $code, string $secret): array;

    /** Bounded janitorial sweep of this provider's expired rows. */
    public function purgeExpired(): void;
}
