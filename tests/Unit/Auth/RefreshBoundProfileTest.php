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
     * Finding 7. A single UNCONSUMED presentation for a profile the account no longer owns is audited
     * as the ownership refusal alone. The grant is not consumed and the family is not revoked, so the
     * refusal does not reach the reuse path at all.
     */
    public function testAnUnconsumedRefreshForAnUnownedProfileIsAuditedAsOwnershipAndLeavesTheGrantUnconsumed(): void
    {
        $jwt = $this->jwt();
        $family = 'f' . str_repeat('e', 35);
        $token = $jwt->createRefreshToken(self::USER, [
            JwtHandler::CLAIM_PROFILE_ID => self::BOUND,
            JwtHandler::CLAIM_SESSION_ID => $family,
        ]);
        $jti = (string) (($jwt->validateToken($token)['jti'] ?? ''));

        $this->owned = [self::DEFAULT];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => $jti,
            'sessionId' => $family,
            'sessionStatus' => 'active',
            'consumed' => false,
            'withinGrace' => false,
        ]);
        $store->expects(self::never())->method('markGrantConsumed');
        $store->expects(self::never())->method('revokeSession');

        $reasons = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason) use (&$reasons): void {
                $reasons[] = $reason;
            }
        );

        try {
            $this->manager($jwt, null, $store, $audit)->refreshToken($token);
            self::fail('a refused refresh must not mint');
        } catch (\InvalidArgumentException) {
            // expected: the ownership refusal
        }

        self::assertSame(['profile_not_owned'], $reasons, 'an unconsumed presentation is the ownership refusal alone');
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
     * Finding G. A replay of a token already rotated OUTSIDE the grace window is reuse, even when its
     * bound profile has since been removed. The ownership refusal used to answer first, with no reuse
     * check, so the family stayed live. Now the replay revokes that one family, audited as
     * refresh_token_reuse, and the grant is not consumed again.
     */
    public function testAReusedRefreshForAProfileTheAccountNoLongerOwnsRevokesTheFamily(): void
    {
        [$token, $jti, $family] = $this->consumedFamilyToken();
        $this->owned = [self::DEFAULT];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => $jti,
            'sessionId' => $family,
            'sessionStatus' => 'active',
            'consumed' => true,
            'withinGrace' => false,
        ]);
        $store->expects(self::once())->method('revokeSession')->with($family, 'refresh_token_reuse');
        $store->expects(self::never())->method('markGrantConsumed');

        $reasons = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason) use (&$reasons): void {
                $reasons[] = $reason;
            }
        );

        $manager = $this->manager($this->jwt(), null, $store, $audit);

        try {
            $manager->refreshToken($token);
            self::fail('a reused, refused refresh must not mint');
        } catch (\InvalidArgumentException) {
            // expected: the family is revoked and the refresh refused
        }

        self::assertSame(['refresh_token_reuse'], $reasons, 'the replay is audited as reuse, not as the ownership refusal');
    }

    /**
     * Finding G, the benign side. A consumed presentation INSIDE the grace window for a removed profile
     * is the ownership refusal only: nothing is revoked, as the adjudication's grace rule says.
     */
    public function testAReplayInsideTheGraceWindowForAProfileTheAccountNoLongerOwnsIsNotRevoked(): void
    {
        [$token, $jti, $family] = $this->consumedFamilyToken();
        $this->owned = [self::DEFAULT];

        $store = $this->createMock(TokenSessionRepository::class);
        $store->method('findGrant')->willReturn([
            'jti' => $jti,
            'sessionId' => $family,
            'sessionStatus' => 'active',
            'consumed' => true,
            'withinGrace' => true,
        ]);
        $store->expects(self::never())->method('revokeSession');

        $reasons = [];
        $audit = $this->createMock(AuditLogger::class);
        $audit->method('logFailedAuth')->willReturnCallback(
            static function (string $reason) use (&$reasons): void {
                $reasons[] = $reason;
            }
        );

        try {
            $this->manager($this->jwt(), null, $store, $audit)->refreshToken($token);
            self::fail('a refused refresh must not mint');
        } catch (\InvalidArgumentException) {
            // expected: the ownership refusal
        }

        self::assertSame(['profile_not_owned'], $reasons);
    }

    /**
     * A family-carrying refresh token bound to the BOUND profile, with its jti and family id.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function consumedFamilyToken(): array
    {
        $jwt = $this->jwt();
        $family = 'f' . str_repeat('e', 35);
        $token = $jwt->createRefreshToken(self::USER, [
            JwtHandler::CLAIM_PROFILE_ID => self::BOUND,
            JwtHandler::CLAIM_SESSION_ID => $family,
        ]);
        $jti = (string) (($jwt->validateToken($token)['jti'] ?? ''));

        return [$token, $jti, $family];
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
     * A claim lookup that fails outright must not resolve to the account default. The first lookup
     * throws (the DB is down). The previous code then retried the same claim lookup, and the retry
     * found the claim unowned and fell back to the default. Now the failed lookup refuses the token,
     * the same null as an unowned claim, and the retry is never made.
     */
    public function testAFailedClaimLookupRefusesTheTokenAndNeverResolvesToTheAccountDefault(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken(self::USER, [JwtHandler::CLAIM_PROFILE_ID => self::BOUND]);

        $claimLookups = 0;
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')->willReturnCallback(
            function (string $userId, ?string $requested = null) use (&$claimLookups): string {
                if ($requested === null || $requested === '') {
                    return self::DEFAULT;
                }
                $claimLookups++;
                if ($claimLookups === 1) {
                    throw new \RuntimeException('database unavailable');
                }

                // A retry would find the claim no longer owned.
                throw ProfileNotOwnedException::forRequestedProfile($userId, $requested);
            }
        );

        self::assertNull(
            $this->manager($jwt, $profiles)->validateAccessToken($token),
            'a failed lookup refuses the token, it never resolves to the account default'
        );
        self::assertSame(1, $claimLookups, 'the refused token is not retried through the default path');
    }

    /**
     * Item 1. With no profile resolver wired (a null profile manager) a profile claim cannot be verified.
     * The token is refused, the same null as a failed claim lookup. It must not fall through to
     * resolveProfileForUser(), which returns null and so would let the account-active profile stand in.
     */
    public function testAClaimedAccessTokenIsRefusedWhenNoProfileResolverIsWired(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken(self::USER, [JwtHandler::CLAIM_PROFILE_ID => self::BOUND]);

        self::assertNull(
            $this->managerWithoutProfileResolver($jwt)->validateAccessToken($token),
            'a claim with no resolver wired refuses the token, it does not resolve to the account default'
        );
    }

    /**
     * Item 1, the unchanged path. A token with NO profile claim keeps its behaviour with no resolver wired:
     * the token is accepted and carries no profile, exactly as before the fix.
     */
    public function testAClaimlessAccessTokenIsUnchangedWhenNoProfileResolverIsWired(): void
    {
        $jwt = $this->jwt();
        $token = $jwt->createAccessToken(self::USER);

        $info = $this->managerWithoutProfileResolver($jwt)->validateAccessToken($token);

        self::assertIsArray($info);
        self::assertNull($info['profile_id'], 'a pre-S80 token with no resolver carries no profile, as before');
    }

    /**
     * The manager with no UserProfileManager at all. The helper manager() cannot express this, because its
     * null-coalescing default always supplies one.
     */
    private function managerWithoutProfileResolver(JwtHandler $jwt): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturn(['status' => 'active', 'tokensNotValidAfter' => 0]);
        $repo->method('findById')->willReturn([
            'id' => self::USER,
            'username' => 'nina',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        return new AuthManager(
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            new StructuredLogger('test', ['handlers' => []]),
        );
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
     * The seam itself. buildAuthResponseForOwnedProfile() verifies the id once and mints it as-is.
     * buildAuthResponse() keeps the default-resolution path for every other caller. The public
     * verified flag is gone (it was a bool any caller could pass as true), so only the method that
     * verifies can take the verified path.
     */
    public function testBuildAuthResponseForOwnedProfileVerifiesOnceAndMintsAsIs(): void
    {
        $jwt = $this->jwt();

        $lookups = [];
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('resolveProfileIdForUser')->willReturnCallback(
            function (string $userId, ?string $requested = null) use (&$lookups): string {
                $lookups[] = $requested;

                return $requested ?? self::DEFAULT;
            }
        );

        $manager = $this->manager($jwt, $profiles);

        $verified = $manager->buildAuthResponseForOwnedProfile(self::USER, self::BOUND);
        self::assertSame(self::BOUND, $verified['profile_id'], 'the verified id is minted as-is');
        self::assertSame([self::BOUND], $lookups, 'the owned-profile mint verifies once and never re-resolves');

        $lookups = [];
        $unverified = $manager->buildAuthResponse(self::USER);
        self::assertSame(self::DEFAULT, $unverified['profile_id'], 'an unverified call still resolves the default');
        self::assertSame([null], $lookups, 'the default-resolution path is unchanged for other callers');
    }

    /**
     * The owned-profile mint never degrades a profile the account no longer owns to the default.
     */
    public function testBuildAuthResponseForOwnedProfileRefusesAProfileTheAccountNoLongerOwns(): void
    {
        $this->owned = [self::DEFAULT];

        $this->expectException(ProfileNotOwnedException::class);
        $this->manager($this->jwt())->buildAuthResponseForOwnedProfile(self::USER, self::BOUND);
    }
}
