<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Plugins\Scrobbler\Lastfm\LastfmApi;
use Phlix\Plugins\Scrobbler\Lastfm\LastfmConfig;
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
        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $this->createMock(LastfmApi::class),
        );

        $request = new Request();
        $request->userId = 'user-1';
        $request->headers = ['HOST' => 'phlix.test', 'X-FORWARDED-PROTO' => 'https'];

        $response = $controller->apiAuthorize($request, []);

        self::assertSame(302, $response->statusCode);
        $location = $response->headers['Location'] ?? '';
        self::assertStringContainsString('last.fm/api/auth', $location);
        self::assertStringContainsString('api_key=test-api-key', $location);
        // cb points at the new API callback on the request host (URL-encoded).
        self::assertStringContainsString(
            'cb=' . rawurlencode('https://phlix.test/api/v1/oauth/lastfm/callback'),
            $location,
        );
        self::assertStringNotContainsString('admin%2Flastfm%2Fcallback', $location);
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
     * apiCallback with a valid token must exchange it, persist the session for
     * the calling user, and 302 to `/app/admin/services?lastfm=connected`.
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
            ->willReturn(['session_key' => 'sess-key-123']);

        $controller = new LastfmController($config, $sessions, $api);

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=connected',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback with a missing token must 302 to `?lastfm=error` rather than
     * returning a JSON 400 that would strand the browser.
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

        $controller = new LastfmController(
            $config,
            $this->createMock(LastfmSessionRepository::class),
            $api,
        );

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = [];

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

        $controller = new LastfmController($config, $sessions, $api);

        $request = new Request();
        $request->userId = 'user-1';
        $request->query = ['token' => 'bad-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }

    /**
     * apiCallback with no authenticated user must 302 to `?lastfm=error`
     * (auth required) — it must not attempt an exchange or a save.
     */
    public function testApiCallbackRedirectsErrorWhenNoUser(): void
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

        $controller = new LastfmController($config, $sessions, $api);

        $request = new Request();
        $request->userId = '';
        $request->query = ['token' => 'req-token'];

        $response = $controller->apiCallback($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/app/admin/services?lastfm=error',
            $response->headers['Location'] ?? '',
        );
    }
}
