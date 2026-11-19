<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common;

use InvalidArgumentException;

/**
 * The single policy table for security tokens and wire-vocabulary hex mints.
 *
 * ## Why this class exists (E-13)
 *
 * Before this class, twenty-eight call sites scattered across the server
 * wrote `bin2hex(random_bytes(N))` inline. Each `N` was size-by-accident —
 * copy-pasted, never justified, and invisible to review: the CLI password
 * reset silently minted 12 bytes where the admin endpoint minted 32 for the
 * same secret family. Here every entropy budget is NAMED and PINNED in one
 * file, `tests/Unit/Common/TokenMintCensusTest.php` reddens CI if a raw
 * hex mint reappears outside it, and a reviewer of a new token consumer
 * reads the generator name instead of counting bytes.
 *
 * ## Entropy law
 *
 * Every mint goes through `random_bytes()` — never `mt_rand()`. The S443
 * incident (see `Uuid`) proved that MT streams are process-global and
 * `mt_srand()`-steerable by PHPUnit's own order seeding; a replayed stream
 * is a replayed secret. `test_the_entropy_stream_is_not_mt_srand_steerable`
 * in the unit test pins this the same way `UuidOrderSeedFuzzTest` pins it
 * for ids.
 *
 * ## The policy table
 *
 * - `state()` — 16 bytes. OAuth/OIDC CSRF `state` and nonce correlation
 *   values. The estate standard since the first Last.fm integration.
 * - `pkceVerifier()` — 32 bytes (64 hex chars). RFC 7636 §4.1 code_verifier;
 *   comfortably inside the 43..128 unreserved-character window.
 * - `resetToken()` — 32 bytes. Password-reset secrets and generated
 *   passwords. DELIBERATELY RISEN from the 12-byte accident in
 *   `UserResetPasswordCommand`: reset tokens are stored hashed
 *   (`PasswordHasher::hash` / `setPasswordResetToken`) and CLI passwords are
 *   written through `UserRepository::update` which hashes, so the wider
 *   input has NO schema impact — only the operator-visible generated
 *   password grows from 24 to 64 characters.
 * - `inviteToken()` — 32 bytes. Join-link / membership-invite secrets,
 *   matching the 32-byte budget the admin reset path already established.
 * - `correlation()` — 32 bytes. The browser-binding secret behind OAuth2
 *   `StateCorrelation::issue()` (see that class header).
 * - `prefixed()` — caller-declared budget under a literal wire prefix, for
 *   shapes consumers parse by prefix: the `'phlix-'` newsletter MIME
 *   boundary and the `'sp_'` SyncPlay group id.
 * - `jti()` — 16 bytes. JWT id claims; shape-identical to the `JwtHandler`
 *   mint it now performs (the banked follow-up swap landed) — wire bytes never
 *   moved, which is the width law: auth_refresh_grants.jti is CHAR(32).
 * - `tmpSuffix()` — 8 bytes. Temp-file / atomic-write collision suffixes.
 *   NOT security state — nothing secret is named by it; it lives here so
 *   the one remaining hex-mint family in `src/` is generated from the same
 *   audited entropy policy instead of ad-hoc 4/5/6/8-byte accidents.
 *
 * ## What deliberately stays outside Token (census-allowlisted, see the
 * guard test's `ALLOWED_RAW_MINT_FILES` rationale per file)
 *
 * - DLNA object-id / device-serial hex suffixes (`Dlna/*`) — UPnP wire
 *   vocabulary shapes, entropy source already ratified CSPRNG by SRV-2.2.
 * - `SyncPlayBridge`'s per-boot tripwire secret — security state, but it
 *   belongs to its owning lane; a named generator will replace it there.
 * - `BootIdentity`'s process label — observability only, gates nothing.
 * - The remaining temp-suffix sites under `Media/` and the installer —
 *   adoption into `Token::tmpSuffix()` is follow-up work for the lanes
 *   that own those files.
 *
 * (Disclosure: the scan brief's leave-list named an S3Crypto AES-key mint,
 * an S3 signed-URL expiry, an SSDP `uniqueServiceName` hex, a
 * SchedulesDirect uuid-derived uid and an ffmpeg output-validator temp
 * path as staying raw; none of those sites exist as `bin2hex(random_bytes(`
 * in `src/` today — the truthfully-remaining raw mints are the families
 * listed above, and each is named file-by-file in the guard allowlist.)
 */
final readonly class Token
{
    /** OAuth/OIDC state + nonce correlation budget. */
    private const STATE_BYTES = 16;

    /** RFC 7636 code_verifier budget. */
    private const PKCE_VERIFIER_BYTES = 32;

    /** Password-reset / generated-password budget (risen from the 12B CLI accident). */
    private const RESET_BYTES = 32;

    /** Invite-secret budget. */
    private const INVITE_BYTES = 32;

    /** OAuth2 state-correlation browser-binding secret budget. */
    private const CORRELATION_BYTES = 32;

    /** JWT id-claim budget (16 bytes = the 32-char CHAR(32) grant-PK wire shape). */
    private const JTI_BYTES = 16;

    /** Temp-file collision-suffix budget. */
    private const TMP_SUFFIX_BYTES = 8;

    /** Upper bound a caller may request via prefixed() — guards fat-finger sizes. */
    private const MAX_BYTES = 64;

    /** Policy table — this class is never instantiated. */
    private function __construct()
    {
    }

    /**
     * CSRF `state` / OIDC nonce value for OAuth handshakes (16 bytes, 32 hex chars).
     */
    public static function state(): string
    {
        return self::hex(self::STATE_BYTES);
    }

    /**
     * RFC 7636 PKCE code_verifier (32 bytes, 64 hex chars).
     */
    public static function pkceVerifier(): string
    {
        return self::hex(self::PKCE_VERIFIER_BYTES);
    }

    /**
     * Password-reset secret or operator-visible generated password (32 bytes).
     */
    public static function resetToken(): string
    {
        return self::hex(self::RESET_BYTES);
    }

    /**
     * Join-link / membership-invite secret (32 bytes).
     */
    public static function inviteToken(): string
    {
        return self::hex(self::INVITE_BYTES);
    }

    /**
     * Browser-binding correlation secret behind OAuth2 StateCorrelation (32 bytes).
     */
    public static function correlation(): string
    {
        return self::hex(self::CORRELATION_BYTES);
    }

    /**
     * A hex mint carrying a literal wire prefix, e.g. `prefixed('sp_', 8)`.
     *
     * @throws InvalidArgumentException when the prefix is empty or the byte
     *                                  budget falls outside 1..MAX_BYTES
     */
    public static function prefixed(string $prefix, int $bytes): string
    {
        if ($prefix === '') {
            throw new InvalidArgumentException('prefixed() requires a non-empty wire prefix.');
        }
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new InvalidArgumentException(
                'prefixed() byte budget must be within 1..' . self::MAX_BYTES . ', got ' . $bytes . '.'
            );
        }

        return $prefix . self::hex($bytes);
    }

    /**
     * JWT id claim (16 bytes — the JwtHandler refresh-mint shape: 32 lowercase
     * hex, width-pinned against the CHAR(32) auth_refresh_grants.jti PK).
     */
    public static function jti(): string
    {
        return self::hex(self::JTI_BYTES);
    }

    /**
     * Collision suffix for temp-file / atomic-write names. Not security state.
     */
    public static function tmpSuffix(): string
    {
        return self::hex(self::TMP_SUFFIX_BYTES);
    }

    /**
     * The one CSPRNG-to-hex mint in first-party production code; the census
     * guard pins this class as its only home outside the allowlist.
     *
     * @param int<1,max> $bytes
     */
    private static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
