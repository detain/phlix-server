<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\AuthManager;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use PHPUnit\Framework\TestCase;

/**
 * CP-004 (COOKIE-DESIGN.md §2.2): the JSON logout route answers the pair
 * the shipped `@phlix/ui` has been posting into a 404 since v0.99.26.
 *
 * Two halves of the design law, both enforced here:
 *
 *  1. the JAR CLEAR is unconditional on every accepted arm — not gated by
 *     the master switch, not gated by request classification (§4.2
 *     re-introduction law: revocation hygiene outlives issuance policy);
 *  2. the WATERMARK BUMP rides the attached arm and only the attached arm
 *     (an anonymous call must never strike a user's token lineage).
 *
 * The response shape is design-mandated too: 204, ZERO body bytes, cookies
 * as additive Set-Cookie expiries. The server twin of the hub's ratified
 * logoutJson carries the SEMANTICS, not the hub's jti-store literals: on the
 * server the watermark bump inside AuthManager::logout() IS the revocation
 * (M-1: tokens_not_valid_after + endAllUserSessions).
 */
final class CookieLogoutJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PHLIX_COOKIE_INSECURE');
    }

    protected function tearDown(): void
    {
        putenv('PHLIX_COOKIE_INSECURE');
        parent::tearDown();
    }

    public function testAttachedLogoutBumpsWatermarkFirstAndClearsBothCookiesAt204(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager
            ->expects($this->once())
            ->method('logout')
            ->with('u-1', '', UserLoggedOut::REASON_EXPLICIT);
        $controller = new AuthController($authManager);

        $request = $this->logoutRequest(userId: 'u-1');

        $response = $controller->logoutJson($request, []);

        $this->assertSame(204, $response->statusCode);
        $this->assertSame('', $response->body, '204 carries zero body bytes');

        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    public function testAnonymousLogoutClearsTheJarButNeverTouchesTheWatermark(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('logout');
        $controller = new AuthController($authManager);

        $response = $controller->logoutJson($this->logoutRequest(userId: null), []);

        $this->assertSame(204, $response->statusCode);
        $lines = $this->setCookieLines($response);
        $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $this->findCookieLine($lines, AuthController::REFRESH_COOKIE);
    }

    public function testEmptyStringUserIdNeverStrikesTheWatermark(): void
    {
        // The attached arm is `is_string && !== ''`; an empty-string userId
        // (hostile or sloppy hydration) is the ANONYMOUS arm — a bump with an
        // empty subject would either be a no-op typo magnet or worse.
        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('logout');
        $controller = new AuthController($authManager);

        $response = $controller->logoutJson($this->logoutRequest(userId: ''), []);

        $this->assertSame(204, $response->statusCode);
    }

    public function testJarClearIsIndependentOfTheIssuanceSwitchAndRequestClass(): void
    {
        // A pure bearer/device call (no Origin, no cookies on the wire) still
        // receives the expiry pair — logoutJson never consults
        // CookiePolicy::classify for the clear (contrast login, which emits
        // cookies only for browser-class requests). The scheme override env
        // stays UNSET here, proving the clear also rides the strict default
        // path without needing it.
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('logout')->willReturnCallback(static function (): void {
        });
        $controller = new AuthController($authManager);

        $request = new Request();
        $request->path = '/api/v1/auth/logout';
        $request->method = 'POST';
        $request->userId = 'u-9';

        $response = $controller->logoutJson($request, []);

        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    public function testFormLogoutStillAnswers302WithTheSameJarClear(): void
    {
        // CP-004 must not move the form path's 302 contract (plain <a href>
        // logout); its jar clear stays, its redirect target stays.
        $authManager = $this->createMock(AuthManager::class);
        $authManager
            ->expects($this->once())
            ->method('logout')
            ->with('u-form', '', UserLoggedOut::REASON_EXPLICIT);
        $controller = new AuthController($authManager);

        $response = $controller->logout($this->logoutRequest(userId: 'u-form'), []);

        $this->assertSame(302, $response->statusCode);
        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('Max-Age=0', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    private function logoutRequest(?string $userId): Request
    {
        $request = new Request();
        $request->path = '/api/v1/auth/logout';
        $request->method = 'POST';
        $request->headers = [
            'Origin' => 'http://localhost:8096',
            'Host' => 'localhost:8096',
        ];
        $request->userId = $userId;

        return $request;
    }

    /**
     * @return list<string>
     */
    private function setCookieLines(\Phlix\Server\Http\Response $response): array
    {
        $header = $response->toWorkermanResponse()->getHeader('Set-Cookie');
        if ($header === null) {
            return [];
        }

        return is_array($header) ? $header : [$header];
    }

    private function findCookieLine(array $cookieLines, string $name): string
    {
        foreach ($cookieLines as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }

        $this->fail("Set-Cookie line for '{$name}' not found");
    }
}
