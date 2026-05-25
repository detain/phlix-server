<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Http\Router;

/**
 * Pins the media-library route layout to the canonical `/api/v1` prefix.
 *
 * The JSON metadata API (auth, media, sessions, collections, libraries,
 * music, books, audiobooks, photos) is uniformly mounted under `/api/v1`.
 * OPDS is the deliberate exception — it follows the OPDS 1.2 spec path
 * (`/opds/v1.2`) and must stay un-prefixed.
 *
 * @covers \Phlix\Server\Http\Router::music
 * @covers \Phlix\Server\Http\Router::books
 * @covers \Phlix\Server\Http\Router::audiobooks
 * @covers \Phlix\Server\Http\Router::photo
 * @covers \Phlix\Server\Http\Router::opds
 */
final class RouterMediaRoutesTest extends TestCase
{
    private const CONTROLLER = 'App\\Fake\\Controller';

    /**
     * @return list<string>
     */
    private function paths(Router $router, string $method): array
    {
        $routes = $router->getRoutes();
        return array_values(array_map(
            static fn (array $entry): string => $entry['path'],
            $routes[$method] ?? [],
        ));
    }

    public function testMusicRoutesArePrefixed(): void
    {
        $get = $this->paths((new Router())->music(self::CONTROLLER), 'GET');

        self::assertContains('/api/v1/music/artists', $get);
        self::assertContains('/api/v1/music/artists/{mbid}', $get);
        self::assertContains('/api/v1/music/albums', $get);
        self::assertContains('/api/v1/music/albums/{mbid}', $get);
        self::assertContains('/api/v1/music/tracks', $get);
        self::assertContains('/api/v1/music/tracks/{id}', $get);
        self::assertContains('/api/v1/music/now-playing', $get);

        self::assertNotContains('/music/artists', $get);
    }

    public function testBookRoutesArePrefixed(): void
    {
        $get = $this->paths((new Router())->books(self::CONTROLLER), 'GET');

        self::assertContains('/api/v1/books', $get);
        self::assertContains('/api/v1/books/{id}', $get);
        self::assertContains('/api/v1/books/{id}/cover', $get);
        self::assertContains('/api/v1/books/{id}/read', $get);
        self::assertContains('/api/v1/books/{id}/download', $get);

        self::assertNotContains('/books', $get);
    }

    public function testAudiobookRoutesArePrefixed(): void
    {
        $router = (new Router())->audiobooks(self::CONTROLLER);
        $get = $this->paths($router, 'GET');
        $post = $this->paths($router, 'POST');

        self::assertContains('/api/v1/audiobooks', $get);
        self::assertContains('/api/v1/audiobooks/{id}', $get);
        self::assertContains('/api/v1/audiobooks/{id}/chapters', $get);
        self::assertContains('/api/v1/audiobooks/{id}/progress', $get);
        self::assertContains('/api/v1/audiobooks/{id}/read', $get);
        self::assertContains('/api/v1/audiobooks/{id}/stream', $get);
        self::assertContains('/api/v1/audiobooks/{id}/progress', $post);

        self::assertNotContains('/audiobooks', $get);
    }

    public function testPhotoRoutesArePrefixed(): void
    {
        $get = $this->paths((new Router())->photo(self::CONTROLLER), 'GET');

        self::assertContains('/api/v1/photo/albums', $get);
        self::assertContains('/api/v1/photo/albums/{id}', $get);
        self::assertContains('/api/v1/photo/photos', $get);
        self::assertContains('/api/v1/photo/photos/{id}', $get);
        self::assertContains('/api/v1/photo/photos/{id}/thumbnail', $get);
        self::assertContains('/api/v1/photo/photos/{id}/full', $get);
        self::assertContains('/api/v1/photo/slideshow', $get);

        self::assertNotContains('/photo/albums', $get);
    }

    public function testOpdsRoutesStayUnprefixed(): void
    {
        $get = $this->paths((new Router())->opds(self::CONTROLLER), 'GET');

        self::assertContains('/opds/v1.2', $get);
        self::assertContains('/opds/v1.2/libraries', $get);
        self::assertContains('/opds/v1.2/libraries/{id}', $get);
        self::assertContains('/opds/v1.2/books/{id}/cover', $get);

        self::assertNotContains('/api/v1/opds/v1.2', $get);
    }
}
