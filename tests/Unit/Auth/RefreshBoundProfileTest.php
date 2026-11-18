<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProfileNotOwnedException;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * The refresh leg of the profile binding.
 *
 * A refresh token carries the family's `profile_id` claim, and every rotation re-carries it.
 * So the claim is the family's bound profile. A refresh must keep that profile, or refuse when
 * the account no longer owns it. It must never re-derive the account default, which is what the
 * access path does for a stale claim. The defect this pins: a bearer bound to a removed profile
 * refreshes into the default, and so escapes the lock on the profile it was bound to.
 */
final class RefreshBoundProfileTest extends TestCase
{
    private const USER = 'user-1';

    private const BOUND = 'profile-bound';

    private const DEFAULT = 'profile-default';

    /** @var list<string> Profile ids the account still owns. A removed profile leaves this list. */
    private array $owned = [];

    protected function setUp(): void
    {
        $this->owned = [self::BOUND, self::DEFAULT];
    }

    private function jwt(): JwtHandler
    {
        return new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
    }

    private function manager(JwtHandler $jwt): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);
        $repo->method('findById')->willReturn([
            'id' => self::USER,
            'username' => 'nina',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')->willReturnCallback(
            function (string $userId, ?string $requested = null): string {
                if ($requested === null || $requested === '') {
                    return self::DEFAULT;
                }
                if (in_array($requested, $this->owned, true)) {
                    return $requested;
                }

                throw ProfileNotOwnedException::forRequestedProfile($userId, $requested);
            }
        );

        // No log handlers attached, so the refresh path writes no file.
        return new AuthManager(
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            new StructuredLogger('test', ['handlers' => []]),
            profileManager: $profiles,
        );
    }

    /** A refresh token bound to the BOUND profile, as a session that switched to it would hold. */
    private function boundRefreshToken(JwtHandler $jwt): string
    {
        return $jwt->createRefreshToken(self::USER, [JwtHandler::CLAIM_PROFILE_ID => self::BOUND]);
    }

    public function testRefreshKeepsTheFamilyBoundProfileOnBothTokens(): void
    {
        $jwt = $this->jwt();
        $response = $this->manager($jwt)->refreshToken($this->boundRefreshToken($jwt));

        self::assertSame(self::BOUND, $response['profile_id'], 'the re-mint stays on the bound profile');
        self::assertSame(
            self::BOUND,
            JwtHandler::profileIdClaim($jwt->validateToken((string) $response['refresh_token'])),
            'the rotated refresh token carries the bound profile, so the NEXT refresh keeps it too'
        );
    }

    public function testRefreshRefusesABoundProfileTheAccountNoLongerOwns(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt);
        $token = $this->boundRefreshToken($jwt);

        // The bound profile is removed. The refresh must refuse, not re-mint into the default.
        $this->owned = [self::DEFAULT];

        $this->expectException(\InvalidArgumentException::class);
        $manager->refreshToken($token);
    }

    public function testARefusalIsNotServedFromTheRotationGraceMemo(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt);
        $token = $this->boundRefreshToken($jwt);

        // The first presentation succeeds and is memoised for the grace window.
        $first = $manager->refreshToken($token);
        self::assertSame(self::BOUND, $first['profile_id']);

        // The profile is removed inside that window. The second presentation must refuse:
        // the ownership guard runs before the memo, so the memoised pair is not re-delivered.
        $this->owned = [self::DEFAULT];

        $this->expectException(\InvalidArgumentException::class);
        $manager->refreshToken($token);
    }

    public function testAPreS80RefreshTokenWithNoProfileClaimStillLandsOnTheDefault(): void
    {
        $jwt = $this->jwt();
        $response = $this->manager($jwt)->refreshToken($jwt->createRefreshToken(self::USER));

        self::assertSame(
            self::DEFAULT,
            $response['profile_id'],
            'a token with no profile claim predates binding and keeps the account default'
        );
    }
}
