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
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
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
 * w28L1 round-2 (DD M1): the LDAP arm of login() carries the SAME dual-emit
 * law (the `ldap:` prefix changes the token MINTER, never the custody class),
 * and this suite now enforces it on every LDAP arm — the JSON dual-emit, the
 * device-class bearer veto, the form-era redirect ride and the §7 master
 * switch. Before this round the ldapLogin() emit had zero test visibility
 * (strip-the-leg mutation stayed green); these legs are the enforcement.
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
 *
 * CP-008 (§7 "full dual-emit/dual-clear matrix") extends the class with the
 * two axes the CP-003 era left as contains-string approximations: the
 * Set-Cookie LINES themselves are pinned byte-exact on every emit and clear
 * arm (attribute set AND order as the vendor serializer composes them —
 * Max-Age, no Expires limb, Path, Secure-by-scheme-law, HttpOnly, SameSite,
 * no Partitioned limb: the absence pins are measured facts about the shipped
 * encoder, not omissions), and the CP-005 grace window is pinned THROUGH the
 * controller — re-delivery inside the window re-emits the identical pair to
 * the byte, the bearer path inside the window stays cookie-free (the memo
 * itself never emits: emission remains classification-gated), and a
 * watermark strike mid-window answers the 401 envelope with ZERO Set-Cookie.
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

    public function testBrowserJsonLdapLoginDualEmitsBodyAndCookiePair(): void
    {
        $controller = new AuthController($this->ldapManager());

        $request = $this->browserRequest();
        $request->body = ['username' => 'ldap:alice', 'password' => 'hunter2hunter2'];

        $response = $controller->login($request, []);

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

    public function testDeviceMarkedLdapLoginStaysBearerOnlyWithByteIdenticalBody(): void
    {
        $controller = new AuthController($this->ldapManager());

        $request = $this->browserRequest();
        $request->headers['X-Phlix-Device-Type'] = 'roku';
        $request->body = ['username' => 'ldap:alice', 'password' => 'hunter2hunter2'];

        $response = $controller->login($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $response->body);
        $this->assertSame([], $this->setCookieLines($response));
    }

    public function testLdapFormLoginRedirectCarriesCookiePair(): void
    {
        $controller = new AuthController($this->ldapManager());

        $request = $this->loginFormRequest();
        $request->body = ['username' => 'ldap:alice', 'password' => 'hunter2hunter2'];

        $response = $controller->login($request, []);

        $this->assertSame(302, $response->statusCode);
        $lines = $this->setCookieLines($response);
        $this->assertStringContainsString('access-tok', $this->findCookieLine($lines, AuthController::SESSION_COOKIE));
        $this->assertStringContainsString('refresh-tok', $this->findCookieLine($lines, AuthController::REFRESH_COOKIE));
    }

    public function testMasterSwitchOffSuppressesCookiesOnLdapJsonAndFormArms(): void
    {
        $policy = new CookiePolicy($this->settingsStore([
            CookiePolicy::COOKIE_ENABLED_KEY => false,
        ]));
        $controller = new AuthController($this->ldapManager(), cookiePolicy: $policy);

        $jsonRequest = $this->browserRequest();
        $jsonRequest->body = ['username' => 'ldap:alice', 'password' => 'hunter2hunter2'];
        $json = $controller->login($jsonRequest, []);
        $this->assertSame(200, $json->statusCode);
        $this->assertSame(self::jsonBody(self::TOKENS), $json->body);
        $this->assertSame([], $this->setCookieLines($json));

        $formRequest = $this->loginFormRequest();
        $formRequest->body = ['username' => 'ldap:alice', 'password' => 'hunter2hunter2'];
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

    // ── CP-008: byte-exact emit/clear lines (§7 dual-emit/dual-clear matrix) ──

    public function testBrowserJsonLoginCookieLinesAreByteExactSecureArm(): void
    {
        $controller = new AuthController($this->loginManager());

        $response = $controller->login($this->browserRequest(), []);

        $this->assertSame(
            $this->expectedPairLines('access-tok', 'refresh-tok', 3600, 604800, secure: true),
            $this->setCookieLines($response),
            'the FULL line (attribute set AND order) is the wire contract',
        );
    }

    public function testInsecureOverrideLoginCookieLinesAreByteExactWithoutSecure(): void
    {
        putenv('PHLIX_COOKIE_INSECURE=1');
        try {
            $controller = new AuthController($this->loginManager());

            $response = $controller->login($this->browserRequest(), []);

            $this->assertSame(
                $this->expectedPairLines('access-tok', 'refresh-tok', 3600, 604800, secure: false),
                $this->setCookieLines($response),
            );
        } finally {
            putenv('PHLIX_COOKIE_INSECURE');
        }
    }

    public function testFormLoginRedirectCookieLinesAreByteExactAlongsideTheLocation(): void
    {
        $controller = new AuthController($this->loginManager());

        $response = $controller->login($this->loginFormRequest(), []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame(
            $this->expectedPairLines('access-tok', 'refresh-tok', 3600, 604800, secure: true),
            $this->setCookieLines($response),
        );
    }

    public function testRefreshRotationCookieLinesAreByteExact(): void
    {
        $controller = new AuthController($this->refreshManager());

        $request = $this->browserRequest('/api/v1/auth/refresh');
        $request->cookies = [AuthController::REFRESH_COOKIE => 'old-refresh'];

        $response = $controller->refresh($request, []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(
            $this->expectedPairLines('access-tok', 'refresh-tok', 3600, 604800, secure: true),
            $this->setCookieLines($response),
            'rotation re-emits the pair on the same byte-exact law as first issue',
        );
    }

    public function testJsonLogoutClearLinesAreByteExactForBothNamesAt204(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('logout')->willReturnCallback(static function (): void {
        });
        $controller = new AuthController($authManager);

        $request = $this->browserRequest('/api/v1/auth/logout');
        $request->userId = 'u-1';

        $response = $controller->logoutJson($request, []);

        $this->assertSame(204, $response->statusCode);
        $this->assertSame(
            [
                // MEASURED shipped shape: the clear retains Path/HttpOnly/SameSite
                // but NOT Secure (Response::clearCookie() passes the attribute
                // defaults). RFC 6265 expiry matches on name/domain/path, so the
                // Secure-flag gap cannot strand the authed cookie — the pin
                // records the fact so any re-shape becomes a disclosed change.
                'phlix_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
                'phlix_refresh=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
            ],
            $this->setCookieLines($response),
        );
    }

    public function testFormLogoutClearLinesAreByteExactAt302(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('logout')->willReturnCallback(static function (): void {
        });
        $controller = new AuthController($authManager);

        $request = $this->loginFormRequest();
        $request->userId = 'u-1';

        $response = $controller->logout($request, []);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame(
            [
                'phlix_session=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
                'phlix_refresh=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
            ],
            $this->setCookieLines($response),
            'both logout rails ship the identical dual-clear bytes',
        );
    }

    public function testEveryEmitAndClearArmCarriesNeitherExpiresNorPartitionedLimb(): void
    {
        // §7's attribute checklist names Expires and Partitioned: the shipped
        // vendor serializer has NO limb for either (Max-Age carries the whole
        // expiry law; Partitioned would need a vendor patch). Pinned as
        // measured absence so "checked" is honest rather than silent.
        $controller = new AuthController($this->loginManager());
        $arms = [
            'login' => $this->setCookieLines($controller->login($this->browserRequest(), [])),
        ];

        $refreshController = new AuthController($this->refreshManager());
        $refreshRequest = $this->browserRequest('/api/v1/auth/refresh');
        $refreshRequest->cookies = [AuthController::REFRESH_COOKIE => 'old-refresh'];
        $arms['refresh'] = $this->setCookieLines($refreshController->refresh($refreshRequest, []));

        $logoutManager = $this->createMock(AuthManager::class);
        $logoutManager->method('logout')->willReturnCallback(static function (): void {
        });
        $arms['logout'] = $this->setCookieLines(
            (new AuthController($logoutManager))->logoutJson($this->browserRequest('/api/v1/auth/logout'), [])
        );

        foreach ($arms as $site => $lines) {
            $this->assertNotSame([], $lines, "premise: the {$site} arm emits lines at all");
            foreach ($lines as $line) {
                $this->assertStringNotContainsString('Expires', $line, "{$site} arm");
                $this->assertStringNotContainsString('Partitioned', $line, "{$site} arm");
            }
        }
    }

    // ── CP-008: the CP-005 grace window THROUGH THE CONTROLLER (real AuthManager
    // over a scripted repo — same harness as RefreshRotationGraceTest, whose
    // manager-level pins stay authoritative for guard ORDER; these legs pin the
    // HTTP consequence: which bytes ride the re-delivery) ──────────────────────

    public function testGraceWindowReissueRedeliversTheIdenticalCookiePairToTheByte(): void
    {
        $jwt = $this->graceJwt();
        $controller = new AuthController($this->graceManager($jwt, static fn (): array => ['status' => 'active', 'tokensNotValidAfter' => 0]));
        $seedToken = $jwt->createRefreshToken('user-1');

        $first = $controller->refresh($this->graceRefreshRequest($seedToken), []);
        $second = $controller->refresh($this->graceRefreshRequest($seedToken), []);

        $this->assertSame(200, $first->statusCode);
        $this->assertSame(200, $second->statusCode);

        $firstLines = $this->setCookieLines($first);
        $secondLines = $this->setCookieLines($second);
        $this->assertNotSame([], $firstLines, 'premise: the browser-class rotation emits');

        $session = $this->findCookieLine($firstLines, AuthController::SESSION_COOKIE);
        $this->assertStringNotContainsString(
            $seedToken,
            $session,
            'premise: this really is a rotation — the jar carries the NEW refresh lineage'
        );
        $this->assertSame(
            $firstLines,
            $secondLines,
            'inside the grace window the re-delivery is the IDENTICAL pair — a second mint '
            . 'would move the refresh cookie value and redden this pin',
        );
    }

    public function testGraceWindowRedeliveryOnTheBearerPathEmitsZeroCookieLines(): void
    {
        // "the grace memo never emits": memoized re-delivery answers the body
        // token identically on the device-class path WITHOUT Set-Cookie — a
        // memo shortcut that attached cookies from the cached pair (bypassing
        // classification) reddens exactly this leg.
        $jwt = $this->graceJwt();
        $manager = $this->graceManager($jwt, static fn (): array => ['status' => 'active', 'tokensNotValidAfter' => 0]);
        $seedToken = $jwt->createRefreshToken('user-1');

        $browser = (new AuthController($manager))->refresh($this->graceRefreshRequest($seedToken), []);
        $this->assertNotSame([], $this->setCookieLines($browser), 'premise: the pair is memoized by a browser-class rotation');

        $deviceRequest = new Request();
        $deviceRequest->path = '/api/v1/auth/refresh';
        $deviceRequest->body = ['refresh_token' => $seedToken];

        $device = (new AuthController($manager))->refresh($deviceRequest, []);

        $this->assertSame(200, $device->statusCode);
        $this->assertSame($browser->body, $device->body, 'the grace pair re-delivers body-identically');
        $this->assertSame([], $this->setCookieLines($device), 'the bearer path stays cookie-free even inside the window');
    }

    public function testWatermarkStrikeInsideGraceWindowAnswers401WithZeroCookieLines(): void
    {
        // RefreshRotationGraceTest proves the guard-before-memo ORDER at the
        // manager; this leg proves the HTTP consequence: the struck client
        // gets the uniform 401 envelope and NO cookie bytes (not even the
        // memoized pair it had a second ago).
        $jwt = $this->graceJwt();
        $watermark = 0;
        $manager = $this->graceManager($jwt, static function () use (&$watermark): array {
            return ['status' => 'active', 'tokensNotValidAfter' => $watermark];
        });
        $seedToken = $jwt->createRefreshToken('user-1');

        $first = (new AuthController($manager))->refresh($this->graceRefreshRequest($seedToken), []);
        $this->assertSame(200, $first->statusCode);

        $watermark = time() + 10;
        // Production's revoke path (AuthManager :2037) pairs the watermark
        // write with this in-process cache bump; the 5s TTL would otherwise
        // serve the pre-strike state (M-1 semantics, not a defect).
        $manager->invalidateUserStatusCache('user-1');

        $struck = (new AuthController($manager))->refresh($this->graceRefreshRequest($seedToken), []);

        $this->assertSame(401, $struck->statusCode);
        $this->assertSame(self::jsonBody(['error' => 'Token has been revoked']), $struck->body);
        $this->assertSame([], $this->setCookieLines($struck));
    }

    /**
     * @return array{0: string, 1: string} the byte-exact pair, in emit order
     *         (session first — the order attachAuthCookies() attaches).
     */
    private function expectedPairLines(
        string $access,
        string $refresh,
        int $accessMaxAge,
        int $refreshMaxAge,
        bool $secure
    ): array {
        // Shape MEASURED against the vendor serializer (workerman Response::cookie)
        // at f82380d6: name=rawurlencode(value); Max-Age; Path; Secure; HttpOnly;
        // SameSite — in that composition order, Expires/Partitioned never.
        $tail = '; Max-Age=%d; Path=/' . ($secure ? '; Secure' : '') . '; HttpOnly; SameSite=Lax';

        return [
            AuthController::SESSION_COOKIE . '=' . rawurlencode($access) . sprintf($tail, $accessMaxAge),
            AuthController::REFRESH_COOKIE . '=' . rawurlencode($refresh) . sprintf($tail, $refreshMaxAge),
        ];
    }

    private function graceJwt(): JwtHandler
    {
        return new JwtHandler('cp008-grace-through-controller-12345', 'HS256', 3600, 604800);
    }

    /**
     * @param \Closure(): array{status: ?string, tokensNotValidAfter: int} $authState
     */
    private function graceManager(JwtHandler $jwt, \Closure $authState): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('getAuthState')->willReturnCallback($authState);
        $repo->method('findById')->willReturn([
            'id' => 'user-1',
            'username' => 'grace',
            'status' => 'active',
        ]);
        $repo->method('mustChangePassword')->willReturn(false);

        return new AuthManager(
            $repo,
            $jwt,
            $this->createMock(AuditLogger::class),
            $this->graceLogger(),
        );
    }

    private function graceLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_cp008_grace_' . uniqid('', true) . '.log',
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

    private function graceRefreshRequest(string $seedToken): Request
    {
        $request = $this->browserRequest('/api/v1/auth/refresh');
        $request->body = ['refresh_token' => $seedToken];

        return $request;
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

    /**
     * The LDAP route mints through loginWithProvider() (ProviderManager prefix
     * dispatch) instead of login() — the custody law is identical, only the
     * token source differs (design §2.2 "who minted" is irrelevant to "who
     * carries").
     */
    private function ldapManager(): AuthManager
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('loginWithProvider')->willReturn(self::TOKENS);
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
