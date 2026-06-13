<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmOAuthStateStore;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmSessionRepository;
use Phlix\Server\Http\Controllers\Admin\LastfmController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see LastfmController}.
 *
 * Focuses on the "Connect Last.fm" landing page ({@see LastfmController::index()}),
 * which renders a Smarty template. The Connect button issues a full-page
 * redirect to `/admin/lastfm`, so a broken render is a hard 500 for the user.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\LastfmController
 */
final class LastfmControllerTest extends TestCase
{
    /**
     * Regression: `GET /admin/lastfm` returned HTTP 500 because index() built
     * its Smarty template directory with `dirname(__DIR__, 4)` — one level too
     * shallow for this controller's location (src/Server/Http/Controllers/Admin/),
     * resolving to the non-existent `src/public/templates` and throwing
     * "Unable to load template 'file:admin/lastfm.tpl'". The template base is
     * `dirname(__DIR__, 5)` (the project root). This test renders the page and
     * asserts it succeeds — it errored with a SmartyException before the fix.
     */
    public function testIndexRendersConnectPageWithoutThrowing(): void
    {
        if (!class_exists(\Smarty::class)) {
            self::markTestSkipped('Smarty runtime not available.');
        }

        $config = LastfmConfig::fromArray([
            'api_key'       => 'test-api-key',
            'shared_secret' => 'test-shared-secret',
            'enabled'       => true,
            'callback_url'  => 'https://example.test/admin/lastfm/callback',
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        // No authenticated user → index() must not hit the session store.
        $sessions->expects(self::never())->method('findByUserId');
        $api = $this->createMock(LastfmApi::class);

        $controller = new LastfmController($config, $sessions, $api);

        $request = new Request();
        $request->userId = '';

        $response = $controller->index($request, []);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Last.fm Scrobbling', $response->body);
    }

    /**
     * `GET /api/v1/oauth/lastfm` (apiAuthorize) when Last.fm is configured must
     * 302-redirect straight to the Last.fm auth URL, carrying the api_key and a
     * `cb` that points at the NEW `/api/v1/oauth/lastfm/callback` derived from
     * the request host (NOT the legacy operator callback_url).
     */
    public function testApiAuthorizeRedirectsToLastfmWhenConfigured(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'test-api-key',
            'shared_secret' => 'test-shared-secret',
            'enabled'       => true,
            // Legacy operator callback — must NOT be used by the new flow.
            'callback_url'  => 'https://example.test/admin/lastfm/callback',
        ]);
        $store = new InMemoryLastfmOAuthStateStore();
        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $this->createMock(LastfmApi::class),
            $store,
        );

        $request = new Request();
        $request->userId = 'user-1';
        $request->headers = ['HOST' => 'phlix.test', 'X-FORWARDED-PROTO' => 'https'];

        $response = $controller->apiAuthorize($request, []);

        self::assertSame(302, $response->statusCode);
        $location = $response->headers['Location'] ?? '';
        self::assertStringContainsString('last.fm/api/auth', $location);
        self::assertStringContainsString('api_key=test-api-key', $location);

        // A CSRF state was generated and stored bound to the initiating user.
        self::assertCount(1, $store->entries);
        $state = array_key_first($store->entries);
        self::assertIsString($state);
        self::assertNotSame('', $state);
        self::assertSame('user-1', $store->entries[$state]);

        // cb points at the new API callback on the request host, carrying the
        // freshly issued state (the whole cb URL is URL-encoded inside the
        // last.fm auth URL, so the encoded callback + state both appear).
        $expectedCb = 'https://phlix.test/api/v1/oauth/lastfm/callback?state=' . $state;
        self::assertStringContainsString('cb=' . rawurlencode($expectedCb), $location);
        self::assertStringNotContainsString('admin%2Flastfm%2Fcallback', $location);
    }

    /**
     * apiAuthorize with no authenticated user (should not happen behind
     * AdminMiddleware, but defended anyway) must NOT seed a state entry and
     * must 302 to `?lastfm=error`.
     */
    public function testApiAuthorizeRedirectsErrorWhenNoUser(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'test-api-key',
            'shared_secret' => 'test-shared-secret',
            'enabled'       => true,
        ]);
        $store = new InMemoryLastfmOAuthStateStore();
        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $this->createMock(LastfmApi::class),
            $store,
        );

        $request = new Request();
        $request->userId = '';

        $response = $controller->apiAuthorize($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
        self::assertCount(0, $store->entries);
    }

    /**
     * apiAuthorize when Last.fm is NOT usable must 302 back to the SPA Services
     * page with `?lastfm=not_configured` — never render any Smarty page.
     */
    public function testApiAuthorizeRedirectsToSpaWhenNotConfigured(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => '',
            'shared_secret' => '',
            'enabled'       => false,
        ]);
        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $this->createMock(LastfmApi::class),
        );

        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->apiAuthorize($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=not_configured',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback with a VALID state + token must exchange it, persist the
     * session for the user BOUND TO THE STATE (recovered server-side, not from
     * the cookie), and 302 to `/app/admin/services?lastfm=connected`.
     */
    public function testApiCallbackSavesSessionAndRedirectsConnected(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->expects(self::once())
            ->method('save')
            ->with('user-1', 'sess-key-123');
        $api = $this->createMock(LastfmApi::class);
        $api->method('getSession')
            ->with('req-token')
            ->willReturn(['session_key' => 'sess-key-123', 'username' => 'lastfm-user']);

        $store = new InMemoryLastfmOAuthStateStore();
        $store->put('state-xyz', 'user-1');

        $controller = new LastfmController($config, $sessions, $api, $store);

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['state' => 'state-xyz', 'token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=connected',
            $response->headers['Location'] ?? '',
        );
        // State was consumed (one-shot) by the callback.
        self::assertCount(0, $store->entries);
    }

    /**
     * apiCallback with a MISSING state must NOT call getSession (no exchange,
     * no save) and must 302 to `?lastfm=error`.
     */
    public function testApiCallbackRedirectsErrorWhenStateMissing(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->expects(self::never())->method('save');
        $api = $this->createMock(LastfmApi::class);
        $api->expects(self::never())->method('getSession');

        $controller = new LastfmController(
            $config,
            $sessions,
            $api,
            new InMemoryLastfmOAuthStateStore(),
        );

        $request = new Request();
        $request->userId = 'user-1';
        // token present but NO state → forged callback.
        $request->query = ['token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback with an UNKNOWN/unissued state must NOT call getSession and
     * must 302 to `?lastfm=error` — the core CSRF defence.
     */
    public function testApiCallbackRedirectsErrorWhenStateUnknown(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->expects(self::never())->method('save');
        $api = $this->createMock(LastfmApi::class);
        $api->expects(self::never())->method('getSession');

        // Empty store → no state matches.
        $controller = new LastfmController(
            $config,
            $sessions,
            $api,
            new InMemoryLastfmOAuthStateStore(),
        );

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['state' => 'never-issued', 'token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback with a valid state but a MISSING token must 302 to
     * `?lastfm=error` (and consume the state) rather than 500/strand.
     */
    public function testApiCallbackRedirectsErrorWhenTokenMissing(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $api = $this->createMock(LastfmApi::class);
        $api->expects(self::never())->method('getSession');

        $store = new InMemoryLastfmOAuthStateStore();
        $store->put('state-xyz', 'user-1');

        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $api,
            $store,
        );

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['state' => 'state-xyz'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback when the token exchange fails (getSession returns null) must
     * 302 to `?lastfm=error`, never a JSON 502.
     */
    public function testApiCallbackRedirectsErrorWhenExchangeFails(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->expects(self::never())->method('save');
        $api = $this->createMock(LastfmApi::class);
        $api->method('getSession')->willReturn(null);

        $store = new InMemoryLastfmOAuthStateStore();
        $store->put('state-xyz', 'user-1');

        $controller = new LastfmController($config, $sessions, $api, $store);

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['state' => 'state-xyz', 'token' => 'bad-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback where the ambient cookie userId disagrees with the
     * state-bound userId must be rejected (302 error) and must NOT save.
     */
    public function testApiCallbackRejectsCookieUserMismatch(): void
    {
        $config = LastfmConfig::fromArray([
            'api_key'       => 'k',
            'shared_secret' => 's',
            'enabled'       => true,
        ]);
        $sessions = $this->createMock(LastfmSessionRepository::class);
        $sessions->expects(self::never())->method('save');
        $api = $this->createMock(LastfmApi::class);
        $api->expects(self::never())->method('getSession');

        $store = new InMemoryLastfmOAuthStateStore();
        $store->put('state-xyz', 'user-1');

        $controller = new LastfmController($config, $sessions, $api, $store);

        $request = new Request();
        // Cookie says a DIFFERENT user than the one that initiated the flow.
        $request->userId = 'attacker-2';
        $request->query = ['state' => 'state-xyz', 'token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }
}

/**
 * In-memory {@see LastfmOAuthStateStore} for controller tests — avoids the
 * `$_SESSION` global so tests stay isolated and inspectable.
 */
final class InMemoryLastfmOAuthStateStore implements LastfmOAuthStateStore
{
    /** @var array<string, string> state => userId */
    public array $entries = [];

    public function put(string $state, string $userId): void
    {
        $this->entries[$state] = $userId;
    }

    public function consume(string $state): ?string
    {
        if (!isset($this->entries[$state])) {
            return null;
        }
        $userId = $this->entries[$state];
        unset($this->entries[$state]);
        return $userId;
    }
}
