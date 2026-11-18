<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

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
use ReflectionProperty;

/**
 * Item B — the direct-play ALLOW memo in {@see PreRouterFastPaths}: denials are never
 * stored, an ALLOW verdict expires after STREAM_ACCESS_TTL_NS (driven by the injected
 * clock seam), and the memo never grows past STREAM_ACCESS_MEMO_MAX_ENTRIES.
 */
final class PreRouterFastPathsStreamMemoTest extends TestCase
{
    private string $mediaPath = '';

    /** @var list<string> Ids passed to ItemRepository::findById(), in call order. */
    private array $lookups = [];

    /** The account's active profile as the profile manager reports it (tests switch it). */
    private string $activeProfileId = 'p1';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->lookups = [];
        $this->activeProfileId = 'p1';
        $this->mediaPath = sys_get_temp_dir() . '/phlix-memo-' . bin2hex(random_bytes(6)) . '.mp4';
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
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param string|null $contentRating rating stamped on every item served by the repo
     * @param array<string, array{allowedRatings: list<string>, allowUnrated: bool}|null>|null $filtersByProfile
     *        when given, the rating filter per profile id (overrides $filter)
     */
    private function makeFastPaths(
        ?array $filter,
        ?string $contentRating,
        ?\Closure $clock = null,
        ?array $filtersByProfile = null,
    ): PreRouterFastPaths {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturnCallback(
            function (string $id) use ($contentRating): array {
                $this->lookups[] = $id;
                return [
                    'id' => $id,
                    'type' => 'movie',
                    'content_rating' => $contentRating,
                    'path' => $this->mediaPath,
                ];
            }
        );
        $repo->method('effectiveContentRatingsForIds')->willReturnCallback(
            static fn (array $ids): array => array_fill_keys($ids, $contentRating)
        );

        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveProfile')->willReturnCallback(
            fn (string $userId): array => ['id' => $this->activeProfileId]
        );
        $pm->method('getActiveRatingFilter')->willReturnCallback(
            function (string $userId, ?string $profileId = null) use ($filter, $filtersByProfile): ?array {
                if ($filtersByProfile === null) {
                    return $filter;
                }

                return $filtersByProfile[$profileId ?? $this->activeProfileId] ?? null;
            }
        );

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($repo, $pm, $users);

        $noSchedule = $this->createMock(\Workerman\MySQL\Connection::class);
        $noSchedule->method('query')->willReturn([]);
        $unscheduled = new \Phlix\Server\Http\Middleware\AccessScheduleMiddleware(
            new \Phlix\Access\AccessScheduleService($noSchedule),
            $pm,
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $class) use ($repo, $pm, $gate, $unscheduled) {
                return match ($class) {
                    RatingGate::class => $gate,
                    UserProfileManager::class => $pm,
                    \Phlix\Server\Http\Middleware\AccessScheduleMiddleware::class => $unscheduled,
                    default => $repo,
                };
            }
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
            $clock,
        );
    }

    private function request(string $id): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/media/' . $id . '/stream';
        $request->userId = 'u1';

        return $request;
    }

    /** A capped profile (PG-13 ceiling) requesting an R-rated item is denied every time. */
    public function testDenialIsNeverMemoisedSoEachRequestIsReEvaluated(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['allowedRatings' => ['G', 'PG', 'PG-13'], 'allowUnrated' => true],
            'R',
        );

        $first = $fastPaths->dispatch($this->request('m1'));
        $second = $fastPaths->dispatch($this->request('m1'));

        self::assertSame(404, $first?->statusCode);
        self::assertSame(404, $second?->statusCode);
        self::assertSame(['m1', 'm1'], $this->lookups, 'a denied request must hit findById() again');
    }

    /** An ALLOW verdict is served from the memo until the TTL elapses, then re-checked. */
    public function testAllowVerdictExpiresAfterItsTtl(): void
    {
        $now = 0;
        $fastPaths = $this->makeFastPaths(null, null, static function () use (&$now): int {
            return $now;
        });

        self::assertSame(200, $fastPaths->dispatch($this->request('m1'))?->statusCode);
        self::assertSame(['m1'], $this->lookups);

        $now = 9_999_999_999; // 9.999... s after the verdict: still inside the 10 s TTL
        self::assertSame(200, $fastPaths->dispatch($this->request('m1'))?->statusCode);
        self::assertSame(['m1'], $this->lookups, 'a fresh ALLOW must be served from the memo');

        $now = 10_000_000_000; // exactly the TTL: expired, so findById() runs again
        self::assertSame(200, $fastPaths->dispatch($this->request('m1'))?->statusCode);
        self::assertSame(['m1', 'm1'], $this->lookups, 'an expired ALLOW must be re-verified');
    }

    /**
     * Finding 1: the request context carries no profile, so the stream runs as the account's
     * active profile. The uncapped profile pB is served and its ALLOW memoised. The account then
     * switches its active profile to pA (capped at PG-13) inside the TTL. The R-rated item must be
     * re-evaluated and denied, not served from pB's cached ALLOW.
     */
    public function testActiveProfileSwitchWithinTtlIsNotServedAnotherProfilesAllow(): void
    {
        $now = 0;
        $fastPaths = $this->makeFastPaths(
            null,
            'R',
            static function () use (&$now): int {
                return $now;
            },
            [
                'pA' => ['allowedRatings' => ['G', 'PG', 'PG-13'], 'allowUnrated' => true],
                'pB' => null,
            ],
        );

        $this->activeProfileId = 'pB';
        self::assertSame(200, $fastPaths->dispatch($this->request('m1'))?->statusCode);

        $this->activeProfileId = 'pA';
        $now = 5_000_000_000; // inside the 10 s TTL: a same-profile hit would be served from the memo
        self::assertSame(
            404,
            $fastPaths->dispatch($this->request('m1'))?->statusCode,
            'the capped profile must not inherit the uncapped profile\'s cached ALLOW',
        );
        self::assertSame(['m1', 'm1'], $this->lookups, 'the switched profile must re-evaluate the item');
    }

    /** The memo is bounded at 2048 entries and evicts the oldest verdict first. */
    public function testMemoStaysWithinItsCapAndEvictsTheOldestEntry(): void
    {
        $fastPaths = $this->makeFastPaths(null, null);

        for ($i = 0; $i < 2049; $i++) {
            $fastPaths->dispatch($this->request('m' . $i));
        }

        $memo = new ReflectionProperty(PreRouterFastPaths::class, 'streamAccessMemo');
        /** @var array<string, mixed> $entries */
        $entries = $memo->getValue($fastPaths);
        self::assertCount(2048, $entries, 'the memo must not grow past its cap');

        $this->lookups = [];
        $fastPaths->dispatch($this->request('m2048')); // newest: still memoised
        self::assertSame([], $this->lookups);

        $fastPaths->dispatch($this->request('m0')); // oldest: evicted, so it is looked up again
        self::assertSame(['m0'], $this->lookups);
    }
}
