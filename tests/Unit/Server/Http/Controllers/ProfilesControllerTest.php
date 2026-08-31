<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Access\ProfileAccessPolicy;
use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Common\RateLimit\RateLimitState;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\ProfilesController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * S81 — the self-service profiles controller, ownership-gated and
 * fail-closed.
 *
 * ## What is pinned here
 *
 * - **Ownership**: every profile-scoped action answers 404 for another
 *   account's profile (and for a nonexistent one — uniform, no existence
 *   oracle). The gate is the ProfileAccessPolicy result; a denied caller
 *   never reaches the manager.
 * - **The two-active-profiles hole**: `update()` refuses `is_active` with
 *   400 `profile.use_switch` — activation is switch-only.
 * - **The last-profile guard**: deleting the final profile is 409
 *   `profile.last_profile`.
 * - **The PIN oracle closure**: verify is throttled by the injected limiter
 *   (RateLimitException → central 429), answers 409 `profile.no_pin` for a
 *   PIN-less profile and 403 `profile.pin_mismatch` for a wrong PIN.
 * - **Switch re-mints tokens**: the endpoint returns AuthManager's
 *   buildAuthResponse() envelope for the NEW profile.
 *
 * The controller is constructed by hand here (mocked collaborators); the
 * PRODUCTION container wiring is pinned separately in
 * {@see ProfilesControllerWiringGuardTest}.
 */
final class ProfilesControllerTest extends TestCase
{
    private UserProfileManager&MockObject $profiles;
    private ProfileAccessPolicy $access;
    private AuthManager&MockObject $auth;
    private AvatarStorage&MockObject $avatars;
    private RateLimiterInterface&MockObject $limiter;

    protected function setUp(): void
    {
        $this->profiles = $this->createMock(UserProfileManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $this->auth = $this->createMock(AuthManager::class);
        $this->avatars = $this->createMock(AvatarStorage::class);
        $this->limiter = $this->createMock(RateLimiterInterface::class);

        // The policy is final, so it is REAL here — ownership is decided by
        // what the mocked manager hands back. Default: every profile is owned
        // by user-1 EXCEPT 'p-other', which belongs to another account, so the
        // denial test can drive the uniform-404 path through the production
        // gate instead of a mock (mirrors BookControllerParentalTest's shape).
        $this->profiles->method('findById')->willReturnCallback(
            static fn (string $id): array => ['id' => $id, 'user_id' => $id === 'p-other' ? 'user-2' : 'user-1'],
        );
        $userRepo->method('findAdminById')->willReturn(null);

        $this->access = new ProfileAccessPolicy($this->profiles, $userRepo);
    }

    private function controller(): ProfilesController
    {
        return new ProfilesController(
            $this->profiles,
            $this->access,
            $this->auth,
            $this->avatars,
            $this->limiter,
        );
    }

    private function authedRequest(string $userId = 'user-1'): Request
    {
        $request = new Request();
        $request->userId = $userId;

        return $request;
    }

    /**
     * Decode the JSON body of a chained `->json()` response.
     *
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        $decoded = json_decode($response->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ---- ownership (the 404-uniform rule) -----------------------------------

    public function testEveryScopedActionDeniesUnentitledCallerWith404(): void
    {
        // 'p-other' resolves to another account through the REAL policy
        // (see setUp), so this exercises production's ownership gate, not a
        // stubbed answer. The manager must never be reached by a denied caller.
        $this->profiles->expects($this->never())->method('findByIdWithSettings');
        $this->profiles->expects($this->never())->method('update');
        $this->profiles->expects($this->never())->method('delete');
        $this->profiles->expects($this->never())->method('setPin');
        $this->profiles->expects($this->never())->method('removePin');
        $this->profiles->expects($this->never())->method('verifyPin');
        $this->profiles->expects($this->never())->method('switchProfile');

        $request = $this->authedRequest('user-1');

        $methods = ['get', 'update', 'delete', 'setPin', 'removePin', 'verifyPin', 'switchProfile', 'uploadAvatar'];
        foreach ($methods as $method) {
            $response = $this->controller()->{$method}($request, ['profileId' => 'p-other']);
            $this->assertSame(404, $response->statusCode, $method . ' must 404 for an unentitled caller');
        }
    }

    public function testListReturnsOnlyTheCallersOwnProfiles(): void
    {
        $this->profiles->expects($this->once())
            ->method('findByUserId')
            ->with('user-1')
            ->willReturn([['id' => 'p-1', 'name' => 'Kid']]);

        $response = $this->controller()->list($this->authedRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['profiles' => [['id' => 'p-1', 'name' => 'Kid']]], $this->payload($response));
    }

    public function testListRequiresAuthentication(): void
    {
        $response = $this->controller()->list(new Request(), []);
        $this->assertSame(401, $response->statusCode);
    }

    // ---- create --------------------------------------------------------------

    public function testCreateValidatesNameAndDelegates(): void
    {
        $this->profiles->expects($this->once())
            ->method('create')
            ->with('user-1', $this->callback(static function (array $data): bool {
                return ($data['name'] ?? null) === 'Kid' && !array_key_exists('is_active', $data);
            }))
            ->willReturn('p-new');

        $request = $this->authedRequest();
        $request->body = ['name' => '  Kid  '];

        $response = $this->controller()->create($request, []);
        $this->assertSame(201, $response->statusCode);
        $this->assertSame('p-new', $this->payload($response)['profile_id']);
    }

    public function testCreateRejectsMissingName(): void
    {
        $request = $this->authedRequest();
        $request->body = [];

        $response = $this->controller()->create($request, []);
        $this->assertSame(400, $response->statusCode);
    }

    public function testCreateSurfacesMaxProfilesAs400(): void
    {
        $this->profiles->method('create')
            ->willThrowException(new \InvalidArgumentException('Maximum number of profiles (5) reached'));

        $request = $this->authedRequest();
        $request->body = ['name' => 'Kid'];

        $response = $this->controller()->create($request, []);
        $this->assertSame(400, $response->statusCode);
        $this->assertStringContainsString('Maximum number of profiles', $this->payload($response)['error']);
    }

    // ---- get / update ---------------------------------------------------------

    public function testGetStripsPinHashFromSettings(): void
    {
        $this->profiles->method('findByIdWithSettings')
            ->with('p-1')
            ->willReturn(['id' => 'p-1', 'name' => 'Kid', 'pin_hash' => 'argon2-blob', 'rating' => 1]);

        $response = $this->controller()->get($this->authedRequest(), ['profileId' => 'p-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertArrayNotHasKey('pin_hash', $this->payload($response)['profile']);
    }

    public function testUpdateRefusesIsActiveWithUseSwitch(): void
    {
        $this->profiles->expects($this->never())->method('update');

        $request = $this->authedRequest();
        $request->body = ['name' => 'Kid', 'is_active' => true];

        $response = $this->controller()->update($request, ['profileId' => 'p-1']);

        $this->assertSame(400, $response->statusCode);
        $this->assertSame('profile.use_switch', $this->payload($response)['error']);
    }

    public function testUpdateDelegatesAllowedFieldsOnly(): void
    {
        // No is_active in the body: `pin` and unknown keys must be filtered
        // out of the payload handed to the manager, allowed fields kept.
        $this->profiles->expects($this->once())
            ->method('update')
            ->with('p-1', $this->callback(static function (array $data): bool {
                return ($data['name'] ?? null) === 'Kid'
                    && ($data['content_rating'] ?? null) === 'PG'
                    && ($data['pin_required_for_admin'] ?? null) === true
                    && !array_key_exists('is_active', $data)
                    && !array_key_exists('pin', $data);
            }));

        $request = $this->authedRequest();
        $request->body = [
            'name' => 'Kid',
            'content_rating' => 'PG',
            'pin' => '1234',
            'pin_required_for_admin' => true,
        ];

        $response = $this->controller()->update($request, ['profileId' => 'p-1']);
        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateRejectsNonStringContentRating(): void
    {
        $request = $this->authedRequest();
        $request->body = ['content_rating' => 42];

        $response = $this->controller()->update($request, ['profileId' => 'p-1']);
        $this->assertSame(400, $response->statusCode);
    }

    // ---- delete ---------------------------------------------------------------

    public function testDeleteRefusesTheLastProfile(): void
    {
        $this->profiles->method('findByUserId')->with('user-1')->willReturn([
            ['id' => 'p-1', 'name' => 'Only'],
        ]);
        $this->profiles->expects($this->never())->method('delete');

        $response = $this->controller()->delete($this->authedRequest(), ['profileId' => 'p-1']);

        $this->assertSame(409, $response->statusCode);
        $this->assertSame('profile.last_profile', $this->payload($response)['error']);
    }

    public function testDeleteAllowsNonLastProfile(): void
    {
        $this->profiles->method('findByUserId')->with('user-1')->willReturn([
            ['id' => 'p-1', 'name' => 'Kid'],
            ['id' => 'p-2', 'name' => 'Owner'],
        ]);
        $this->profiles->expects($this->once())->method('delete')->with('p-1');

        $response = $this->controller()->delete($this->authedRequest(), ['profileId' => 'p-1']);
        $this->assertSame(200, $response->statusCode);
    }

    // ---- pin endpoints ----------------------------------------------------------

    public function testSetPinValidatesDigitsAndLength(): void
    {
        foreach (['123', '12345', 'abcd', '12a4'] as $badPin) {
            $request = $this->authedRequest();
            $request->body = ['pin' => $badPin];

            $response = $this->controller()->setPin($request, ['profileId' => 'p-1']);
            $this->assertSame(400, $response->statusCode, "pin '$badPin' must be rejected");
        }
    }

    public function testSetPinDelegatesValidPin(): void
    {
        $this->profiles->expects($this->once())->method('setPin')->with('p-1', '1234');

        $request = $this->authedRequest();
        $request->body = ['pin' => '1234'];

        $response = $this->controller()->setPin($request, ['profileId' => 'p-1']);
        $this->assertSame(200, $response->statusCode);
    }

    public function testSetPinEmptyClearsPin(): void
    {
        $this->profiles->expects($this->once())->method('removePin')->with('p-1');

        $request = $this->authedRequest();
        $request->body = ['pin' => ''];

        $response = $this->controller()->setPin($request, ['profileId' => 'p-1']);
        $this->assertSame(200, $response->statusCode);
    }

    public function testVerifyPinAnswersNoPinWith409(): void
    {
        $this->limiter->method('hit')->willReturn(new RateLimitState(1, 4, 0, false, 5));
        $this->profiles->method('hasPin')->with('p-1')->willReturn(false);
        $this->profiles->expects($this->never())->method('verifyPin');

        $request = $this->authedRequest();
        $request->body = ['pin' => '1234'];

        $response = $this->controller()->verifyPin($request, ['profileId' => 'p-1']);
        $this->assertSame(409, $response->statusCode);
        $this->assertSame('profile.no_pin', $this->payload($response)['error']);
    }

    public function testVerifyPinAnswersMismatchWith403(): void
    {
        $this->limiter->method('hit')->willReturn(new RateLimitState(1, 4, 0, false, 5));
        $this->profiles->method('hasPin')->with('p-1')->willReturn(true);
        $this->profiles->method('verifyPin')->with('p-1', '9999')->willReturn(false);

        $request = $this->authedRequest();
        $request->body = ['pin' => '9999'];

        $response = $this->controller()->verifyPin($request, ['profileId' => 'p-1']);
        $this->assertSame(403, $response->statusCode);
        $this->assertSame('profile.pin_mismatch', $this->payload($response)['error']);
    }

    public function testVerifyPinAnswersMatchWith200(): void
    {
        $this->limiter->method('hit')->willReturn(new RateLimitState(1, 4, 0, false, 5));
        $this->profiles->method('hasPin')->with('p-1')->willReturn(true);
        $this->profiles->method('verifyPin')->with('p-1', '1234')->willReturn(true);

        $request = $this->authedRequest();
        $request->body = ['pin' => '1234'];

        $response = $this->controller()->verifyPin($request, ['profileId' => 'p-1']);
        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($this->payload($response)['verified']);
    }

    public function testVerifyPinTripsTheLimiterInto429(): void
    {
        $this->limiter->method('hit')
            ->with('profile-pin:p-1')
            ->willReturn(new RateLimitState(5, 0, 1767225600, true, 5));
        $this->profiles->expects($this->never())->method('hasPin');

        $request = $this->authedRequest();
        $request->body = ['pin' => '1234'];

        $this->expectException(RateLimitException::class);
        $this->controller()->verifyPin($request, ['profileId' => 'p-1']);
    }

    // ---- switch ---------------------------------------------------------------

    public function testSwitchReMintsTokensForTheNewProfile(): void
    {
        $this->profiles->expects($this->once())
            ->method('switchProfile')
            ->with('user-1', 'p-2')
            ->willReturn(true);

        $envelope = [
            'access_token' => 'at-new',
            'refresh_token' => 'rt-new',
            'profile_id' => 'p-2',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'user-1'],
        ];
        $this->auth->expects($this->once())
            ->method('buildAuthResponse')
            ->with('user-1', 'p-2')
            ->willReturn($envelope);

        $response = $this->controller()->switchProfile($this->authedRequest(), ['profileId' => 'p-2']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('at-new', $this->payload($response)['access_token']);
        $this->assertSame('p-2', $this->payload($response)['profile_id']);
    }

    public function testSwitchFailureAnswers404(): void
    {
        $this->profiles->method('switchProfile')->willReturn(false);

        $response = $this->controller()->switchProfile($this->authedRequest(), ['profileId' => 'p-2']);
        $this->assertSame(404, $response->statusCode);
    }

    // ---- avatar ---------------------------------------------------------------

    public function testUploadAvatarStoresPerProfileAndUpdatesAvatarUrl(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 's81-avatar');
        $this->assertNotFalse($tmpFile);
        // A real 1x1 PNG so AvatarStorage validation would accept it.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        $this->assertIsString($png);
        file_put_contents($tmpFile, $png);

        $this->avatars->expects($this->once())
            ->method('store')
            ->with('p-1', $tmpFile)
            ->willReturn('/var/avatars/p-1.jpg');
        $this->profiles->expects($this->once())
            ->method('update')
            ->with('p-1', ['avatar_url' => '/var/avatars/p-1.jpg']);
        $this->avatars->expects($this->once())
            ->method('url')
            ->with('p-1', '/var/avatars/p-1.jpg')
            ->willReturn('/api/v1/avatars/p-1.jpg');

        $request = $this->authedRequest();
        $request->files = [
            'avatar' => [
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $tmpFile,
                'name' => 'avatar.png',
                'size' => filesize($tmpFile),
                'type' => 'image/png',
            ],
        ];

        $response = $this->controller()->uploadAvatar($request, ['profileId' => 'p-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('/api/v1/avatars/p-1.jpg', $this->payload($response)['avatar_url']);

        unlink($tmpFile);
    }

    public function testUploadAvatarRejectsMissingFile(): void
    {
        $request = $this->authedRequest();
        $request->files = [];

        $response = $this->controller()->uploadAvatar($request, ['profileId' => 'p-1']);
        $this->assertSame(400, $response->statusCode);
    }

    // ---- response payload accessor ---------------------------------------------

    public function testParseProfileIdRejectsEmptyAndNonString(): void
    {
        $this->profiles->expects($this->never())->method('findByIdWithSettings');

        $response = $this->controller()->get($this->authedRequest(), ['profileId' => '']);
        $this->assertSame(400, $response->statusCode);

        $response = $this->controller()->get($this->authedRequest(), []);
        $this->assertSame(400, $response->statusCode);
    }
}
