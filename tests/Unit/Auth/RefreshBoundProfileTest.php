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
use Phlix\Auth\TokenSessionRepository;
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

    /**
     * A profile manager whose ownership is the test's `$owned` list: the claimed id is
     * returned when owned, the default otherwise, and a removed id throws.
     */
    private function ownedProfiles(): UserProfileManager
    {
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

        return $profiles;
    }

    private function manager(
        JwtHandler $jwt,
        ?UserProfileManager $profiles = null,
        ?TokenSessionRepository $tokenSessions = null,
        ?AuditLogger $audit = null,
    ): AuthManager {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);
        $repo->method('findById')->willReturn([
            'id' => self::USER,
            'username' => 'nina',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        // No log handlers attached, so the refresh path writes no file.
        return new AuthManager(
            $repo,
            $jwt,
            $audit ?? $this->createMock(AuditLogger::class),
            new StructuredLogger('test', ['handlers' => []]),
            profileManager: $profiles ?? $this->ownedProfiles(),
            tokenSessions: $tokenSessions,
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

    /**
     * Finding 6. A family-carrying refresh whose bound profile is gone is refused BEFORE the family
     * adjudication, so the refusal never consumes the presented grant. A retry is then audited as the
     * same ownership refusal, not as 'refresh_token_reuse' (which would revoke the family).
     *
     * The store is stateful: the grant is live until something consumes it. Had the refusal run after
     * the adjudication, the first presentation would mark the grant consumed, and a retry outside the
     * grace window would read as reuse.
     */
    public function testARefusedSessionRefreshDoesNotConsumeItsGrantSoARetryIsNotReuse(): void
    {
        $jwt = $this->jwt();
        $family = 'f' . str_repeat('e', 35);
        $token = $jwt->createRefreshToken(self::USER, [
            JwtHandler::CLAIM_PROFILE_ID => self::BOUND,
            JwtHandler::CLAIM_SESSION_ID => $family,
        ]);
        $jti = (string) (($jwt->validateToken($token)['jti'] ?? ''));

        // The bound profile is removed before the presentation.
        $this->owned = [self::DEFAULT];

        $consumed = false;
        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturnCallback(
            static function () use (&$consumed, $jti, $family): array {
                return [
                    'jti' => $jti,
                    'sessionId' => $family,
                    'sessionStatus' => 'active',
                    'consumed' => $consumed,
                    'withinGrace' => false,
                ];
            }
        );
        $store->method('markGrantConsumed')->willReturnCallback(
            static function () use (&$consumed): bool {
                $consumed = true;

                return true;
            }
        );
        $store->expects(self::never())->method('revokeSession');

        $reasons = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason) use (&$reasons): void {
                $reasons[] = $reason;
            }
        );

        $manager = $this->manager($jwt, null, $store, $audit);

        // The first presentation is refused, then the client retries.
        foreach ([1, 2] as $_) {
            try {
                $manager->refreshToken($token);
                self::fail('a refused refresh must not mint');
            } catch (\InvalidArgumentException) {
                // expected: the ownership refusal
            }
        }

        self::assertFalse($consumed, 'a refused presentation must not consume its grant');
        self::assertSame(
            ['profile_not_owned', 'profile_not_owned'],
            $reasons,
            'the retry is audited as the ownership refusal, not refresh_token_reuse'
        );
    }

    /**
     * The access leg (finding 2). A profile claim the account no longer owns refuses the
     * access token. It must not run as the default for the token's whole lifetime.
     */
    public function testAnAccessTokenBoundToAProfileTheAccountNoLongerOwnsIsRefused(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt);
        $token = $jwt->createAccessToken(self::USER, [JwtHandler::CLAIM_PROFILE_ID => self::BOUND]);

        $this->owned = [self::DEFAULT];

        self::assertNull(
            $manager->validateAccessToken($token),
            'a stale profile claim refuses the token, it does not degrade to the default'
        );
    }

    public function testAnAccessTokenBoundToAnOwnedProfileKeepsIt(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken(self::USER, [JwtHandler::CLAIM_PROFILE_ID => self::BOUND]);

        $info = $this->manager($jwt)->validateAccessToken($token);

        self::assertIsArray($info);
        self::assertSame(self::BOUND, $info['profile_id']);
    }

    public function testAnAccessTokenWithNoProfileClaimStillResolvesTheDefault(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken(self::USER);

        $info = $this->manager($jwt)->validateAccessToken($token);

        self::assertIsArray($info);
        self::assertSame(self::DEFAULT, $info['profile_id'], 'a pre-S80 access token keeps the account default');
    }

    /**
     * Finding 3. The refresh verifies the bound profile once and mints exactly that id. The mint does
     * not look the profile up a second time, so there is no second read for a removal to land in.
     */
    public function testARefreshMintsTheProfileItVerifiedWithoutResolvingItAgain(): void
    {
        $jwt = $this->jwt();

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::once())->method('resolveProfileIdForUser')
            ->with(self::USER, self::BOUND)
            ->willReturn(self::BOUND);

        $response = $this->manager($jwt, $profiles)->refreshToken($this->boundRefreshToken($jwt));

        self::assertSame(self::BOUND, $response['profile_id']);
    }

    /**
     * Finding 3, the race itself. The profile is owned at the check and removed before the mint. The
     * mint still carries the verified id. Re-resolving it would find the removal and degrade to the
     * default, so this test fails on the earlier code.
     */
    public function testAProfileRemovedBetweenTheCheckAndTheMintStillMintsTheVerifiedProfile(): void
    {
        $jwt = $this->jwt();

        $checks = 0;
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')->willReturnCallback(
            function (string $userId, ?string $requested = null) use (&$checks): string {
                $checks++;
                if ($checks === 1) {
                    return self::BOUND;
                }

                // Removed after the ownership check.
                throw ProfileNotOwnedException::forRequestedProfile($userId, (string) $requested);
            }
        );

        $response = $this->manager($jwt, $profiles)->refreshToken($this->boundRefreshToken($jwt));

        self::assertSame(
            self::BOUND,
            $response['profile_id'],
            'the verified profile is minted, never the default from a second, failing lookup'
        );
    }

    /**
     * The seam itself. A verified id is minted as-is with no lookup. An unverified one still goes
     * through the default-resolution path, so every other caller keeps its behaviour.
     */
    public function testBuildAuthResponseMintsAVerifiedIdAsIsAndStillResolvesAnUnverifiedOne(): void
    {
        $jwt = $this->jwt();

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::once())->method('resolveProfileIdForUser')
            ->with(self::USER, null)
            ->willReturn(self::DEFAULT);

        $manager = $this->manager($jwt, $profiles);

        $verified = $manager->buildAuthResponse(self::USER, self::BOUND, null, null, null, true);
        self::assertSame(self::BOUND, $verified['profile_id'], 'a verified id is minted without a lookup');

        $unverified = $manager->buildAuthResponse(self::USER);
        self::assertSame(self::DEFAULT, $unverified['profile_id'], 'an unverified call still resolves the default');
    }
}
