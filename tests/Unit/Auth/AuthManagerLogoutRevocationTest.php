<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Session\SessionManager;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;

/**
 * M-1 (security audit 2026-09-29) — logout is now a real server-side
 * revocation, not a cookie wipe.
 *
 * {@see AuthManager::logout()} must bump the per-user `tokens_not_valid_after`
 * watermark (killing every JWT minted up to NOW() on both the access-token and
 * refresh paths — watermark rejection itself is pinned in
 * AuthManagerSignupGateTest) and, when a SessionManager is wired, end the
 * user's device-session rows so the cookie-backed store cannot outlive the
 * revoked tokens. Bookkeeping emissions (REASON_EXPIRED) must NOT nuke a live
 * account's tokens.
 */
final class AuthManagerLogoutRevocationTest extends TestCase
{
    private function manager(UserRepository $repo, ?SessionManager $sessions): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-at-least-32-bytes-long!!', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            null,      // logger -> shared AUTH channel (writes to .logs, harmless)
            null,      // eventDispatcher
            null,      // db
            null,      // providerManager
            null,      // statsCollector
            null,      // settingsRepository
            null,      // loginRateLimitStore
            null,      // profileManager
            $sessions, // sessionManager (12th ctor param, M-1 wiring)
        );
    }

    public function test_explicit_logout_bumps_watermark_and_ends_device_sessions(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('revokeTokensBeforeNow')->with('u-1');
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects($this->once())->method('endAllUserSessions')->with('u-1');

        $this->manager($repo, $sessions)->logout('u-1', 'device-1');
    }

    public function test_admin_revoked_logout_also_bumps(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('revokeTokensBeforeNow');
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects($this->once())->method('endAllUserSessions');

        $this->manager($repo, $sessions)->logout('u-1', 'device-1', UserLoggedOut::REASON_REVOKED);
    }

    public function test_expired_bookkeeping_does_not_revoke_live_tokens(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->never())->method('revokeTokensBeforeNow');
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects($this->never())->method('endAllUserSessions');

        $this->manager($repo, $sessions)->logout('u-1', 'device-1', UserLoggedOut::REASON_EXPIRED);
    }

    public function test_logout_without_session_manager_still_revokes_tokens(): void
    {
        // The JWT denylist is the PRIMARY control — a legacy hand-built
        // manager (no SessionManager) must still kill the tokens.
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('revokeTokensBeforeNow')->with('u-1');

        $this->manager($repo, null)->logout('u-1', 'device-1');
    }

    public function test_revoke_user_tokens_invalidates_local_status_cache(): void
    {
        // Warm the 5s auth-state cache, then revoke: the same worker must see
        // the new watermark on its very NEXT request (invalidate is immediate;
        // only cross-worker convergence waits out the TTL).
        $dbCalls = 0;
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturnCallback(
            static function (string $id) use (&$dbCalls): array {
                $dbCalls++;
                // First read: never revoked. Later reads: revoked one hour
                // into the future — every token minted before that dies.
                return $dbCalls === 1
                    ? ['status' => 'active', 'tokensNotValidAfter' => 0]
                    : ['status' => 'active', 'tokensNotValidAfter' => time() + 3600];
            }
        );
        $repo->expects($this->once())->method('revokeTokensBeforeNow')->with('u-1');

        $manager = $this->manager($repo, null);

        $token = (new JwtHandler('test-secret-key-at-least-32-bytes-long!!', 'HS256', 3600, 604800))
            ->createAccessToken('u-1');
        $this->assertNotNull($manager->validateAccessToken($token));
        $this->assertSame(1, $dbCalls, 'first validation must hit the DB exactly once (cache warm)');

        $manager->revokeUserTokens('u-1', 'unit-test');

        // After the bump the cache is invalidated, so the very next validation
        // re-reads — and the future watermark must already reject this token.
        $this->assertNull($manager->validateAccessToken($token));
        $this->assertSame(2, $dbCalls, 'revocation must have cleared the cached state');
    }
}
