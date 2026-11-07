<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\AuthManager;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * The unwired-service 503 copy, per surface (review server__webux Major).
 *
 * One placeholder sentence — 'This feature isn't available on this server
 * right now. Please try again later.' — answered 31 distinct failures across
 * the web-portal router. The retry promise was false for an unwired service
 * (a deployment fact, not a transient condition), and the sentence named
 * nothing the reader could act on. The cure routes every site through
 * WebPortalRouter::serviceUnavailable(surface, service); these tests pin:
 *
 *  - the SOURCE tripwires: the placeholder sentence is gone, exactly 31 named
 *    call sites remain, and every distinct surface name appears in the file;
 *  - the BEHAVIOR on representative arms (watch-history, themes, ratings,
 *    avatar): status 503, flat single-key `{error}` envelope (the wire shape
 *    is UNCHANGED — no new codes), the copy names the surface and the
 *    service, and it no longer promises a retry.
 *
 * Pre-existing status-only pins (ThemeEndpointsReachabilityTest,
 * WebPortalRouterNextUpTest, WebPortalRouterPersonPhotoTest) stay green: the
 * code this copy rides is the same 503 they assert.
 */
final class WebPortalRouterUnwiredServiceCopyTest extends TestCase
{
    /** The retired placeholder sentence (written so this file never contains it verbatim). */
    private const RETIRED_SENTENCE = 'This feature is' . "n't available on this server right now. Please try again later.";

    /** @var list<string> every distinct surface name the router must now carry */
    private const SURFACE_NAMES = [
        'Next Up',
        'Watch history',
        'My list',
        'Ratings',
        'Thumbs rating',
        'Watched markers',
        'Similar titles',
        'Collections',
        'Themes',
        'Person photos',
        'Recommendations',
        'Transcoding',
        'Playback preferences',
        'Settings',
        'Profile pictures',
        'Music library scans',
    ];

    /** @var list<string> every distinct service name the router must now carry */
    private const SERVICE_NAMES = [
        'watch-history',
        'profile',
        'user-data',
        'ratings',
        'similarity',
        'collection',
        'theming',
        'artwork-storage',
        'recommendation',
        'transcode',
        'user-settings',
        'avatar',
        'music-library',
    ];

    private static function routerSource(): string
    {
        $path = dirname(__DIR__, 4) . '/src/Server/WebPortal/WebPortalRouter.php';
        $text = @file_get_contents($path);
        self::assertIsString($text, 'WebPortalRouter.php must exist for the copy tripwire');
        return $text;
    }

    public function testThePlaceholderSentenceIsGoneFromTheRouter(): void
    {
        self::assertStringNotContainsString(
            self::RETIRED_SENTENCE,
            self::routerSource(),
            'the blanket retry-promise placeholder must never come back — every unwired-service '
                . '503 goes through serviceUnavailable() with a named surface'
        );
    }

    public function testEveryUnwiredServiceSiteRoutesThroughTheHelper(): void
    {
        $calls = substr_count(self::routerSource(), "return \$this->serviceUnavailable('");

        self::assertSame(
            31,
            $calls,
            'the router carries exactly 31 unwired-service surfaces through the named helper '
                . '(measured at the cure commit; a new optional-collaborator arm must name its surface too, '
                . 'and retiring one rotates this pin in the same commit)'
        );
    }

    public function testEverySurfaceAndServiceNameAppearsInTheRouter(): void
    {
        $source = self::routerSource();
        foreach (self::SURFACE_NAMES as $surface) {
            self::assertStringContainsString(
                "serviceUnavailable('" . $surface . "'",
                $source,
                "surface '{$surface}' must name its 503 arms"
            );
        }
        foreach (self::SERVICE_NAMES as $service) {
            self::assertStringContainsString(
                "', '{$service}')",
                $source,
                "service '{$service}' must be named by at least one 503 arm"
            );
        }
    }

    /**
     * A router with the seven required collaborators mocked and every optional
     * one ABSENT — the exact state each 503 arm answers.
     */
    private function unwiredRouter(): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(\Phlix\Media\Library\ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
        );
    }

    private function authedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * Asserts the 503 arms the review flagged: status code and flat `{error}`
     * envelope unchanged, copy now names the failed surface and the unwired
     * service, and the retired "try again later" promise stays gone.
     */
    private function assertNamedUnavailable(Response $resp, string $surface, string $service): void
    {
        self::assertSame(503, $resp->statusCode, 'unwired services answer 503 (wire shape unchanged)');

        $body = json_decode($resp->body, true);
        self::assertIsArray($body);
        self::assertSame(['error'], array_keys($body), 'the envelope stays the flat single-key {error}');

        $message = $body['error'];
        self::assertIsString($message);
        self::assertStringContainsString($surface, $message, "the copy must name the failed surface ('{$surface}')");
        self::assertStringContainsString($service, $message, "the copy must name the unwired service ('{$service}')");
        self::assertStringNotContainsString(
            'try again',
            $message,
            'an unwired service is a deployment fact — the copy must not promise a retry the client cannot honor'
        );
    }

    public function testNextUpArmNamesItsSurfaceAndService(): void
    {
        $resp = $this->unwiredRouter()->getNextUp($this->authedRequest(), []);
        $this->assertNamedUnavailable($resp, 'Next Up', 'watch-history');
    }

    public function testThemesArmNamesItsSurfaceAndService(): void
    {
        $resp = $this->unwiredRouter()->listThemes($this->authedRequest(), []);
        $this->assertNamedUnavailable($resp, 'Themes', 'theming');
    }

    public function testRatingsArmNamesItsSurfaceAndService(): void
    {
        $resp = $this->unwiredRouter()->getRatings(['id' => 'm1']);
        $this->assertNamedUnavailable($resp, 'Ratings', 'ratings');
    }

    public function testAvatarArmNamesItsSurfaceAndService(): void
    {
        $resp = $this->unwiredRouter()->uploadAvatar($this->authedRequest(), []);
        $this->assertNamedUnavailable($resp, 'Profile pictures', 'avatar');
    }
}
