<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\PasswordHasher;
use Phlix\Auth\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the B-06 password-hashing SSOT: one canonical ARGON2ID cost set, a
 * verify() that reads the per-hash embedded parameters, and a needsRehash()
 * that answers against the canonical set so successful logins can re-mint
 * drifted credentials forward (see PasswordHasher's class docblock).
 *
 * The rehash matrix below deliberately mints its legacy fixtures with raw
 * password_hash() calls at NON-canonical costs — that is the point of the
 * fixture, and why the src/-only adoption guard lives in
 * PasswordHasherMintSiteCensusTest rather than here.
 */
final class PasswordHasherTest extends TestCase
{
    /** The exact PHC string prefix a canonical mint must carry. */
    private const CANONICAL_EMBEDDED_PARAMS = '$argon2id$v=19$m=65536,t=4,p=1$';

    public function testCanonicalOptionsAreTheDocumentedArgon2idTriple(): void
    {
        $this->assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            PasswordHasher::ARGON2ID_OPTIONS,
            'The canonical cost set is the estate SSOT constant; changing it must go '
                . 'together with regenerating UserRepository::TIMING_BURN_DUMMY_HASH '
                . '(pinned by testTheTimingBurnDummyHashCarriesTheCanonicalParameters) '
                . 'and with the hub twin for the wave-2 phlix-shared consolidation.'
        );
    }

    public function testHashEmbedsTheCanonicalArgon2idParameters(): void
    {
        $hash = PasswordHasher::hash('red-first-canonical-mint');

        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $hash,
            'Every mint must carry the canonical memory/time/threads parameters in the '
                . 'hash itself, not whatever the host PHP has as ini defaults today.'
        );
    }

    public function testVerifyRoundTripAcceptsTheMintedPassword(): void
    {
        $hash = PasswordHasher::hash('round-trip-password');

        $this->assertTrue(PasswordHasher::verify('round-trip-password', $hash));
    }

    public function testVerifyRejectsTheWrongPassword(): void
    {
        $hash = PasswordHasher::hash('round-trip-password');

        $this->assertFalse(PasswordHasher::verify('wrong-password', $hash));
    }

    /**
     * Matrix of stored-hash shapes a rehash-aware login path can meet, with the
     * answer needsRehash() must give for each.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function rehashMatrixProvider(): iterable
    {
        yield 'canonical-mint-needs-no-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ]),
            false,
        ];

        yield 'legacy-low-cost-argon2id-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2ID, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ]),
            true,
        ];

        yield 'argon2i-variant-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_ARGON2I, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ]),
            true,
        ];

        yield 'bcrypt-needs-rehash' => [
            password_hash('matrix-password', PASSWORD_BCRYPT),
            true,
        ];
    }

    #[DataProvider('rehashMatrixProvider')]
    public function testNeedsRehashAnswersTheDriftMatrix(string $hash, bool $expected): void
    {
        $this->assertSame(
            $expected,
            PasswordHasher::needsRehash($hash),
            'needsRehash() must flag every non-canonical cost shape (and only those) '
                . 'so the login path can re-mint drifted credentials forward.'
        );
    }

    public function testNeedsRehashTreatsGarbageAsNonCanonical(): void
    {
        $this->assertTrue(
            PasswordHasher::needsRehash('not-a-password-hash'),
            'A stored value that is not a canonical ARGON2ID PHC string is by definition '
                . 'not canonical — needsRehash() answers true without throwing. That is safe '
                . 'on the login path because the re-mint is gated on a successful verify() '
                . 'first, and garbage never verifies.'
        );
    }

    public function testTheTimingBurnDummyHashCarriesTheCanonicalParameters(): void
    {
        $dummy = (new ReflectionClass(UserRepository::class))->getConstant('TIMING_BURN_DUMMY_HASH');

        $this->assertIsString($dummy);
        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $dummy,
            'UserRepository::burnPasswordVerifyTime() equalizes known-user vs unknown-user '
                . 'login timing by verifying against this dummy hash. If its embedded cost '
                . 'shape stops matching the canonical options the two paths stop costing the '
                . 'same — regenerate the dummy hash in lockstep with any options change.'
        );
    }
}
