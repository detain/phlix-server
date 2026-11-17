<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use DateTimeImmutable;
use Phlix\Access\AccessScheduleService;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Middleware\AccessScheduleMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

/**
 * #13a — the direct-play fast path (`GET|HEAD /media/{id}/stream`) is in no route
 * table, so the router's global AccessScheduleMiddleware never ran for it. A
 * schedule-restricted profile could stream outside its allowed hours. These cases
 * pin that the fast path now applies the SAME middleware, on GET and HEAD, and
 * fails closed for a session with no resolvable profile.
 */
final class PreRouterFastPathsAccessScheduleTest extends TestCase
{
    private string $mediaPath = '';

    protected function setUp(): void
    {
        $this->mediaPath = sys_get_temp_dir() . '/phlix-sched-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($this->mediaPath, 'ABCDEFGHIJKLMNOP');
    }

    protected function tearDown(): void
    {
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
    }

    private static function dayAbbrev(DateTimeImmutable $when): string
    {
        return strtolower(substr($when->format('D'), 0, 3));
    }

    /**
     * A schedule window covering the whole of TODAY, so the profile is locked out now.
     *
     * @return list<array<string, mixed>>
     */
    private function lockedOutNow(): array
    {
        return [[
            'id' => 1,
            'profile_id' => 'p1',
            'name' => 'Bedtime',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => self::dayAbbrev(new DateTimeImmutable()),
            'is_active' => 1,
        ]];
    }

    /**
     * A schedule window on a day that is NOT today, so the profile may stream now.
     *
     * @return list<array<string, mixed>>
     */
    private function lockedOutOnAnotherDay(): array
    {
        $today = self::dayAbbrev(new DateTimeImmutable());
        $other = $today === 'mon' ? 'tue' : 'mon';

        return [[
            'id' => 2,
            'profile_id' => 'p1',
            'name' => 'Elsewhere',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => $other,
            'is_active' => 1,
        ]];
    }

    /**
     * @param list<array<string, mixed>>  $scheduleRows access_schedules rows the DB returns.
     * @param array<string, mixed>|null   $profile      UserProfileManager::getActiveProfile() result.
     */
    private function makeFastPaths(array $scheduleRows, ?array $profile): PreRouterFastPaths
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

        // Both schedule and stream-limit services are final: build them for real
        // over one scripted Connection. No schedule rows for the stream-limit
        // reads means a free slot, so any refusal here is the schedule's alone.
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($scheduleRows): mixed {
                if (str_starts_with($sql, 'SELECT * FROM access_schedules')) {
                    return $scheduleRows;
                }
                if (str_starts_with($sql, 'SELECT 1 FROM active_streams')) {
                    return [];
                }
                if (str_starts_with($sql, 'SELECT * FROM profile_stream_limits')) {
                    return [];
                }
                if (str_starts_with($sql, 'SELECT COUNT(*) as cnt')) {
                    return [['cnt' => '0']];
                }
                return 1;
            }
        );

        $schedule = new AccessScheduleMiddleware(new AccessScheduleService($db), $profiles);
        $streams = new StreamSessionService($db);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                RatingGate::class => $gate,
                UserProfileManager::class => $profiles,
                AccessScheduleMiddleware::class => $schedule,
                StreamSessionService::class => $streams,
                default => $repo,
            },
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );
    }

    private function authenticatedRequest(string $method, ?string $profileId): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/media/m1/stream';
        $request->userId = 'u1';
        $request->profileId = $profileId;
        // No device header and no User-Agent: the stream-limit check passes this
        // through without registering a session, so the cases below exercise the
        // schedule alone (a registered session would arm a Workerman timer, which
        // needs a running worker).

        return $request;
    }

    /** @return array<string, mixed> */
    private function decodedBody(\Phlix\Server\Http\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function test_schedule_window_refuses_an_authenticated_direct_play_get(): void
    {
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', 'p1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertNull($response->filePath, 'no bytes may be streamed to a locked-out profile');
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_schedule_window_refuses_the_direct_play_head_probe_too(): void
    {
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('HEAD', 'p1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertNull($response->filePath);
    }

    public function test_a_schedule_on_another_day_still_allows_direct_play(): void
    {
        $fastPaths = $this->makeFastPaths($this->lockedOutOnAnotherDay(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', 'p1'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
        self::assertSame($this->mediaPath, $response->filePath);
    }

    public function test_an_authenticated_session_with_no_resolvable_profile_is_refused(): void
    {
        // No session profile and no active account profile: the stream's named
        // profile.not_found refusal still answers first (unchanged for clients), so
        // the session never reaches the schedule check, never streams and never
        // takes a slot.
        $fastPaths = $this->makeFastPaths([], null);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', null));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertNull($response->filePath);
        self::assertSame('profile.not_found', $this->decodedBody($response)['code']);
    }
}
