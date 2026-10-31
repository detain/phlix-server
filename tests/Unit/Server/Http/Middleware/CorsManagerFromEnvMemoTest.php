<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * minors M-SERVER-40 (W-min109): CorsManager::fromEnv() ran (at least) once
 * per request. It is now memoized PER WORKER keyed by the RAW env value —
 * identity reuse in the steady state, a cold parse (never a stale serve) when
 * the value changes, and an explicit reset seam.
 */
final class CorsManagerFromEnvMemoTest extends TestCase
{
    private string $original = '';

    protected function setUp(): void
    {
        CorsManager::resetFromEnvMemoForTesting();
        $got = getenv(CorsManager::ENV_ALLOWED_ORIGINS);
        $this->original = is_string($got) ? $got : '';
    }

    protected function tearDown(): void
    {
        CorsManager::resetFromEnvMemoForTesting();
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=' . $this->original);
    }

    public function testTheSameEnvValueReusesOneImmutableManager(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://memo-a.test');
        $first = CorsManager::fromEnv();
        $second = CorsManager::fromEnv();

        // Parent shape builds a fresh object every call — this identity pin is
        // the discriminating red for the memoization.
        self::assertSame($first, $second);
    }

    public function testAChangedEnvValueColdParsesInsteadOfServingStale(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://memo-a.test');
        $a = CorsManager::fromEnv();

        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://memo-b.test');
        $b = CorsManager::fromEnv();

        self::assertNotSame($a, $b);

        $preflight = static function (CorsManager $cors, string $origin): ?int {
            $request = new Request();
            $request->method = 'OPTIONS';
            $request->headers['Origin'] = $origin;
            $request->headers['Access-Control-Request-Method'] = 'POST';
            $response = $cors->preflightResponse($request);

            return $response?->statusCode;
        };

        self::assertSame(204, $preflight($b, 'https://memo-b.test'));
        self::assertNull($preflight($b, 'https://memo-a.test'));
        self::assertSame(204, $preflight($a, 'https://memo-a.test'));
    }

    public function testResetSeamDropsEveryMemoizedValue(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://memo-c.test');
        $first = CorsManager::fromEnv();
        self::assertSame($first, CorsManager::fromEnv());

        CorsManager::resetFromEnvMemoForTesting();

        self::assertNotSame($first, CorsManager::fromEnv());
    }
}
