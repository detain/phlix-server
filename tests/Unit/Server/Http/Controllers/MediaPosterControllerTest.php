<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Server\Http\Controllers\MediaPosterController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see MediaPosterController} — Step 15.1/15.2 poster candidate
 * listing and poster selection endpoints.
 *
 * ## S323 — every case here now runs THROUGH the admin gate
 *
 * Before S323 the controller was built with no middleware at all, so
 * `requireAdmin()` waved every authenticated request through on its
 * `if ($this->adminMiddleware !== null)` branch. Every `authedRequest()` case
 * below already sent `userId = 'admin-1'` and asserted a handler outcome, which
 * meant they pinned that fail-open in EFFECT while their names and docblocks only
 * ever described the handler. The gate is now a required constructor dependency
 * and {@see self::makeController()} admits exactly `admin-1`. No assertion in this
 * file changed.
 *
 * The gate's own three arms (anonymous / non-admin / admin) live in
 * {@see MediaPosterControllerAdminGateIsStructuralTest}.
 */
class MediaPosterControllerTest extends TestCase
{
    /**
     * Build the controller with a REAL admin gate that admits exactly `admin-1`.
     *
     * Not a permissive stub: a request from any other user id is refused here
     * exactly as it would be in production.
     */
    private function makeController(
        ItemRepository $items,
        TmdbProvider $tmdb
    ): MediaPosterController {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        return new MediaPosterController(
            $items,
            $tmdb,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authedRequest(array $body = []): Request
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = $body;
        return $request;
    }

    public function testListPostersRequiresAuth(): void
    {
        $controller = $this->makeController(
            $this->createMock(ItemRepository::class),
            $this->createMock(TmdbProvider::class),
        );

        $response = $controller->listPosters(new Request(), ['id' => 'm1']);
        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    public function testListPosters404WhenItemMissing(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testListPostersReturnsStoredImages(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'The Matrix',
            'metadata' => [
                'poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                'external_ids' => ['tmdb' => '603'],
                'images' => [
                    'tmdb' => [
                        'posters' => [
                            [
                                'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                                'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                                'width' => 500,
                                'height' => 750,
                                'language' => 'en',
                            ],
                            [
                                'url' => 'https://image.tmdb.org/t/p/w500/p2.jpg',
                                'url_original' => 'https://image.tmdb.org/t/p/original/p2.jpg',
                                'width' => 500,
                                'height' => 750,
                                'language' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{providers: list<array{provider: mixed, posters: array<mixed>}>, current: mixed} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['providers']);
        $this->assertSame('tmdb', $body['providers'][0]['provider']);
        $this->assertCount(2, $body['providers'][0]['posters']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $body['current']);
    }

    public function testListPostersFetchesFromTmdbWhenNoStoredCandidates(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturnCallback(function (string $id) {
            static $call = 0;
            ++$call;
            if ($call === 1) {
                return [
                    'id' => 'm1',
                    'type' => 'movie',
                    'name' => 'The Matrix',
                    'metadata' => [
                        'external_ids' => ['tmdb' => '603'],
                        'images' => [], // empty — no stored candidates
                    ],
                ];
            }
            // After update, findById returns the item with stored images.
            return [
                'id' => 'm1',
                'type' => 'movie',
                'name' => 'The Matrix',
                'metadata' => [
                    'external_ids' => ['tmdb' => '603'],
                    'images' => [
                        'tmdb' => [
                            'posters' => [
                                [
                                    'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                                    'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                                    'width' => 500,
                                    'height' => 750,
                                    'language' => 'en',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        });

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->expects($this->once())
            ->method('getImages')
            ->with('603')
            ->willReturn([
                'posters' => [
                    [
                        'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                        'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                        'width' => 500,
                        'height' => 750,
                        'language' => 'en',
                    ],
                ],
                'backdrops' => [],
                'logos' => [],
            ]);

        $items->expects($this->once())->method('update')->with('m1', $this->anything());

        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{providers: list<array{provider: mixed, posters: array<mixed>}>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['providers']);
        $this->assertSame('tmdb', $body['providers'][0]['provider']);
        $this->assertCount(1, $body['providers'][0]['posters']);
    }

    public function testListPosters422WhenTmdbUnconfigured(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'X',
            'metadata' => ['external_ids' => ['tmdb' => '603'], 'images' => []],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('getImages')->willThrowException(new TmdbUnconfiguredException('TMDB not configured'));

        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(422, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('metadata.tmdb_unconfigured', $body['code']);
    }

    public function testListPosters502WhenTmdbUnreachable(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'X',
            'metadata' => ['external_ids' => ['tmdb' => '603'], 'images' => []],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('getImages')->willThrowException(new \RuntimeException('network error'));

        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(502, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('metadata.tmdb_unreachable', $body['code']);
    }

    public function testListPostersCapsAt30PerProvider(): void
    {
        $posters = [];
        for ($i = 1; $i <= 35; $i++) {
            $posters[] = [
                'url' => "https://image.tmdb.org/t/p/w500/p{$i}.jpg",
                'url_original' => "https://image.tmdb.org/t/p/original/p{$i}.jpg",
                'width' => 500,
                'height' => 750,
                'language' => 'en',
            ];
        }

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'X',
            'metadata' => [
                'external_ids' => ['tmdb' => '603'],
                'images' => ['tmdb' => ['posters' => $posters]],
            ],
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{providers: list<array{posters: array<mixed>}>} $body */
        $body = json_decode($response->body, true);
        $this->assertCount(30, $body['providers'][0]['posters']);
    }

    public function testListPostersReturnsEmptyProvidersWhenNoExternalId(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'name' => 'X',
            'metadata' => ['images' => []], // no external_ids
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->expects($this->never())->method('getImages');

        $controller = $this->makeController($items, $tmdb);
        $response = $controller->listPosters($this->authedRequest(), ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame([], $body['providers']);
        $this->assertNull($body['current']);
    }

    public function testSetPosterRequiresAuth(): void
    {
        $controller = $this->makeController(
            $this->createMock(ItemRepository::class),
            $this->createMock(TmdbProvider::class),
        );

        $response = $controller->setPoster(new Request(), ['id' => 'm1']);
        $this->assertSame(401, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    public function testSetPoster404WhenItemMissing(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(null);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster($this->authedRequest(['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg']), ['id' => 'missing']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testSetPoster400WhenMissingUrl(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster($this->authedRequest([]), ['id' => 'm1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('poster.missing_url', $body['code']);
    }

    public function testSetPoster400WhenUrlNotCandidate(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'metadata' => [
                'images' => [
                    'tmdb' => [
                        'posters' => [
                            [
                                'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                                'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                                'width' => 500,
                                'height' => 750,
                                'language' => 'en',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster(
            $this->authedRequest(['poster_url' => 'https://evil.example.com/poster.jpg']),
            ['id' => 'm1'],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('poster.poster_not_candidate', $body['code']);
    }

    public function testSetPoster400WhenNoImagesStored(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'type' => 'movie',
            'metadata' => ['images' => []],
        ]);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster(
            $this->authedRequest(['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg']),
            ['id' => 'm1'],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('poster.poster_not_candidate', $body['code']);
    }

    public function testSetPosterSuccessPersistsAndReturnsShapedItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturnCallback(function (string $id) {
            static $call = 0;
            ++$call;
            if ($call === 1) {
                return [
                    'id' => 'm1',
                    'type' => 'movie',
                    'name' => 'The Matrix',
                    'path' => '/media/matrix.mkv',
                    'metadata' => [
                        'poster_url' => 'https://image.tmdb.org/t/p/w500/old.jpg',
                        'images' => [
                            'tmdb' => [
                                'posters' => [
                                    [
                                        'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                                        'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                                        'width' => 500,
                                        'height' => 750,
                                        'language' => 'en',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];
            }
            // After update: item with new poster_url.
            return [
                'id' => 'm1',
                'type' => 'movie',
                'name' => 'The Matrix',
                'path' => '/media/matrix.mkv',
                'metadata' => [
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                    'images' => [
                        'tmdb' => [
                            'posters' => [
                                [
                                    'url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                                    'url_original' => 'https://image.tmdb.org/t/p/original/p.jpg',
                                    'width' => 500,
                                    'height' => 750,
                                    'language' => 'en',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        });
        $items->method('getItemStreams')->willReturn([]);

        $items->expects($this->once())->method('update')->with(
            'm1',
            $this->callback(function (array $data): bool {
                $meta = $data['metadata_json'] ?? [];
                return ($meta['poster_url'] ?? null) === 'https://image.tmdb.org/t/p/w500/p.jpg';
            }),
        );

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster(
            $this->authedRequest(['poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg']),
            ['id' => 'm1'],
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array{item: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('item', $body);
        $this->assertSame('m1', $body['item']['id']);
        $this->assertSame('The Matrix', $body['item']['name']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/p.jpg', $body['item']['poster_url']);
    }

    public function testSetPosterRejectsEmptyStringUrl(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'type' => 'movie', 'metadata' => []]);

        $controller = $this->makeController($items, $this->createMock(TmdbProvider::class));
        $response = $controller->setPoster($this->authedRequest(['poster_url' => '  ']), ['id' => 'm1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('poster.missing_url', $body['code']);
    }
}
