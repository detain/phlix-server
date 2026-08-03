<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\RateLimit\DbRateLimiter;
use Phlix\Common\RateLimit\RateLimiter;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitProfiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-4.15(d): {@see AuthServicesProvider} registers one limiter per surface
 * under its {@see RateLimitProfiles} container id — the four brute-force
 * surfaces as the shared DB-backed {@see DbRateLimiter}, and jwks / ws_connect
 * as the worker-local in-memory {@see RateLimiter} — with config-driven
 * `{max, window}` and DISTINCT instances. `login` is NOT registered here.
 *
 */
final class AuthServicesProviderRateLimitTest extends TestCase
{
    /**
     * The four brute-force surfaces resolve to the shared, DB-backed limiter so
     * enforcement is true-global across all HTTP workers.
     */
    public function testDbBackedSurfacesResolveToDbRateLimiter(): void
    {
        $container = $this->buildContainer([]);

        $expected = [
            RateLimitProfiles::REGISTER        => [5, 600],
            RateLimitProfiles::REFRESH         => [30, 60],
            RateLimitProfiles::WEBAUTHN_START  => [10, 60],
            RateLimitProfiles::WEBAUTHN_FINISH => [10, 60],
        ];

        foreach ($expected as $id => [$max, $window]) {
            $limiter = $container->get($id);
            self::assertInstanceOf(DbRateLimiter::class, $limiter, $id);
            $this->assertMaxAndWindow($limiter, $max, $window, $id);
        }
    }

    /**
     * jwks and ws_connect stay worker-local in-memory with their documented
     * defaults.
     */
    public function testInMemorySurfacesResolveToRateLimiter(): void
    {
        $container = $this->buildContainer([]);

        $expected = [
            RateLimitProfiles::JWKS       => [120, 60],
            RateLimitProfiles::WS_CONNECT => [30, 60],
        ];

        foreach ($expected as $id => [$max, $window]) {
            $limiter = $container->get($id);
            self::assertInstanceOf(RateLimiter::class, $limiter, $id);
            $this->assertMaxAndWindow($limiter, $max, $window, $id);
        }
    }

    /**
     * No two surfaces share the same instance (a shared window object would
     * cross-contaminate unrelated traffic).
     */
    public function testSurfacesAreDistinctInstances(): void
    {
        $container = $this->buildContainer([]);

        $objectIds = [];
        foreach (array_keys(RateLimitProfiles::defaults()) as $id) {
            $instance = $container->get($id);
            self::assertIsObject($instance, $id);
            $objectIds[] = spl_object_id($instance);
        }

        self::assertSameSize($objectIds, array_unique($objectIds));
    }

    /**
     * A config override of a DB-backed surface's {max, window} flows into the
     * resolved DbRateLimiter; untouched surfaces keep their defaults.
     */
    public function testConfigOverrideForDbSurfaceIsApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'register' => ['max' => 3, 'window' => 1200],
            ],
        ]);

        $register = $container->get(RateLimitProfiles::REGISTER);
        self::assertInstanceOf(DbRateLimiter::class, $register);
        $this->assertMaxAndWindow($register, 3, 1200, RateLimitProfiles::REGISTER);

        // Untouched DB-backed surface keeps its default.
        $refresh = $container->get(RateLimitProfiles::REFRESH);
        self::assertInstanceOf(DbRateLimiter::class, $refresh);
        $this->assertMaxAndWindow($refresh, 30, 60, RateLimitProfiles::REFRESH);
    }

    /**
     * A config override of an in-memory surface's {max, window} flows into the
     * resolved RateLimiter.
     */
    public function testConfigOverrideForInMemorySurfaceIsApplied(): void
    {
        $container = $this->buildContainer([
            'rate_limit' => [
                'jwks' => ['max' => 7, 'window' => 42],
            ],
        ]);

        $jwks = $container->get(RateLimitProfiles::JWKS);
        self::assertInstanceOf(RateLimiter::class, $jwks);
        $this->assertMaxAndWindow($jwks, 7, 42, RateLimitProfiles::JWKS);

        // Untouched in-memory surface keeps its default.
        $ws = $container->get(RateLimitProfiles::WS_CONNECT);
        self::assertInstanceOf(RateLimiter::class, $ws);
        $this->assertMaxAndWindow($ws, 30, 60, RateLimitProfiles::WS_CONNECT);
    }

    /**
     * Every profile id resolves to a RateLimiterInterface, and NO `login`
     * profile is registered by this provider.
     */
    public function testAllProfilesResolveAndNoLoginProfile(): void
    {
        $container = $this->buildContainer([]);

        foreach (array_keys(RateLimitProfiles::defaults()) as $id) {
            self::assertInstanceOf(RateLimiterInterface::class, $container->get($id), $id);
        }

        self::assertFalse($container->has('rate_limiter.login'));
    }

    /**
     * Assert a container-built limiter has the given max and window.
     *
     * `max` is read exactly from `peek()->limit`; `window` is inferred from the
     * `resetAt` of a fresh `hit()` against a captured timestamp (range-checked
     * to absorb a second-boundary roll with the default time() clock). Works for
     * both the in-memory {@see RateLimiter} and the DB-backed {@see DbRateLimiter}
     * (the mock Connection returns no row, so a fresh hit reports
     * resetAt = now + window).
     */
    private function assertMaxAndWindow(
        RateLimiterInterface $limiter,
        int $max,
        int $window,
        string $label,
    ): void {
        self::assertSame($max, $limiter->peek('probe')->limit, $label . ' max');

        $before = time();
        $resetAt = $limiter->hit('window-probe')->resetAt;
        $after = time();

        self::assertGreaterThanOrEqual($before + $window, $resetAt, $label . ' window lower');
        self::assertLessThanOrEqual($after + $window, $resetAt, $label . ' window upper');
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new AuthServicesProvider())->register($builder, $appConfig);

        // The DB-backed profiles autowire a Connection; the mock returns no rows
        // so peek() is empty and a fresh hit() reports resetAt = now + window
        // (enough to assert the thresholds without a real DB).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $builder->addDefinitions([Connection::class => $db]);

        return $builder->build();
    }
}
