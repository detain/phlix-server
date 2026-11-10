<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * CP-005 (COOKIE-DESIGN.md §5): the refresh rotation grace window.
 *
 * Every leg maps to ONE distinct mutant of the memo (defect-inheritance law):
 *
 *  - same-pair identity      ← strip-the-grace (parent behavior);
 *  - distinct-jti isolation  ← memo keyed on something coarser than jti;
 *  - guards-first            ← memo consulted BEFORE the watermark check;
 *  - expiry                  ← a memo entry that never dies;
 *  - cap                     ← an unbounded memo on a resident worker.
 *
 * The harness mirrors AuthManagerStatusCacheTest: a real AuthManager over a
 * scripted UserRepository, real JwtHandler, silent logger.
 */
final class RefreshRotationGraceTest extends TestCase
{
    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_grace_' . uniqid('', true) . '.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ]);
    }

    private function jwt(): JwtHandler
    {
        return new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800);
    }

    /**
     * @param \Closure(): array{status: ?string, tokensNotValidAfter: int} $authState
     */
    private function manager(JwtHandler $jwt, \Closure $authState): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturnCallback($authState);
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'nina',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        return new AuthManager(
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    /** @return \Closure(): array{status: string, tokensNotValidAfter: int} */
    private function activeState(): \Closure
    {
        return static fn (): array => ['status' => 'active', 'tokensNotValidAfter' => 0];
    }

    public function test_repeat_presentation_within_window_redelivers_the_identical_pair(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt, $this->activeState());
        $token = $jwt->createRefreshToken('user-1');

        $first = $manager->refreshToken($token);
        $second = $manager->refreshToken($token);

        $this->assertSame($first, $second, 'grace re-delivers the rotated pair verbatim');
        $this->assertArrayHasKey('refresh_token', $first);
    }

    public function test_distinct_presentations_rotate_independently(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt, $this->activeState());

        $a = $manager->refreshToken($jwt->createRefreshToken('user-1'));
        $b = $manager->refreshToken($jwt->createRefreshToken('user-1'));

        // Isolation is proven on the ROTATING artifact: access tokens are
        // deterministic payloads (sub/iat/exp/type, no jti), so two mints in
        // the same second legitimately share a string. Distinct refresh
        // tokens here mean distinct memo keys — a collision-prone key would
        // have re-delivered A's pair wholesale.
        $this->assertNotSame($a['refresh_token'], $b['refresh_token']);
    }

    public function test_the_grace_window_never_memoizes_the_authorization(): void
    {
        // Guards run FIRST on every presentation: a watermark bump landing
        // mid-window must refuse the same old token that just enjoyed a grace
        // re-delivery. If the memo were consulted before the revocation check,
        // the bump would be silently bypassable for ten seconds.
        $jwt = $this->jwt();
        $watermark = 0;
        $manager = $this->manager($jwt, static function () use (&$watermark): array {
            return ['status' => 'active', 'tokensNotValidAfter' => $watermark];
        });
        $token = $jwt->createRefreshToken('user-1');

        $first = $manager->refreshToken($token);
        $this->assertSame($first, $manager->refreshToken($token));

        $watermark = time() + 3600;
        $manager->invalidateUserStatusCache('user-1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Token has been revoked');
        $manager->refreshToken($token);
    }

    public function test_an_expired_grace_entry_mints_a_fresh_pair(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt, $this->activeState());
        $token = $jwt->createRefreshToken('user-1');

        $first = $manager->refreshToken($token);

        $this->ageAllGraceEntries($manager);

        $second = $manager->refreshToken($token);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
    }

    public function test_the_memo_is_bounded_and_service_degrades_benignly_at_the_cap(): void
    {
        $jwt = $this->jwt();
        $manager = $this->manager($jwt, $this->activeState());

        // Seed the memo to its cap minus one with future-dated junk entries; a
        // real rotation must still succeed, and the memo must not exceed cap.
        $property = new ReflectionProperty(AuthManager::class, 'rotationGrace');
        $property->setValue($manager, array_fill_keys(
            array_map(static fn (int $i): string => str_repeat('f', 24) . $i, range(1, 63)),
            ['response' => [], 'expiresAt' => time() + 3600],
        ));

        $result = $manager->refreshToken($jwt->createRefreshToken('user-1'));
        $this->assertArrayHasKey('access_token', $result);

        $memo = $property->getValue($manager);
        $this->assertIsArray($memo);
        $this->assertLessThanOrEqual(64, count($memo), 'resident-worker memory is bounded');
    }

    private function ageAllGraceEntries(AuthManager $manager): void
    {
        $property = new ReflectionProperty(AuthManager::class, 'rotationGrace');
        $memo = $property->getValue($manager);
        $this->assertIsArray($memo);
        $this->assertNotEmpty($memo, 'the memo should hold the entry the rotation just stored');
        foreach ($memo as $jti => $entry) {
            $memo[$jti]['expiresAt'] = time() - 1;
        }
        $property->setValue($manager, $memo);
    }
}
