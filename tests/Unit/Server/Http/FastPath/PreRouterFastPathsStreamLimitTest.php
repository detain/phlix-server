<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use Phlix\Access\StreamSessionService;
use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * S301 — the direct-play stream limit (`checkStreamLimit()`, moved verbatim
 * from `HttpHandler` into {@see PreRouterFastPaths}).
 *
 * The `profile_not_found` branch had ZERO test coverage repo-wide before this
 * step — the exact branch that 403'd EVERY relayed stream while only the hub
 * UUID crossed the tunnel. It is now pinned as the honest, named refusal: a
 * session whose user has no resolvable active profile is denied 403 with
 * `denial_type: profile_not_found`, never silently streamed untracked.
 */
final class PreRouterFastPathsStreamLimitTest extends TestCase
{
    private string $mediaPath = '';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->mediaPath = sys_get_temp_dir() . '/phlix-limit-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($this->mediaPath, 'ABCDEFGHIJKLMNOP');
    }

    protected function tearDown(): void
    {
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        SignedUrl::resetSharedForTesting();
    }

    /**
     * @param array<string, mixed>|null $profile      getActiveProfile() result.
     * @param bool|null                 $registerOk   registerStream() result (null = service never consulted).
     */
    private function makeFastPaths(?array $profile, ?bool $registerOk = null): PreRouterFastPaths
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ]);
        $repo->method('effectiveContentRatingsForIds')->willReturn([]);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn($profile);
        $profiles->method('getActiveRatingFilter')->willReturn(null);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($repo, $profiles, $users);

        // StreamSessionService is final — built for real over a scripted
        // Connection (same idiom as ProfileContextPrecedenceTest):
        //  - registerOk null → the service is never reached (no session/device).
        //  - registerOk true  → an already-registered session row (SELECT 1 hit).
        //  - registerOk false → default limit 1 (no profile_stream_limits row)
        //    already exhausted by COUNT 1.
        $streams = null;
        if ($registerOk !== null) {
            $db = $this->createMock(\Workerman\MySQL\Connection::class);
            $db->method('query')->willReturnCallback(
                static function (string $sql) use ($registerOk): mixed {
                    if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                        return $registerOk ? [['1' => '1']] : [];
                    }
                    if (str_starts_with($sql, 'SELECT * FROM profile_stream_limits')) {
                        return [];
                    }
                    if (str_starts_with($sql, 'SELECT COUNT(*) as cnt')) {
                        return $registerOk ? [['cnt' => '0']] : [['cnt' => '1']];
                    }
                    return [];
                }
            );
            $streams = new StreamSessionService($db);
        }

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                RatingGate::class          => $gate,
                UserProfileManager::class  => $profiles,
                StreamSessionService::class => $streams,
                default                    => $repo,
            },
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );
    }

    private function userRequest(string $userId, string $method = 'GET'): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/media/m1/stream';
        $request->userId = $userId;

        return $request;
    }

    /**
     * THE PIN: an authenticated session whose user has NO active profile is
     * refused with the NAMED 403 — this is the branch that 403'd every relayed
     * stream while only the hub UUID crossed the tunnel, and it is the honest
     * refusal for an unmapped principal, not a hole.
     */
    public function testUserWithNoActiveProfileIsRefusedWithNamedProfileNotFound(): void
    {
        $fastPaths = $this->makeFastPaths(null);

        $response = $fastPaths->dispatch($this->userRequest('u1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        /** @var array<int|string, array<mixed>|string> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('profile_not_found', $body['denial_type']);
        self::assertSame('Profile not found; access denied', $body['message']);
    }

    /** A profile row whose id cannot resolve is the same named refusal. */
    public function testProfileWithoutResolvableIdIsRefusedWithNamedProfileNotFound(): void
    {
        $fastPaths = $this->makeFastPaths(['id' => '']);

        $response = $fastPaths->dispatch($this->userRequest('u1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        /** @var array<int|string, array<mixed>|string> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('profile_not_found', $body['denial_type']);
    }

    /** A resolvable profile with no device/session identifiers passes through. */
    public function testMissingDeviceOrSessionSkipsEnforcementAndServes(): void
    {
        $fastPaths = $this->makeFastPaths(['id' => 'p1']);

        $response = $fastPaths->dispatch($this->userRequest('u1'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
    }

    /** The limit branch itself: registerStream() false → 429, named. */
    public function testStreamLimitExceededYields429(): void
    {
        $fastPaths = $this->makeFastPaths(['id' => 'p1'], false);

        $request = $this->userRequest('u1');
        $request->headers['X-Device-Id'] = 'device-1';
        $request->headers['X-Session-Id'] = 'session-1';

        $response = $fastPaths->dispatch($request);

        self::assertNotNull($response);
        self::assertSame(429, $response->statusCode);
        /** @var array<int|string, array<mixed>|string> $body */
        $body = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('stream_limit_exceeded', $body['denial_type']);
        self::assertSame('p1', $body['profile_id']);
    }

    /** Signed-URL access (userId null) skips the limit entirely — the URL is the access control. */
    public function testSignedUrlSkipsTheStreamLimit(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->expects(self::never())->method('getActiveProfile');

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ]);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($repo, $profiles, $users);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                RatingGate::class         => $gate,
                UserProfileManager::class => $profiles,
                default                   => $repo,
            },
        );

        $fastPaths = new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );

        $signed = SignedUrl::fromEnv()->mint('/media/m1/stream');
        $request = new Request();
        $request->method = 'GET';
        $request->path = strtok($signed, '?') ?: '/media/m1/stream';
        $queryString = (string) (strpos($signed, '?') !== false ? substr($signed, strpos($signed, '?') + 1) : '');
        $request->queryString = $queryString;
        $request->query = PreRouterFastPathsMediaStreamTest::parsedQuery($queryString);

        $response = $fastPaths->dispatch($request);

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
    }
}