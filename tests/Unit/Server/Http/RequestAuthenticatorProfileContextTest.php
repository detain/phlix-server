<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * S80 — {@see RequestAuthenticator} is the SINGLE place the session's profile is
 * published, and this file is what stops it silently going away.
 *
 * ## Measured: without this file, deleting the publication is invisible
 *
 * Replacing the whole publication block in `authenticate()` with
 * `$request->profileId = null;` left `tests/Unit/Server` + `tests/Unit/Auth` at
 * **2287 tests, 13098 assertions, GREEN**. Every other S80 test either builds its
 * own `AuthManager` (the integration suite) or seeds `RequestContext` by hand (the
 * middleware precedence suite), so not one of them crosses this seam — and in
 * production that mutation makes the whole feature inert: no request would ever
 * carry a profile, and every profile-scoped read and write would silently fall
 * back to the account-wide `user_profiles.is_active` flag.
 *
 * The publication lives here rather than in a middleware because `AuthMiddleware`
 * is instantiated inline as `new AuthMiddleware()` at nineteen sites in
 * `Application.php`, and several surfaces — the pre-router fast paths, the
 * direct-play `/media/{id}/stream` route — never reach a middleware group at all.
 * Both entry points (`HttpHandler` and `public/index.php`) call this one method.
 */
final class RequestAuthenticatorProfileContextTest extends TestCase
{
    private const USER = 'user-1';
    private const PROFILE = 'profile-session';

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
    }

    protected function tearDown(): void
    {
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        parent::tearDown();
    }

    /**
     * The verified profile reaches BOTH channels the rest of the stack reads:
     * `Request::$profileId` (controllers, `WebPortalRouter`) and
     * `RequestContext` (the middlewares and `ItemRepository`'s tag filter).
     *
     * Both are asserted because they have different consumers — publishing to only
     * one would leave half the stack on the account-wide flag.
     */
    public function testTheVerifiedProfileIsPublishedToTheRequestAndTheContext(): void
    {
        $authenticator = new RequestAuthenticator($this->authManagerReturning([
            'user_id' => self::USER,
            'expires_at' => time() + 3600,
            'profile_id' => self::PROFILE,
        ]));

        $request = $this->bearerRequest();

        $this->assertTrue($authenticator->authenticate($request));
        $this->assertSame(self::USER, $request->userId);
        $this->assertSame(
            self::PROFILE,
            $request->profileId,
            'Request::$profileId is what MediaUserDataController and WebPortalRouter read'
        );
        $this->assertSame(
            self::PROFILE,
            RequestContext::getProfileId(),
            'RequestContext is what AccessScheduleMiddleware, StreamLimitMiddleware and '
            . "ItemRepository's profile_tags filter read"
        );
    }

    /**
     * The succeeding control's counterpart: a validation result with NO profile —
     * a token minted before S80 — authenticates normally and leaves the profile
     * unset, rather than inventing one or failing the request.
     *
     * This sits beside the test above so "the profile was null" cannot pass as
     * "the publication works": there, the identical code path produced a value.
     */
    public function testATokenWithNoProfileStillAuthenticatesAndLeavesTheProfileUnset(): void
    {
        $authenticator = new RequestAuthenticator($this->authManagerReturning([
            'user_id' => self::USER,
            'expires_at' => time() + 3600,
        ]));

        $request = $this->bearerRequest();

        $this->assertTrue($authenticator->authenticate($request), 'a pre-S80 token must still authenticate');
        $this->assertSame(self::USER, $request->userId);
        $this->assertNull($request->profileId);
        $this->assertNull(RequestContext::getProfileId());
    }

    /**
     * An empty-string profile is treated as absent, not published as `''`.
     *
     * `''` would flow into `user_item_data.profile_id`, which is NOT NULL with a
     * foreign key to `user_profiles`, and every write would die on the constraint.
     */
    public function testAnEmptyProfileIsNormalisedToNull(): void
    {
        $authenticator = new RequestAuthenticator($this->authManagerReturning([
            'user_id' => self::USER,
            'expires_at' => time() + 3600,
            'profile_id' => '',
        ]));

        $request = $this->bearerRequest();

        $this->assertTrue($authenticator->authenticate($request));
        $this->assertNull($request->profileId);
        $this->assertNull(RequestContext::getProfileId());
    }

    /**
     * A failed authentication publishes nothing at all — no user, no profile.
     */
    public function testAFailedAuthenticationPublishesNoProfile(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn(null);

        $request = $this->bearerRequest();

        $this->assertFalse((new RequestAuthenticator($authManager))->authenticate($request));
        $this->assertNull($request->userId);
        $this->assertNull($request->profileId);
        $this->assertNull(RequestContext::getProfileId());
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $result What `validateAccessToken()` returns.
     */
    private function authManagerReturning(array $result): AuthManager
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn($result);

        return $authManager;
    }

    private function bearerRequest(): Request
    {
        $request = new Request();
        $request->headers['authorization'] = 'Bearer some-token';

        return $request;
    }
}
