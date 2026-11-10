<?php

/**
 * Phlix media server component: Auth tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\CookiePolicy;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * CP-003 (COOKIE-DESIGN.md §2.2, design §7 matrix): the JSON login/register
 * surfaces DUAL-EMIT for browser-class requests — byte-ident body envelope
 * AND the HttpOnly cookie pair — while device-class requests stay bearer-only
 * with NO Set-Cookie ever, and every browser-class emission is gated by the
 * §2.4 scheme law (https-or-localhost, or the explicit insecure override).
 *
 * Also carries the refresh-cure leg: /auth/refresh used to attach cookies
 * UNCONDITIONALLY (cookie-program scaffolding bug, BANK.md) — device-class
 * body-token refreshes must not receive cookies under the same
 * classifyRequest policy the JSON arms use.
 *
 * Bodies are pinned byte-for-byte (same encode flags as Response::json())
 * between the browser arm and the device arm of the same AuthManager
 * payload — the central non-regression law: legacy bearer-only clients see
 * identical JSON no matter what the cookie ride adds.
 */
class CookieDualEmitTest extends TestCase
{
    /** @var array<string, mixed> The AuthManager success payload under test. */
    private const TOKENS = [
        'access_token' => 'access-tok',
        'refresh_token' => 'refresh-tok',
        'expires_in' => 3600,
        'user' => ['id' => 'u-1', 'username' => 'alice'],
    ];

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

    public function testBrowserJsonLoginDualEmitsBodyAndCookiePair(): void
    {
        $controller = new AuthController($this->loginManager());

        $response = $controller->login($this->browserRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);

        $lines = $this->setCookieLines($response);
        $session = $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $refresh = $this->findCookieLine($lines, AuthController::REFRESH_COOKIE);

        $this->assertStringContainsString('access-tok', $session);
        $this->assertStringContainsString('refresh-tok', $refresh);
        $this->assertStringContainsString('Max-Age=3600', $session);
        $this->assertStringContainsString('Max-Age=604800', $refresh);
        foreach ([$session, $refresh] as $line) {
            $this->assertStringContainsString('Secure', $line);
            $this->assertStringContainsString('HttpOnly', $line);
            $this->assertStringContainsString('SameSite=Lax', $line);
        }
    }

    public function testBrowserJsonRegisterDualEmitsAt201WithIdenticalBody(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willReturn(self::TOKENS);
        $authManager->method('accessTtl')->willReturn(3600);
        $authManager->method('refreshTtl')->willReturn(604800);

        $controller = new AuthController($authManager);

        $response = $controller->register($this->browserRegisterRequest(), []);

        $this->assertSame(201, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);

        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('access-tok', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('refresh-tok', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    public function testRegisterPendingArmNeverEmitsCookiesForBrowsers(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('register')->willReturn([
            'status' => 'pending',
            'message' => 'awaiting approval',
        ]);

        $controller = new AuthController($authManager);

        $response = $controller->register($this->browserRegisterRequest(), []);

        $this->assertSame(202, $response->statusCode);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testDeviceMarkedLoginStaysBearerOnlyWithByteIdenticalBody(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['X-Phlix-Device-Type'] = 'roku';

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testForeignOriginLoginIsDeviceClassBearerOnly(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'http://evil.example';

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testWebviewOriginLoginIsDeviceClassBearerOnly(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'capacitor://localhost';

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testSignallessJsonLoginKeepsLegacyBearerOnlyPosture(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = new Request();
        $request->path = '/api/v1/auth/login';
        $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testHttpLanBrowserLoginIssuesNoCookiesButIdenticalBody(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'http://box.lan:8096';
        $request->headers['Host'] = 'box.lan:8096';

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testHttpLanBrowserLoginWithInsecureOverrideIssuesUnsecureCookies(): void
    {
        putenv('PHLIX_COOKIE_INSECURE=1');

        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'http://box.lan:8096';
        $request->headers['Host'] = 'box.lan:8096';

        $response = $controller->login($request, []);

        $lines = $this->setCookieLines($response);
        $session = $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $this->assertStringContainsString('access-tok', $session);
        $this->assertStringNotContainsString('Secure', $session);
        $this->assertStringContainsString('HttpOnly', $session);
        $this->assertStringContainsString('SameSite=Lax', $session);
    }

    public function testForwardedHttpsBrowserLoginIssuesSecureCookies(): void
    {
        $controller = new AuthController($this->loginManager());

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'https://box.example';
        $request->headers['Host'] = 'box.example';
        $request->headers['X-Forwarded-Proto'] = 'https, http';

        $response = $controller->login($request, []);

        $lines = $this->setCookieLines($response);
        $session = $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $this->assertStringContainsString('Secure', $session);
    }

    public function testDeviceBodyTokenRefreshReceivesNoCookies(): void
    {
        $authManager = $this->refreshManager();
        $controller = new AuthController($authManager);

        $request = new Request();
        $request->path = '/api/v1/auth/refresh';
        $request->body = ['refresh_token' => 'old-refresh'];

        $response = $controller->refresh($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testBrowserCookieRefreshStillRotatesThePair(): void
    {
        $authManager = $this->refreshManager();
        $controller = new AuthController($authManager);

        $request = $this->browserRequest('/api/v1/auth/refresh');
        $request->cookies = [AuthController::REFRESH_COOKIE => 'old-refresh'];

        $response = $controller->refresh($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);

        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('access-tok', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('refresh-tok', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    public function testMasterSwitchOffSuppressesCookiesOnEveryPath(): void
    {
        $policy = new CookiePolicy($this->settingsStore([
            CookiePolicy::COOKIE_ENABLED_KEY => false,
        ]));
        $controller = new AuthController($this->loginManager(), cookiePolicy: $policy);

        $json = $controller->login($this->browserRequest(), []);
        $this->assertSame(200, $json->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $json->body);
        $this->assertSame([], $this->setCookieLines($json));

        $formRequest = $this->loginFormRequest();
        $redirect = $controller->login($formRequest, []);
        $this->assertSame(302, $redirect->statusCode);
        $this->assertSame([], $this->setCookieLines($redirect));
    }

    public function testStoredInsecureSettingOptsIntoUnsecureCookies(): void
    {
        $policy = new CookiePolicy($this->settingsStore([
            CookiePolicy::COOKIE_INSECURE_KEY => true,
        ]));
        $controller = new AuthController($this->loginManager(), cookiePolicy: $policy);

        $request = $this->browserRequest();
        $request->headers['Origin'] = 'http://box.lan:8096';
        $request->headers['Host'] = 'box.lan:8096';

        $response = $controller->login($request, []);

        $lines = $this->setCookieLines($response);
        $session = $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $this->assertStringContainsString('access-tok', $session);
        $this->assertStringNotContainsString('Secure', $session);
    }

    public function testThrowingSettingsStoreFailsOpenToShippedPosture(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $policy = new CookiePolicy($settings);
        $controller = new AuthController($this->loginManager(), cookiePolicy: $policy);

        $response = $controller->login($this->browserRequest(), []);

        $lines = $this->setCookieLines($response);
        $session = $this->findCookieLine($lines, AuthController::SESSION_COOKIE);
        $this->assertStringContainsString('Secure', $session);
    }

    /** @param array<string, mixed> $payload */
    private static function jsonBody(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function loginManager(): AuthManager
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')->willReturn(self::TOKENS);
        $authManager->method('register')->willReturn(self::TOKENS);
        $authManager->method('accessTtl')->willReturn(3600);
        $authManager->method('refreshTtl')->willReturn(604800);

        return $authManager;
    }

    private function refreshManager(): AuthManager
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('refreshToken')->willReturn(self::TOKENS);
        $authManager->method('accessTtl')->willReturn(3600);
        $authManager->method('refreshTtl')->willReturn(604800);

        return $authManager;
    }

    /**
     * A same-origin browser SPA JSON login on a localhost dev box: browser
     * class AND scheme-eligible (§2.4) — the dual-emit target posture.
     */
    private function browserRequest(string $path = '/api/v1/auth/login'): Request
    {
        $request = new Request();
        $request->path = $path;
        $request->headers = [
            'Origin' => 'http://localhost:8096',
            'Host' => 'localhost:8096',
            'Content-Type' => 'application/json',
        ];
        $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];

        return $request;
    }

    /** Browser-class JSON register (the register arm needs an email field). */
    private function browserRegisterRequest(): Request
    {
        $request = $this->browserRequest('/api/v1/auth/register');
        $request->body = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'hunter2hunter2',
        ];

        return $request;
    }

    /** The form-era POST at the /auth/ alias (browser-class via path signal). */
    private function loginFormRequest(): Request
    {
        $request = new Request();
        $request->path = '/auth/login';
        $request->headers = [
            'Host' => 'localhost:8096',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $request->body = ['username' => 'alice', 'password' => 'hunter2hunter2'];

        return $request;
    }

    /**
     * @param array<string, mixed> $values key => stored effective value
     */
    private function settingsStore(array $values): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? null
        );

        return $settings;
    }

    /**
     * @return array<int, string>
     */
    private function setCookieLines(\Phlix\Server\Http\Response $response): array
    {
        $header = $response->toWorkermanResponse()->getHeader('Set-Cookie');
        if ($header === null) {
            return [];
        }

        return is_array($header) ? $header : [$header];
    }

    /** @param array<int, string> $cookieLines */
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
