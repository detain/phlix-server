<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Auth\DbLoginRateLimitStore;
use Phlix\Auth\QuickConnectStateStore;
use Phlix\Auth\TokenSessionRepository;
use Phlix\Auth\WebAuthn\WebAuthnChallengeStore;
use Phlix\Casting\CastingSessionStore;
use Phlix\Common\RateLimit\DbRateLimiter;
use Phlix\Plugins\OAuth2\DbOAuth2StateStore;
use Phlix\Plugins\Oidc\DbOidcStateStore;
use Phlix\Server\Integrations\Lastfm\DbLastfmOAuthStateStore;
use Phlix\Server\Integrations\Trakt\DbTraktOAuthStateStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * D-14 failure-posture pins (B-03.3 / HUB-5 janitor law): a retention sweep
 * that cannot run must never sink the user-facing request riding it.
 *
 * The seven `*_posture` tests are RED-FIRST cure pins for the sites that
 * THREW today (rate limiters, OAuth state stores, WebAuthn ceremony): on the
 * pre-engine code the injected sweep failure escapes into the request path.
 * The three `already*` tests pin sites that already swallowed (tokens,
 * casting, QuickConnect — the doctrine source) and must keep swallowing
 * through the migration. Every test also asserts the sweep statement was
 * actually attempted, so the posture cannot pass by skipping the cleanup.
 */
final class RetentionPruneSitePostureTest extends TestCase
{
    /** @param callable(string, mixed): mixed $handler */
    private function failingSweepDb(callable $handler): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback($handler);
        return $db;
    }

    /**
     * Fail loudly on the retention DELETE; anything else answers like an
     * empty/no-op table (the request paths under test only need their own
     * statements to survive).
     */
    private function sweepThrowsHandler(string $sweepNeedle): callable
    {
        return static function ($sql, $params = null) use ($sweepNeedle): mixed {
            if (str_contains((string) $sql, $sweepNeedle)) {
                throw new RuntimeException('injected janitor failure: ' . $sweepNeedle);
            }
            if (str_contains((string) $sql, 'SELECT')) {
                return [];
            }
            return 1;
        };
    }

    /**
     * Capture what the run writes to the error channel (estate idiom,
     * {@see EnvConfigTest}). HUB-5.2: a soft swallow must be LOUD there —
     * survival alone is only half the law.
     *
     * @param callable(): void $run
     */
    private function captureErrorLog(callable $run): string
    {
        $file = tempnam(sys_get_temp_dir(), 'd14log');
        self::assertIsString($file);
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $run();

            return (string) file_get_contents($file);
        } finally {
            ini_set('error_log', $previous);
            unlink($file);
        }
    }

    public function testRateLimiterHitSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM rate_limit_buckets'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use (&$db, &$state): void {
            $state = (new DbRateLimiter($db))->hit('login:1.2.3.4');
        });

        $this->assertContains('DELETE FROM rate_limit_buckets WHERE reset_at <= ? LIMIT ?', $seen);
        $this->assertSame(1, $state->remaining >= 0 ? 1 : 0, 'the decision path still answered');
        $this->assertStringContainsString('sweep on rate_limit_buckets', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testLoginFailureRecordingSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM login_rate_limit'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            (new DbLoginRateLimitStore($db))->recordFailedAttempt('203.0.113.7', 'user@example.test');
        });

        $this->assertContains('DELETE FROM login_rate_limit WHERE reset_at <= ? LIMIT ?', $seen);
        $this->assertContains('DELETE FROM login_rate_limit_account WHERE reset_at <= ? LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on login_rate_limit', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testLastfmCallbackConsumeSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE expires_at'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            $this->assertNull((new DbLastfmOAuthStateStore($db))->consume('state-that-does-not-exist'));
        });
        $this->assertContains('DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testTraktCallbackConsumeSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE expires_at'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            $this->assertNull((new DbTraktOAuthStateStore($db))->consume('state-that-does-not-exist'));
        });
        $this->assertContains('DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testGenericOauth2ConsumeSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE expires_at'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            (new DbOAuth2StateStore($db, 'github'))->consume('state-that-does-not-exist');
        });

        $this->assertContains('DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testOidcCallbackConsumeSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE expires_at'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            $this->assertNull((new DbOidcStateStore($db))->consume('state-that-does-not-exist'));
        });
        $this->assertContains('DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testWebAuthnCeremonyIssueSurvivesAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE provider'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            (new WebAuthnChallengeStore($db))->issue('chal-1', WebAuthnChallengeStore::SCOPE_REGISTER, 'principal-1');
        });

        $this->assertContains('DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testQuickConnectIssueAlreadySurvivedAFailingSweep(): void
    {
        // Doctrine source — soft BEFORE and AFTER the engine; regression pin.
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM oauth_state_store WHERE provider'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            (new QuickConnectStateStore($db))->issue();
        });

        $this->assertContains('DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on oauth_state_store', $log, 'HUB-5.2: the doctrine source stayed loud through the lift');
    }

    public function testTokenGrantPruneAlreadySurvivedAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM auth_refresh_grants'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            (new TokenSessionRepository($db))->pruneExpired();
        });

        $this->assertContains('DELETE FROM auth_refresh_grants WHERE expires_at < NOW() - INTERVAL 86400 SECOND LIMIT ?', $seen);
        $this->assertContains(
            'DELETE FROM auth_refresh_grants WHERE expires_at IS NULL AND created_at < NOW() - INTERVAL 31536000 SECOND LIMIT ?',
            $seen,
            'arm 2 must not be skipped by arm 1\'s failure — per-arm swallow (D-14 r1)'
        );
        $this->assertStringContainsString('sweep on auth_refresh_grants', $log, 'HUB-5.2: the swallow was loud');
    }

    public function testCastingSweepAlreadySurvivedAFailingSweep(): void
    {
        $seen = [];
        $db = $this->failingSweepDb(function ($sql, $params = null) use (&$seen): mixed {
            $seen[] = (string) $sql;
            return ($this->sweepThrowsHandler('DELETE FROM casting_sessions'))($sql, $params);
        });

        $log = $this->captureErrorLog(function () use ($db): void {
            $this->assertNull((new CastingSessionStore($db))->find('airplay', 'device-1'));
        });
        $this->assertContains('DELETE FROM casting_sessions WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 86400 SECOND) LIMIT ?', $seen);
        $this->assertStringContainsString('sweep on casting_sessions', $log, 'HUB-5.2: the swallow was loud');
    }
}
