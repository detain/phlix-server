<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Workerman\MySQL\Connection;

/**
 * Covers updateUserSettings()/extractSettingsPayload(): the payload must be
 * read from the decoded request BODY (not the query string), and only
 * whitelisted keys may reach the repository.
 *
 * Also pins the theme 400 mapping (repository InvalidArgumentException → flat
 * {error} 400 envelope, no new error codes), the read-modify-write guard on
 * updatePlaybackPreferences() (a legacy stored theme must not 400 a crossfade
 * save, and that endpoint must never write user_profiles), and that handler's
 * defense-in-depth InvalidArgumentException → 400 catch.
 */
class WebPortalRouterSettingsTest extends TestCase
{
    /**
     * @param UserRepository|null $userRepository
     */
    private function makeRouter(?UserRepository $userRepository): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            null, // chapterSearchService
            $userRepository
        );
    }

    public function testPutBodySettingsReachRepositoryWithWhitelistOnly(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->with(
                'user-123',
                $this->callback(static function (array $settings): bool {
                    // Whitelisted keys present with their submitted values...
                    return ($settings['max_streams'] ?? null) === 5
                        && ($settings['preferred_audio_language'] ?? null) === 'fr'
                        // ...and the unknown key was dropped (no mass-assignment).
                        && !array_key_exists('is_admin', $settings)
                        && !array_key_exists('id', $settings);
                })
            );

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        // A JSON PUT lands in $request->body (decoded) per Request::fromGlobals().
        $request->rawBody = (string) json_encode([
            'max_streams' => 5,
            'preferred_audio_language' => 'fr',
            'is_admin' => true,
            'id' => 'attacker-controlled',
        ]);
        $request->body = [
            'max_streams' => 5,
            'preferred_audio_language' => 'fr',
            'is_admin' => true,
            'id' => 'attacker-controlled',
        ];

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testPutFallsBackToRawBodyWhenBodyEmpty(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->with(
                'user-123',
                $this->callback(static fn (array $settings): bool => ($settings['subtitle_mode'] ?? null) === 'always')
            );

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        // body empty, but raw JSON present (e.g. a custom content-type path).
        $request->body = [];
        $request->rawBody = (string) json_encode(['subtitle_mode' => 'always']);

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testQueryBodyIsIgnored(): void
    {
        // The old bug read $request->query['body']; ensure that value no longer
        // leaks into the repository when the real body is empty. The handler
        // still calls updateSettings (which no-ops on an empty array), so we
        // assert the payload is empty rather than that the query value reached
        // the repo.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->with(
                'user-123',
                $this->callback(static fn (array $settings): bool => $settings === [])
            );

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];
        $request->rawBody = '';
        $request->query = ['body' => json_encode(['max_streams' => 99])];

        $response = $router->updateUserSettings($request, []);

        // Query 'body' is ignored; empty payload persists nothing but succeeds.
        $this->assertEquals(200, $response->statusCode);
    }

    public function testMalformedRawBodyReturns400(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->never())->method('updateSettings');

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        $request->body = [];
        $request->rawBody = 'not-json';

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(400, $response->statusCode);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $router = $this->makeRouter($this->createMock(UserRepository::class));

        $request = new Request();
        $request->body = ['max_streams' => 5];

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(401, $response->statusCode);
    }

    public function testPutUnknownThemeReturns400WithRepositoryMessage(): void
    {
        // The repo validates theme against live truth and throws; the handler
        // must map that to 400 carrying the SAME flat {error} envelope style
        // the payload 400 already uses — silent-drop-with-200 is impossible.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->willThrowException(new \InvalidArgumentException(
                'Unknown theme "phlix-dark". Valid themes: nocturne, daylight, midnight.'
            ));

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        $request->body = ['theme' => 'phlix-dark'];
        $request->rawBody = (string) json_encode($request->body);

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(400, $response->statusCode);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            'Unknown theme "phlix-dark". Valid themes: nocturne, daylight, midnight.',
            $decoded['error'] ?? null
        );
    }

    public function testPutValidThemeReachesRepositoryAndReturns200(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->with(
                'user-123',
                $this->callback(static fn (array $settings): bool => ($settings['theme'] ?? null) === 'nocturne')
            );

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-123';
        $request->body = ['theme' => 'nocturne'];
        $request->rawBody = (string) json_encode($request->body);

        $response = $router->updateUserSettings($request, []);

        $this->assertEquals(200, $response->statusCode);
    }

    public function testPlaybackPreferencesSaveWithLegacyStoredThemeSucceeds(): void
    {
        // RMW guard: getSettings() injects the STORED theme, which may be a
        // legacy id written before validation existed ('phlix-dark'). The
        // handler strips it before re-passing the payload — an unrelated
        // crossfade save must not 400 over stored history, and must never
        // write user_profiles (that would launder the legacy id into a
        // "validated" write path).
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []) use (&$captured): array {
                $captured[] = [$sql, $bindings];
                if (str_contains($sql, 'SELECT * FROM user_settings')) {
                    return [['user_id' => 'user-1']];
                }
                if (str_contains($sql, 'SELECT active_theme_id FROM user_profiles')) {
                    return [['active_theme_id' => 'phlix-dark']];
                }
                return [];
            });

        $router = $this->makeRouter(new UserRepository($db));

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['crossfadeDuration' => 4];
        $request->rawBody = (string) json_encode($request->body);

        $response = $router->updatePlaybackPreferences($request, []);

        $this->assertEquals(200, $response->statusCode);
        foreach ($captured as [$sql]) {
            $this->assertStringNotContainsString('UPDATE user_profiles', $sql);
        }
    }

    public function testPlaybackPreferencesRejectedUpdateMapsTo400Envelope(): void
    {
        // Belt pin for updatePlaybackPreferences()'s InvalidArgumentException
        // → 400 catch. Today the unset($settings['theme']) guard upstream
        // removes the only field that validates, so no REAL rejection can
        // arise through this handler — the throw is injected at the
        // repository seam to pin the catch arm itself: whatever validation a
        // future lane adds to updateSettings(), this endpoint must answer
        // with the same flat {error} 400 envelope updateUserSettings() uses
        // (contract law: no new error codes), never an uncaught 500.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('updateSettings')
            ->willThrowException(new \InvalidArgumentException('future-field must be a known value'));

        $router = $this->makeRouter($userRepo);

        $request = new Request();
        $request->userId = 'user-1';
        $request->body = ['crossfadeDuration' => 4];
        $request->rawBody = (string) json_encode($request->body);

        $response = $router->updatePlaybackPreferences($request, []);

        $this->assertEquals(400, $response->statusCode);
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('future-field must be a known value', $decoded['error'] ?? null);
        $this->assertArrayNotHasKey('code', $decoded, 'flat {error} envelope — no new error codes');
    }
}
