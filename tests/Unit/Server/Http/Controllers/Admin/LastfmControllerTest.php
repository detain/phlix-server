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
}
