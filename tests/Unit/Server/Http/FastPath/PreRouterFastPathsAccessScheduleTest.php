<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use DateTimeImmutable;
use Phlix\Access\AccessScheduleService;
use Phlix\Access\StreamSessionService;
use Phlix\Auth\SignedUrl;
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
 *
 * Also pinned here: a signed-URL token bound to a profile (`pid`) is refused outside
 * that profile's window, a stripped or swapped `pid` is refused, and a legacy token
 * without `pid` is unchecked until it expires (the documented bound).
 */
final class PreRouterFastPathsAccessScheduleTest extends TestCase
{
    private const SIGNED_SECRET = 'pre-router-schedule-test-secret';

    private string $mediaPath = '';

    /** @var list<string> Every SQL statement the scripted connection was asked to run. */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->mediaPath = sys_get_temp_dir() . '/phlix-sched-' . bin2hex(random_bytes(6)) . '.mp4';
        file_put_contents($this->mediaPath, 'ABCDEFGHIJKLMNOP');
        $this->queries = [];
        putenv('PHLIX_SIGNED_URL_SECRET=' . self::SIGNED_SECRET);
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
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
     * @param list<array<string, mixed>>  $scheduleRows access_schedules rows the DB holds
     *                                                 (filtered by profile, as the real query is).
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
            function (string $sql, mixed $params = null) use ($scheduleRows): mixed {
                $this->queries[] = $sql;
                if (str_starts_with($sql, 'SELECT * FROM access_schedules')) {
                    // Bound to one profile, exactly as the real statement is.
                    $profileId = is_array($params) ? ($params[0] ?? null) : null;

                    return array_values(array_filter(
                        $scheduleRows,
                        static fn (array $row): bool => $row['profile_id'] === $profileId,
                    ));
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

    /**
     * An authenticated session request. The session profile is published the same way
     * {@see \Phlix\Server\Http\RequestAuthenticator::authenticate()} publishes it, on
     * BOTH the request and RequestContext, because checkStreamLimit() reads the context.
     *
     * @param bool $withDevice When true, a User-Agent is sent so the stream would be
     *                         registered if nothing refused it first.
     */
    private function authenticatedRequest(string $method, ?string $profileId, bool $withDevice = false): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/media/m1/stream';
        $request->userId = 'u1';
        $request->profileId = $profileId;
        RequestContext::setProfileId($profileId);
        if ($withDevice) {
            $request->headers['User-Agent'] = 'phlix-schedule-test/1.0';
        }

        return $request;
    }

    /**
     * A signed direct-play request. The token is minted by the real signer, so the
     * request carries exactly the query a client would send; `$profileId` null mints a
     * legacy token with no `pid`.
     */
    private function signedRequest(?string $profileId): Request
    {
        $minted = SignedUrl::fromEnv()->mint('/media/m1/stream', null, null, $profileId);
        parse_str((string) parse_url($minted, PHP_URL_QUERY), $query);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/media/m1/stream';
        $request->query = $query;

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

    public function test_a_refused_direct_play_takes_no_stream_slot(): void
    {
        // The device header makes the stream slot path reachable. The schedule must
        // refuse before it, so no active_streams row is written for a locked-out profile.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', 'p1', true));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertNotEmpty($this->queries, 'the schedule rows were read');
        foreach ($this->queries as $sql) {
            self::assertStringNotContainsString('INSERT INTO active_streams', $sql);
        }
    }

    public function test_the_session_profile_is_the_one_checked_not_the_account_active_profile(): void
    {
        // The session runs as p1 (locked). The account's active profile is p2 (free).
        // The check must follow the session, so the stream is refused.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p2']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', 'p1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_a_locked_account_profile_does_not_refuse_a_free_session_profile(): void
    {
        // The reverse: the account's active profile p1 is locked, but this session runs
        // as p2 (free), so it streams.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', 'p2'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
    }

    public function test_the_request_context_is_restored_after_the_schedule_check(): void
    {
        // accessScheduleDenial() publishes the verified identity for the middleware and
        // must put back exactly what it found, on the refused path as on the allowed one.
        RequestContext::setUserId('u-prev');
        RequestContext::setProfileId(null);

        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);
        $response = $fastPaths->dispatch($this->authenticatedRequest('GET', null));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode, 'the account profile p1 is locked');
        self::assertSame('u-prev', RequestContext::getUserId());
        self::assertNull(RequestContext::getProfileId());
    }

    public function test_a_signed_token_bound_to_a_locked_profile_is_refused_in_its_window(): void
    {
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest('p1'));

        self::assertNotNull($response);
        self::assertSame(403, $response->statusCode);
        self::assertNull($response->filePath, 'a signed URL cannot stream outside its profile window');
        self::assertSame('access.scheduled', $this->decodedBody($response)['code']);
    }

    public function test_a_signed_token_bound_to_a_free_profile_still_streams(): void
    {
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest('p2'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
        self::assertSame($this->mediaPath, $response->filePath);
    }

    public function test_a_legacy_signed_token_without_a_profile_is_unchecked_until_it_expires(): void
    {
        // Documented bound: a token minted before `pid` existed carries no profile, so
        // there is nothing to schedule-check. It is valid only until its expiry
        // (SignedUrl::DEFAULT_TTL, 21600 s). This pins that it still plays, not that it
        // should.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest(null));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
    }

    public function test_stripping_the_profile_from_a_bound_token_is_refused(): void
    {
        // Removing `pid` must not turn a profile-bound token into an unbound one: the
        // signature was over the profile, so the legacy message no longer verifies.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);
        $request = $this->signedRequest('p1');
        unset($request->query[SignedUrl::PROFILE_PARAM]);

        $response = $fastPaths->dispatch($request);

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertNull($response->filePath);
    }

    public function test_swapping_the_profile_on_a_bound_token_is_refused(): void
    {
        // A token minted for the free profile p2 must not be re-pointed at the locked p1.
        $fastPaths = $this->makeFastPaths($this->lockedOutNow(), ['id' => 'p1']);
        $request = $this->signedRequest('p2');
        $request->query[SignedUrl::PROFILE_PARAM] = 'p1';

        $response = $fastPaths->dispatch($request);

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertNull($response->filePath);
    }
}
