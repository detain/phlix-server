<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\AuthManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;

/**
 * Covers updateUserSettings()/extractSettingsPayload(): the payload must be
 * read from the decoded request BODY (not the query string), and only
 * whitelisted keys may reach the repository.
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
}
