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
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use PHPUnit\Framework\TestCase;
use stdClass;
use Workerman\MySQL\Connection;

/**
 * SRV-4.1 adoption pins: the scattered mint sites (user create/update,
 * profile PIN mints, password verification) now speak through
 * {@see PasswordHasher}, and a successful login carrying a drifted-cost
 * credential re-mints it forward to the canonical parameters.
 *
 * The drift fixture is minted with a raw password_hash() call on purpose —
 * a canonical mint could not express the state under test. The src/-side
 * SSOT exclusivity is enforced by PasswordHasherMintSiteCensusTest.
 */
final class PasswordHasherAdoptionTest extends TestCase
{
    /** Canonical PHC prefix every SRV-4 mint must carry. */
    private const CANONICAL_EMBEDDED_PARAMS = '$argon2id$v=19$m=65536,t=4,p=1$';

    /**
     * Build a UserRepository whose Connection records every statement.
     *
     * The recorder travels as an object handle: PHP's list() destructuring
     * drops reference bindings, so returning the array by reference here
     * silently loses the captures.
     *
     * @param array<string, mixed> $userRow row returned for any SELECT
     *
     * @return array{UserRepository, stdClass} the stdClass carries ->calls
     */
    private function repoRecordingQueries(array $userRow): array
    {
        $recorder = new stdClass();
        $recorder->calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param array<int|string, mixed> $params */
            static function (string $sql, array $params = []) use ($recorder, $userRow) {
                $recorder->calls[] = [$sql, $params];
                if (stripos($sql, 'SELECT') === 0) {
                    return [$userRow];
                }
                return 0;
            }
        );

        return [new UserRepository($db), $recorder];
    }

    /**
     * @param stdClass $recorder recorder from {@see self::repoRecordingQueries()}
     *
     * @return list<array{string, array<int|string, mixed>}>
     */
    private function updates(stdClass $recorder): array
    {
        return array_values(array_filter(
            $recorder->calls,
            static fn(array $call): bool => stripos($call[0], 'UPDATE') === 0
        ));
    }

    public function testASuccessfulLoginWithADriftedCredentialReMintsItCanonical(): void
    {
        $legacy = password_hash('correct horse battery staple', PASSWORD_ARGON2ID, [
            'memory_cost' => 1024,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        [$repo, $rec] = $this->repoRecordingQueries([
            'id' => 'user-1',
            'username' => 'u1',
            'email' => 'u1@example.invalid',
            'display_name' => 'U One',
            'password_hash' => $legacy,
            'status' => 'active',
        ]);

        $this->assertTrue($repo->verifyPassword('user-1', 'correct horse battery staple'));

        $updates = $this->updates($rec);

        $this->assertCount(
            1,
            $updates,
            'A drifted-cost credential that just authenticated must be re-minted to the '
                . 'canonical parameters exactly once (B-06 forward-only correction).'
        );
        [$sql, $params] = $updates[0];
        $this->assertStringStartsWith('UPDATE users SET password_hash = ?', $sql);
        $this->assertSame('user-1', $params[1]);

        $reminted = $params[0];
        $this->assertIsString($reminted);
        $this->assertStringStartsWith(self::CANONICAL_EMBEDDED_PARAMS, $reminted);
        $this->assertTrue(
            PasswordHasher::verify('correct horse battery staple', $reminted),
            'The re-mint must authenticate the same password it replaced.'
        );
        $this->assertFalse(PasswordHasher::needsRehash($reminted));
    }

    public function testASuccessfulLoginWithACanonicalCredentialWritesNothing(): void
    {
        [$repo, $rec] = $this->repoRecordingQueries([
            'id' => 'user-2',
            'username' => 'u2',
            'email' => 'u2@example.invalid',
            'display_name' => 'U Two',
            'password_hash' => PasswordHasher::hash('already-canonical'),
            'status' => 'active',
        ]);

        $this->assertTrue($repo->verifyPassword('user-2', 'already-canonical'));

        $this->assertCount(
            1,
            $rec->calls,
            'Canonical credentials must not churn — the re-mint fires only on drift.'
        );
        $this->assertStringStartsWith('SELECT', $rec->calls[0][0]);
    }

    public function testAFailedVerificationNeverRewritesTheStoredCredential(): void
    {
        [$repo, $rec] = $this->repoRecordingQueries([
            'id' => 'user-3',
            'username' => 'u3',
            'email' => 'u3@example.invalid',
            'display_name' => 'U Three',
            'password_hash' => password_hash('right-ones', PASSWORD_ARGON2ID, [
                'memory_cost' => 1024,
                'time_cost' => 1,
                'threads' => 1,
            ]),
            'status' => 'active',
        ]);

        $this->assertFalse($repo->verifyPassword('user-3', 'wrong-one'));

        $this->assertCount(
            1,
            $rec->calls,
            'Re-minting happens only after a SUCCESSFUL verify — an attacker cannot '
                . 'cause password-store writes with failed guesses.'
        );
    }

    public function testAnExternalAccountWithNoPasswordStillVerifiesFalse(): void
    {
        [$repo, $rec] = $this->repoRecordingQueries([
            'id' => 'user-4',
            'username' => 'u4',
            'email' => 'u4@example.invalid',
            'password_hash' => null,
            'status' => 'active',
        ]);

        $this->assertFalse($repo->verifyPassword('user-4', 'anything'));
        $this->assertCount(1, $rec->calls, 'A NULL-password row must short-circuit before any write.');
    }

    public function testUserCreateMintsThroughTheCanonicalOptionSet(): void
    {
        [$repo, $rec] = $this->repoRecordingQueries([]);

        $repo->create([
            'username' => 'newbie',
            'email' => 'new@example.invalid',
            'password' => 'brand-new-password',
        ]);

        $inserts = array_values(array_filter(
            $rec->calls,
            static fn(array $call): bool => stripos($call[0], 'INSERT INTO users') === 0
        ));
        $this->assertCount(1, $inserts);
        // INSERT column order: (id, username, email, password_hash, display_name, status)
        $passwordHash = $inserts[0][1][3];
        $this->assertIsString($passwordHash);
        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $passwordHash,
            'Every user mint must carry the canonical parameters explicitly.'
        );
    }

    public function testAPasswordChangeStillCarriesTheRevocationStampAlongsideACanonicalMint(): void
    {
        [$repo, $rec] = $this->repoRecordingQueries([]);

        $repo->update('user-9', ['password' => 'rotated-secret']);

        $this->assertCount(1, $rec->calls);
        [$sql, $params] = $rec->calls[0];
        $this->assertStringContainsString('password_hash = ?', $sql);
        $this->assertStringContainsString(
            'tokens_not_valid_after = NOW()',
            $sql,
            'M-1 law: a password CHANGE is a revocation event. The transparent cost '
                . 're-mint on login deliberately does NOT stamp this — do not merge '
                . 'the two shapes.'
        );
        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $params[0],
            'Password-change mints go through the same canonical SSOT.'
        );
    }

    public function testProfilePinMintsUseTheCanonicalOptionSet(): void
    {
        $recorder = new stdClass();
        $recorder->calls = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param array<int|string, mixed> $params */
            static function (string $sql, array $params = []) use ($recorder) {
                $recorder->calls[] = [$sql, $params];
                return 0;
            }
        );

        $manager = new UserProfileManager($db);
        $manager->setPin('profile-1', '482917');

        $this->assertCount(1, $recorder->calls);
        $this->assertStringStartsWith('UPDATE profile_settings SET pin_hash = ?', $recorder->calls[0][0]);
        $pinHash = $recorder->calls[0][1][0];
        $this->assertIsString($pinHash);
        $this->assertStringStartsWith(
            self::CANONICAL_EMBEDDED_PARAMS,
            $pinHash,
            'The PIN is a password-quality secret: same mint, same canonical costs.'
        );
        $this->assertTrue(PasswordHasher::verify('482917', $pinHash));
    }
}
