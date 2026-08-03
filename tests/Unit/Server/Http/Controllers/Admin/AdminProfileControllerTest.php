<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminProfileController (Step 1.2b).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller; here we assert the controller's behaviour given an
 * already-authenticated-admin request.
 */
final class AdminProfileControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     */
    private function makeRequest(array $body = []): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    // ─────────────────────────────────────────────────────────────────
    // listForUser()
    // ─────────────────────────────────────────────────────────────────

    public function testListForUserHappy(): void
    {
        $profiles = [
            ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'],
            ['id' => 'prof_2', 'user_id' => '1', 'name' => 'Bob'],
        ];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn($profiles);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->listForUser($this->makeRequest(), ['userId' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{profiles: array<int, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profiles', $body);
        $this->assertCount(2, $body['profiles']);
    }

    public function testListForUserNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->never())->method('findByUserId');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->listForUser($this->makeRequest(), ['userId' => '999']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User not found', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // createForUser()
    // ─────────────────────────────────────────────────────────────────

    public function testCreateForUserHappy(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn([['id' => 'prof_1', 'name' => 'Alice']]);
        $profileManager->expects($this->once())
            ->method('create')
            ->with('1', ['name' => 'Bob', 'content_rating' => 'PG-13'])
            ->willReturn('prof_2');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest([
            'name' => 'Bob',
            'rating' => 2,
        ]), ['userId' => '1']);

        $this->assertSame(201, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profile_id', $body);
        $this->assertSame('Profile created successfully', $body['message']);
    }

    public function testCreateForUserMapsTvRatingInt(): void
    {
        // Phase C: numeric keys 7-12 map to the appended US TV ratings.
        // 11 → TV-14 (see AdminProfileController::RATING_MAP).
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn([]);
        $profileManager->expects($this->once())
            ->method('create')
            ->with('1', ['name' => 'Teen', 'content_rating' => 'TV-14'])
            ->willReturn('prof_9');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest([
            'name' => 'Teen',
            'rating' => 11,
        ]), ['userId' => '1']);

        $this->assertSame(201, $response->statusCode);
    }

    public function testCreateForUserRejectsRatingAboveMax(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn([]);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest([
            'name' => 'Bad',
            'rating' => 13, // above MAX_RATING_INT (12)
        ]), ['userId' => '1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testCreateForUserMaxProfiles(): void
    {
        $existingProfiles = [
            ['id' => 'prof_1'],
            ['id' => 'prof_2'],
            ['id' => 'prof_3'],
            ['id' => 'prof_4'],
            ['id' => 'prof_5'],
        ];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn($existingProfiles);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest(['name' => 'TooMany']), ['userId' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Maximum profiles reached', $body['error']);
    }

    public function testCreateForUserUserNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->never())->method('findByUserId');
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest(['name' => 'Bob']), ['userId' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testCreateForUserInvalidName(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->willReturn([]);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest([
            'name' => 'This name is way too long and exceeds fifty characters which is the maximum allowed',
        ]), ['userId' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid name', $body['error']);
        $this->assertArrayHasKey('field_errors', $body);
    }

    public function testCreateForUserInvalidRating(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->willReturn([]);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser($this->makeRequest([
            'name' => 'ValidName',
            'rating' => 99,
        ]), ['userId' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid rating', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // get()
    // ─────────────────────────────────────────────────────────────────

    public function testGetHappyPath(): void
    {
        // findByIdWithSettings calls hydrateProfile() which computes 'rating' from content_rating
        // PG-13 -> RATING_ORDER['PG-13']=3 -> ts_rating = 3-1 = 2
        $hydratedProfile = [
            'id' => 'prof_1',
            'user_id' => '1',
            'name' => 'Alice',
            'avatar_url' => null,
            'is_active' => false,
            'is_admin' => false,
            'created_at' => null,
            'updated_at' => null,
            'rating' => 2, // computed: RATING_ORDER['PG-13']=3, ts_rating=3-1=2
            'settings' => [
                'content_rating' => 'PG-13',
                'pin_required_for_admin' => false,
                'max_daily_watch_time' => 0,
                'allow_unrated' => true,
            ],
        ];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByIdWithSettings')
            ->with('1')
            ->willReturn($hydratedProfile);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->get($this->makeRequest(), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{profile: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profile', $body);
        $this->assertSame('Alice', $body['profile']['name']);
        $this->assertSame(2, $body['profile']['rating']); // PG-13 = ts index 2
    }

    public function testGetNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByIdWithSettings')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->get($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile not found', $body['error']);
    }

    /**
     * Regression: every id-based profile route 500'd live because the methods
     * declared `int $profileId` / `int $userId` first while the daemon Router
     * always calls `$method($request, $params)`. A UUID `$params['id']` /
     * `$params['userId']` must now flow verbatim to the managers and return a
     * non-500 response.
     */
    public function testGetWithUuidParamsDoesNotTypeError(): void
    {
        $uuid = 'prof-7c9e6679-7425-40de-944b-e07fc1f90ae7';
        $hydratedProfile = ['id' => $uuid, 'user_id' => '1', 'name' => 'Alice', 'rating' => 2];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByIdWithSettings')
            ->with($uuid)
            ->willReturn($hydratedProfile);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->get($this->makeRequest(), ['id' => $uuid]);

        $this->assertLessThan(500, $response->statusCode);
        $this->assertSame(200, $response->statusCode);
        /** @var array{profile: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Alice', $body['profile']['name']);
    }

    public function testListForUserWithUuidParamsDoesNotTypeError(): void
    {
        $userUuid = 'd9b2d63d-a233-4123-847b-1ff2da0b9a35';

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with($userUuid)
            ->willReturn([['id' => 'prof_1', 'user_id' => $userUuid, 'name' => 'Alice']]);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userUuid)
            ->willReturn(['id' => $userUuid, 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->listForUser($this->makeRequest(), ['userId' => $userUuid]);

        $this->assertLessThan(500, $response->statusCode);
        $this->assertSame(200, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // update()
    // ─────────────────────────────────────────────────────────────────

    public function testUpdateHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('update')
            ->with('1', ['name' => 'Alice Updated']);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->update($this->makeRequest(['name' => 'Alice Updated']), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile updated successfully', $body['message']);
    }

    public function testUpdateNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->update($this->makeRequest(['name' => 'New Name']), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────

    public function testDeleteHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('delete')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->delete($this->makeRequest(), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile deleted successfully', $body['message']);
    }

    public function testDeleteNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->delete($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // setPin()
    // ─────────────────────────────────────────────────────────────────

    public function testSetPinHappyPath4Digit(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('setPin')
            ->with('1', '1234');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => '1234']), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN set successfully', $body['message']);
    }

    public function testSetPinHappyPath6Digit(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('setPin')
            ->with('1', '123456');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => '123456']), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetPinClear(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('removePin')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => null]), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN cleared successfully', $body['message']);
    }

    public function testSetPinInvalidLength(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->never())->method('setPin');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => '12345']), ['id' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid PIN length', $body['error']);
    }

    public function testSetPinNonDigits(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->never())->method('setPin');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => 'abcd']), ['id' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN must contain only digits', $body['error']);
    }

    public function testSetPinNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin($this->makeRequest(['pin' => '1234']), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // deletePin()
    // ─────────────────────────────────────────────────────────────────

    public function testDeletePinHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('removePin')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->deletePin($this->makeRequest(), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN deleted successfully', $body['message']);
    }

    public function testDeletePinNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        // The controller's profile-cap pre-check now reads the EFFECTIVE cap
        // via maxProfiles(). An unstubbed mock returns 0, which would make
        // `count($existing) >= 0` true and 400 every creation — a test-double
        // artifact, not a real state: the real method clamps to >= 1.
        $profileManager->method('maxProfiles')
            ->willReturn(UserProfileManager::MAX_PROFILES_PER_USER);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->deletePin($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }
}
