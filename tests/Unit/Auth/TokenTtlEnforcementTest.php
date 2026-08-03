<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\TokenTtlPolicy;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The "half-effective setting" guard for `auth.access_ttl` / `auth.refresh_ttl`.
 *
 * ## Why this file exists separately
 *
 * The two lifetimes were FOUR independent literals, and the failure mode of
 * wiring only some of them is not a missing feature — it is an incoherent
 * session. Shorten the access TTL while `AuthManager::buildAuthResponse()`
 * still hardcodes `expires_in => 3600` and the client is told it has an hour
 * on a token that dies in fifteen minutes; leave
 * `AuthController::attachAuthCookies()`'s `7 * 24 * 3600` in place while the
 * refresh TTL is raised and the browser drops a cookie carrying a still-valid
 * refresh token. Both bugs look like "random logouts", and neither would be
 * caught by a unit test of the policy object.
 *
 * {@see TokenTtlPolicyTest} proves the policy behaves. This file proves the
 * four historical literal sites are gone, by driving ONE override
 * (access 900 / refresh 172800) through real production objects and asserting
 * the number that comes out the far end.
 *
 * The chosen values DISCRIMINATE: 900 and 172800 are equal to none of the
 * replaced literals (3600, 604800, 7 * 24 * 3600), so a site left on its
 * literal produces a visibly different number rather than coincidentally
 * agreeing. That property is the whole point — a mutation test whose input
 * cannot distinguish the branches proves nothing.
 *
 * Mutation-verified: restoring the literal at any one of the four sites turns
 * exactly that site's test red while the others stay green.
 *
 */
final class TokenTtlEnforcementTest extends TestCase
{
    /** Differs from the replaced literal 3600. */
    private const OVERRIDE_ACCESS_TTL = 900;

    /** Differs from the replaced literals 604800 and 7 * 24 * 3600. */
    private const OVERRIDE_REFRESH_TTL = 172800;

    private const USER_ID = 'user-ttl-1';

    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_ttl_' . uniqid('', true) . '.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => ['context' => true, 'request_id' => false, 'user_id' => false],
        ]);
    }

    /**
     * A real JwtHandler wired exactly as `AuthServicesProvider` wires it:
     * ctor lifetimes at the shipped defaults, with a configured policy that
     * must override them.
     */
    private function jwt(): JwtHandler
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')
            ->willReturnCallback(static fn (string $key): ?int => match ($key) {
                TokenTtlPolicy::ACCESS_TTL_KEY => self::OVERRIDE_ACCESS_TTL,
                TokenTtlPolicy::REFRESH_TTL_KEY => self::OVERRIDE_REFRESH_TTL,
                default => null,
            });

        return new JwtHandler(
            'test-secret-key-1234567890-abcdef',
            'HS256',
            TokenTtlPolicy::DEFAULT_ACCESS_TTL,
            TokenTtlPolicy::DEFAULT_REFRESH_TTL,
            new TokenTtlPolicy($settings)
        );
    }

    private function userRepository(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getStatus')->willReturn('active');
        $repo->method('mustChangePassword')->willReturn(false);
        $repo->method('findById')->willReturn(['id' => self::USER_ID, 'username' => 'ttl']);

        return $repo;
    }

    private function authManager(JwtHandler $jwt): AuthManager
    {
        return new AuthManager(
            $this->userRepository(),
            $jwt,
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    /**
     * Decode a JWT payload without validating it — we only want the `exp`.
     *
     * @return array<string, mixed>
     */
    private function payload(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'expected a three-segment JWT');
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // ─────────────────────────────────────────────────────────────────
    // site 1 — the `exp` claim of the access token
    // ─────────────────────────────────────────────────────────────────

    public function test_access_token_exp_honours_the_override(): void
    {
        $jwt = $this->jwt();

        $before = time();
        $payload = $this->payload($jwt->createAccessToken(self::USER_ID));
        $after = time();

        $this->assertIsInt($payload['exp'] ?? null);
        /** @var int $exp */
        $exp = $payload['exp'];

        // Bracketed rather than exact, so a second ticking over mid-test is
        // not a flake. The bracket is far narrower than the gap to 3600.
        $this->assertGreaterThanOrEqual($before + self::OVERRIDE_ACCESS_TTL, $exp);
        $this->assertLessThanOrEqual($after + self::OVERRIDE_ACCESS_TTL, $exp);
    }

    // ─────────────────────────────────────────────────────────────────
    // site 2 — the `exp` claim of the refresh token
    // ─────────────────────────────────────────────────────────────────

    public function test_refresh_token_exp_honours_the_override(): void
    {
        $jwt = $this->jwt();

        $before = time();
        $payload = $this->payload($jwt->createRefreshToken(self::USER_ID));
        $after = time();

        $this->assertIsInt($payload['exp'] ?? null);
        /** @var int $exp */
        $exp = $payload['exp'];

        $this->assertGreaterThanOrEqual($before + self::OVERRIDE_REFRESH_TTL, $exp);
        $this->assertLessThanOrEqual($after + self::OVERRIDE_REFRESH_TTL, $exp);
    }

    // ─────────────────────────────────────────────────────────────────
    // site 3 — `expires_in` in the auth response
    // ─────────────────────────────────────────────────────────────────

    public function test_auth_response_expires_in_matches_the_token_it_ships_with(): void
    {
        $manager = $this->authManager($this->jwt());

        $response = $manager->buildAuthResponse(self::USER_ID);

        $this->assertSame(
            self::OVERRIDE_ACCESS_TTL,
            $response['expires_in'],
            'expires_in must come from the handler that minted the token, not a literal'
        );
    }

    public function test_expires_in_agrees_with_the_exp_baked_into_the_access_token(): void
    {
        // The strongest form of site 3: the number the client is TOLD and the
        // number the client can READ out of the token must be the same. This
        // is the assertion the old hardcoded 3600 would fail.
        $manager = $this->authManager($this->jwt());

        $response = $manager->buildAuthResponse(self::USER_ID);
        $this->assertIsString($response['access_token']);
        $payload = $this->payload($response['access_token']);

        $this->assertIsInt($payload['exp'] ?? null);
        $this->assertIsInt($payload['iat'] ?? null);
        /** @var int $exp */
        $exp = $payload['exp'];
        /** @var int $iat */
        $iat = $payload['iat'];

        $this->assertSame($exp - $iat, $response['expires_in']);
    }

    // ─────────────────────────────────────────────────────────────────
    // site 4 — the two auth cookies
    // ─────────────────────────────────────────────────────────────────

    /**
     * Drive the real `AuthController::refresh()` path, which is one of the two
     * production callers of `attachAuthCookies()`, and read the queued cookies
     * back off the Response.
     *
     * @return array<string, array<string, mixed>> Cookie name => cookie spec.
     */
    private function refreshCookies(): array
    {
        $jwt = $this->jwt();
        $manager = $this->authManager($jwt);
        $controller = new AuthController($manager);

        $request = new Request();
        $request->method = 'POST';
        $request->path = '/auth/refresh';
        $request->body = ['refresh_token' => $jwt->createRefreshToken(self::USER_ID)];

        $response = $controller->refresh($request, []);
        $this->assertSame(200, $response->statusCode, 'refresh must succeed for the cookies to be queued');

        $byName = [];
        foreach ($response->cookies as $cookie) {
            $this->assertIsString($cookie['name']);
            $byName[$cookie['name']] = $cookie;
        }

        return $byName;
    }

    public function test_session_cookie_max_age_honours_the_access_ttl_override(): void
    {
        $cookies = $this->refreshCookies();

        $this->assertArrayHasKey(AuthController::SESSION_COOKIE, $cookies);
        $this->assertSame(
            self::OVERRIDE_ACCESS_TTL,
            $cookies[AuthController::SESSION_COOKIE]['maxAge'],
            'the session cookie must expire with the access token it carries'
        );
    }

    public function test_refresh_cookie_max_age_honours_the_refresh_ttl_override(): void
    {
        $cookies = $this->refreshCookies();

        $this->assertArrayHasKey(AuthController::REFRESH_COOKIE, $cookies);
        $this->assertSame(
            self::OVERRIDE_REFRESH_TTL,
            $cookies[AuthController::REFRESH_COOKIE]['maxAge'],
            'the refresh cookie must expire with the refresh token it carries, '
            . 'not on a hardcoded 7-day schedule'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // the no-policy path still yields the historical behaviour
    // ─────────────────────────────────────────────────────────────────

    public function test_a_handler_without_a_policy_keeps_its_constructor_lifetimes(): void
    {
        // Direct-construction call sites (tests, CLI, the degraded
        // no-container fallback) must be unaffected by this change.
        $jwt = new JwtHandler('test-secret-key-1234567890-abcdef', 'HS256', 3600, 604800);

        $this->assertSame(3600, $jwt->accessTtl());
        $this->assertSame(604800, $jwt->refreshTtl());
    }
}
