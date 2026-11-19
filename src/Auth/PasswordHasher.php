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
 * Single source of truth for password-quality hashing (B-06, 2026-11-19).
 *
 * Before this class every ARGON2ID mint in the repo was a bare
 * `password_hash(..., PASSWORD_ARGON2ID)` call: nine call sites estate-wide,
 * each silently pinning its cost parameters to whatever the running PHP
 * declared as ini defaults at first-mint time, and `password_needs_rehash()`
 * had zero call sites — so a drifted or weaker-than-intended cost was never
 * corrected. This class owns all three shapes of the operation:
 *
 *  - one canonical options array ({@see self::ARGON2ID_OPTIONS}) that every
 *    mint passes explicitly and every needsRehash() answer is measured
 *    against;
 *  - the only password_verify() call shape used by first-party code;
 *  - needsRehash(), consumed by {@see UserRepository::verifyPassword()} to
 *    re-mint a stored credential to canonical cost on a SUCCESSFUL login —
 *    a forward-only correction: password_verify() always reads the
 *    per-hash embedded parameters, so pre-existing hashes keep verifying
 *    until their owner next signs in, and no migration is needed.
 *
 * The three public signatures below (hash/verify/needsRehash) ARE the
 * estate SSOT contract: the hub builds the same public API in its own tree
 * for this wave, and the planned phlix-shared consolidation moves this
 * class across unchanged — which is why the names, order and parameter
 * shapes are fixed.
 *
 * Cost parameters must change in lockstep with the timing-equalization
 * dummy hash at UserRepository::TIMING_BURN_DUMMY_HASH, or unknown-user and
 * known-user logins stop costing the same; PasswordHasherTest pins that
 * coupling.
 */
final class PasswordHasher
{
    /**
     * The one canonical ARGON2ID cost set, passed explicitly at every mint.
     *
     * - memory_cost 65536 KiB (64 MiB) per hash — the RFC 9106 lowest
     *   recommended memory for a general-purpose interactive login, and
     *   comfortably inside the 12-core no-GPU boxes this project deploys on.
     * - time_cost 4 — four passes; with memory_cost at 64 MiB this keeps a
     *   single hash/verify at roughly 100 ms, which is the envelope the
     *   login-path timing burn (UserRepository::burnPasswordVerifyTime)
     *   documents and equalizes against.
     * - threads 1 — parallelism buys wall-clock on multicloud hosts but no
     *   security; fixing p=1 makes the mint cost host-independent so the
     *   ~100 ms envelope stays honest, and it is the shape of the existing
     *   TIMING_BURN_DUMMY_HASH and of every hash already minted by this
     *   code on stock PHP.
     *
     * These values deliberately match PHP 8.3's built-in PASSWORD_ARGON2ID
     * defaults so credentials already minted by the old bare calls on
     * standard hosts are canonical from day one — needsRehash() answers
     * false for them and nothing churns. Hashes minted under non-default
     * ini values (or bcrypt/argon2i history) are flagged and re-minted on
     * the owner's next successful login.
     *
     * @var array{memory_cost: int, time_cost: int, threads: int}
     */
    public const ARGON2ID_OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    /** Pure static surface — this class is not instantiable. */
    private function __construct()
    {
    }

    /**
     * Mint a password hash with the canonical ARGON2ID cost set.
     *
     * @param string $password the plaintext credential
     *
     * @return string the PHC-format hash, canonical parameters embedded
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }

    /**
     * Verify a plaintext candidate against any stored hash shape.
     *
     * password_verify() reads the algorithm and cost parameters embedded in
     * the hash itself, so this accepts canonical mints and every legacy
     * format still present in the users table alike.
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * True when $hash was not minted with the canonical cost set (or is not
     * an ARGON2ID hash at all, or is garbage) — the signal for a login path
     * to re-mint the credential forward after a successful verify.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
    }
}
