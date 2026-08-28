<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

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
 * S301 — the direct-play parental ACCESS gate, moved verbatim from
 * `HttpHandler::serveMediaStream()` (Finding 1) into {@see PreRouterFastPaths}.
 *
 * A capped session requesting an over-cap item is denied with a 404 (existence
 * not confirmed, no bytes); a within-cap item and the owner path proceed. Over
 * the relay this is what finally FIRES for a mapped hub user: the hub UUID
 * resolves to a server user (RelayIdentityResolver), and the gate resolves the
 * filter from THAT user's own rows.
 */
final class PreRouterFastPathsRatingGateTest extends TestCase
{
    private string $mediaPath = '';

    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return [
            'allowedRatings' => ['G', 'PG', 'PG-13', 'TV-14'],
            'allowUnrated' => true,
        ];
    }

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->mediaPath = sys_get_temp_dir() . '/phlix-gate-' . bin2hex(random_bytes(6)) . '.mp4';
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
     * @param array<string, mixed>|null $item
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param array<string, string|null> $effective id => effective rating stub
     */
    private function makeFastPaths(
        ?array $item,
        ?array $filter,
        bool $isAdmin = false,
        array $effective = []
    ): PreRouterFastPaths {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->method('effectiveContentRatingsForIds')->willReturnCallback(
            static function (array $ids) use ($effective): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $effective[$id] ?? null;
                }
                return $out;
            }
        );

        $pm = $this->createMock(UserProfileManager::class);
        // For checkStreamLimit: a resolvable active profile so the limit check
        // passes through (no device/session headers → enforcement skipped).
        $pm->method('getActiveProfile')->willReturn(['id' => 'p1']);
        $pm->method('getActiveRatingFilter')->willReturn($filter);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        $gate = new RatingGate($repo, $pm, $users);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $class) use ($repo, $pm, $gate) {
                return match ($class) {
                    RatingGate::class => $gate,
                    UserProfileManager::class => $pm,
                    default => $repo,
                };
            }
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );
    }

    private function request(string $id, string $userId): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/media/' . $id . '/stream';
        $request->userId = $userId;

        return $request;
    }

    public function testCappedSessionDeniedOverCapItem(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'R', 'path' => $this->mediaPath],
            $this->pg13Filter()
        );

        $resp = $fastPaths->dispatch($this->request('m1', 'u1'));

        self::assertNotNull($resp);
        self::assertSame(404, $resp->statusCode);
        self::assertSame('Media not found', $resp->body);
    }

    public function testCappedSessionAllowedWithinCapItem(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'PG', 'path' => $this->mediaPath],
            $this->pg13Filter()
        );

        $resp = $fastPaths->dispatch($this->request('m1', 'u1'));

        self::assertNotNull($resp);
        self::assertSame(200, $resp->statusCode);
    }

    public function testCappedSessionDeniedEpisodeOfBlockedSeries(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['id' => 'ep1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            false,
            ['show-1' => 'R']
        );

        $resp = $fastPaths->dispatch($this->request('ep1', 'u1'));
        self::assertSame(404, $resp?->statusCode);
    }

    public function testCappedSessionAllowedEpisodeOfAllowedSeries(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['id' => 'ep1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            false,
            ['show-1' => 'PG']
        );

        $resp = $fastPaths->dispatch($this->request('ep1', 'u1'));
        self::assertSame(200, $resp?->statusCode);
    }

    public function testOwnerAdminNotGated(): void
    {
        $fastPaths = $this->makeFastPaths(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'NC-17', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            true
        );

        $resp = $fastPaths->dispatch($this->request('m1', 'u1'));
        self::assertSame(200, $resp?->statusCode);
    }
}