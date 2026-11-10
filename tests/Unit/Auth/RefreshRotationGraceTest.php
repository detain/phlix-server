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
 *  - cap eviction            ← an unbounded memo on a resident worker —
 *                              observable ONLY at the cap itself (the
 *                              cap-minus-one leg cannot tell, w29L1-R2);
 *  - window magnitude        ← a silent retune of the ten-second design
 *                              window (drift guard, constant pin).
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

    public function test_at_the_cap_the_memo_evicts_the_oldest_entry_before_storing(): void
    {
        // Cap-EVICATION limb, convicted by name (w29L1-R2, review
        // REVIEW-w29L1server.md Major): the cap-minus-one leg above cannot
        // distinguish capped from uncapped BY CONSTRUCTION — 63 future-dated
        // seeds + the one presented jti land on exactly 64 either way, so the
        // FIFO prune strip planted GREEN across the whole touched-suite set
        // (both the `while (false)` and the PHP_INT_MAX-bound variants). The
        // cap limb only acts when a store must make room — so seed the memo AT
        // the cap. Every assertion below is then decided by the prune loop
        // ALONE: the seeds are future-dated (the expiry sweep never fires),
        // the presented jti is fresh and never among them (no overwrite), and
        // every authorization gate passes — the store is the memo's only
        // other writer.
        $jwt = $this->jwt();
        $manager = $this->manager($jwt, $this->activeState());

        $property = new ReflectionProperty(AuthManager::class, 'rotationGrace');
        $seeds = array_map(static fn (int $i): string => str_repeat('f', 24) . $i, range(1, 64));
        $property->setValue($manager, array_fill_keys(
            $seeds,
            ['response' => [], 'expiresAt' => time() + 3600],
        ));

        $result = $manager->refreshToken($jwt->createRefreshToken('user-1'));
        $this->assertArrayHasKey(
            'access_token',
            $result,
            'benign degradation stands: the mint still succeeds at the cap'
        );

        $memo = $property->getValue($manager);
        $this->assertIsArray($memo);
        // Exactly one eviction made exactly one slot: an OVER-evicting cap
        // (or a mass purge) reddens here even though it too stays <= 64.
        $this->assertCount(64, $memo, 'a full store plus one new entry lands back on the cap, never above it');
        $this->assertArrayNotHasKey($seeds[0], $memo, 'the cap evicts the OLDEST entry (insertion-order FIFO)');
        foreach (array_slice($seeds, 1) as $survivor) {
            $this->assertArrayHasKey($survivor, $memo, 'only the oldest seed is evicted — the rest survive');
        }
    }

    public function test_the_grace_window_stays_ten_seconds_as_the_design_names_it(): void
    {
        // Drift guard, not an arm mutant (w29L1-R2, review
        // REVIEW-w29L1server.md minor on ROTATION_GRACE_SECONDS magnitude):
        // the behavioral legs either age entries explicitly or trip the
        // authorization guards before the memo window matters, so re-tuning
        // the constant measured GREEN across the whole suite under an
        // executed plant (10 -> 86400) — silently contradicting
        // COOKIE-DESIGN.md §5 ("within 10s skew") and the store docblock's
        // "the window is ten seconds". A deliberate retune must move THIS pin
        // together with the design file and the prose — never drift alone.
        $constant = (new \ReflectionClass(AuthManager::class))
            ->getReflectionConstant('ROTATION_GRACE_SECONDS');
        $this->assertNotFalse($constant, 'the grace window is a named constant on AuthManager');
        $this->assertSame(10, $constant->getValue(), 'ten seconds, per COOKIE-DESIGN.md §5');
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
