<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Finding 1: the direct-play byte-serving route enforces the active profile's
 * parental cap BEFORE serving. A capped session requesting an over-cap item is
 * denied with a 404 (existence not confirmed, no bytes); a within-cap item and
 * the owner path proceed.
 */
final class HttpHandlerStreamRatingGateTest extends TestCase
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
    private function makeHandler(
        ?array $item,
        ?array $filter,
        bool $isAdmin = false,
        array $effective = []
    ): HttpHandler {
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

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            sys_get_temp_dir(),
            $this->createMock(Application::class),
            null,
        );
    }

    private function invokeAsUser(HttpHandler $handler, string $id, string $userId): ?WorkermanResponse
    {
        $wr = new WorkermanRequest("GET /media/{$id}/stream HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $m = new \ReflectionMethod(HttpHandler::class, 'serveMediaStream');
        $m->setAccessible(true);
        /** @var WorkermanResponse|null $result */
        $result = $m->invoke($handler, $wr, $userId);
        return $result;
    }

    public function testCappedSessionDeniedOverCapItem(): void
    {
        $handler = $this->makeHandler(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'R', 'path' => $this->mediaPath],
            $this->pg13Filter()
        );

        $resp = $this->invokeAsUser($handler, 'm1', 'u1');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(404, $resp->getStatusCode());
        self::assertSame('Media not found', $resp->rawBody());
    }

    public function testCappedSessionAllowedWithinCapItem(): void
    {
        $handler = $this->makeHandler(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'PG', 'path' => $this->mediaPath],
            $this->pg13Filter()
        );

        $resp = $this->invokeAsUser($handler, 'm1', 'u1');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testCappedSessionDeniedEpisodeOfBlockedSeries(): void
    {
        $handler = $this->makeHandler(
            ['id' => 'ep1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            false,
            ['show-1' => 'R']
        );

        $resp = $this->invokeAsUser($handler, 'ep1', 'u1');
        self::assertSame(404, $resp?->getStatusCode());
    }

    public function testCappedSessionAllowedEpisodeOfAllowedSeries(): void
    {
        $handler = $this->makeHandler(
            ['id' => 'ep1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            false,
            ['show-1' => 'PG']
        );

        $resp = $this->invokeAsUser($handler, 'ep1', 'u1');
        self::assertSame(200, $resp?->getStatusCode());
    }

    public function testOwnerAdminNotGated(): void
    {
        $handler = $this->makeHandler(
            ['id' => 'm1', 'type' => 'movie', 'content_rating' => 'NC-17', 'path' => $this->mediaPath],
            $this->pg13Filter(),
            true
        );

        $resp = $this->invokeAsUser($handler, 'm1', 'u1');
        self::assertSame(200, $resp?->getStatusCode());
    }
}
