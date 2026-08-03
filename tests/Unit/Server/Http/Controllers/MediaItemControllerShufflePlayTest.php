<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Tests for MediaItemController::shufflePlay().
 *
 */
class MediaItemControllerShufflePlayTest extends TestCase
{
    /**
     * Every leaf member of the `media_items.type` ENUM — an item of any of
     * these types with no children is itself the thing to play.
     *
     * @return array<string, array{string}>
     */
    public static function playableLeafTypeProvider(): array
    {
        return [
            'movie' => ['movie'],
            'episode' => ['episode'],
            'video' => ['video'],
            'audio' => ['audio'],
            'track' => ['track'],
            'book' => ['book'],
            'photo' => ['photo'],
            'audiobook' => ['audiobook'],
        ];
    }

    /**
     * Pure grouping types. A childless container has genuinely nothing to play.
     *
     * ⚠ `album` / `artist` reach the 404 by a DIFFERENT route since S97: they
     * never consult `findByParent()` at all (music carries no `parent_id`), they
     * ask `music_*` — and with no {@see MusicLibraryService} wired, as here, that
     * is unanswerable, so the pre-S97 404 is preserved rather than an unplayable
     * container id being returned. The wired behaviour is pinned below.
     *
     * @return array<string, array{string}>
     */
    public static function containerTypeProvider(): array
    {
        return [
            'series' => ['series'],
            'season' => ['season'],
            'music' => ['music'],
            'album' => ['album'],
            'artist' => ['artist'],
        ];
    }

    /**
     * Build a controller whose repository returns a single item of $type with
     * the given children.
     *
     * @param array<int, array<string, mixed>> $children
     */
    private function controllerFor(string $type, array $children = []): MediaItemController
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($type, $children): array {
                if (str_contains($sql, 'parent_id = ?')) {
                    return $children;
                }

                return [[
                    'id' => 'item-1',
                    'name' => 'Test Item',
                    'type' => $type,
                    'library_id' => 'lib-1',
                    'parent_id' => null,
                    'path' => '/test/file',
                    'metadata_json' => json_encode([]),
                ]];
            }
        );

        $itemRepo = new ItemRepository($db);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        return new MediaItemController(
            $itemRepo,
            new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo)),
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($db)
        );
    }

    private function requestFor(string $mediaId): Request
    {
        $request = new Request();
        $request->body = ['media_id' => $mediaId];

        return $request;
    }

    /**
     * @dataProvider playableLeafTypeProvider
     */
    public function testChildlessLeafTypePlaysAsSingle(string $type): void
    {
        $response = $this->controllerFor($type)->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(
            200,
            $response->statusCode,
            sprintf('A childless "%s" is playable on its own and must not 404.', $type)
        );

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('single', $body['mode']);
        $this->assertSame(['item-1'], $body['shuffled_ids']);
    }

    /**
     * @dataProvider containerTypeProvider
     */
    public function testChildlessContainerTypeReturns404(string $type): void
    {
        $response = $this->controllerFor($type)->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(
            404,
            $response->statusCode,
            sprintf('A childless "%s" container has nothing to play.', $type)
        );

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('No playable items found', $body['error']);
    }

    public function testItemWithChildrenShufflesThem(): void
    {
        $children = [
            ['id' => 'ep-1', 'name' => 'Episode 1', 'type' => 'episode', 'metadata_json' => json_encode([])],
            ['id' => 'ep-2', 'name' => 'Episode 2', 'type' => 'episode', 'metadata_json' => json_encode([])],
            ['id' => 'ep-3', 'name' => 'Episode 3', 'type' => 'episode', 'metadata_json' => json_encode([])],
        ];

        $response = $this->controllerFor('season', $children)
            ->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('shuffle', $body['mode']);
        $this->assertIsArray($body['shuffled_ids']);
        $this->assertCount(3, $body['shuffled_ids']);

        $ids = $body['shuffled_ids'];
        sort($ids);
        $this->assertSame(['ep-1', 'ep-2', 'ep-3'], $ids);
    }

    public function testMissingMediaIdReturns400(): void
    {
        $response = $this->controllerFor('movie')->shufflePlay(new Request(), []);

        $this->assertSame(400, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('media_id is required', $body['error']);
    }

    public function testUnknownItemReturns404(): void
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        $controller = new MediaItemController(
            $itemRepo,
            new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo)),
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($db)
        );

        $response = $controller->shufflePlay($this->requestFor('nope'), []);

        $this->assertSame(404, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Item not found', $body['error']);
    }

    /**
     * A row whose type is somehow absent/blank is treated as non-playable
     * rather than being handed back as a bogus single.
     */
    public function testBlankTypeReturns404(): void
    {
        $response = $this->controllerFor('')->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(404, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // S97 — music containers resolve to playable TRACK ids via `music_*`.
    // -----------------------------------------------------------------------

    /**
     * Build a controller for a music container of `$type` whose `music_*` reader
     * answers `$trackIds`, and whose `findByIds()` returns a row per id.
     *
     * @param list<string> $trackIds
     */
    private function musicControllerFor(string $type, array $trackIds): MediaItemController
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($type, $trackIds): array {
                if (str_contains($sql, 'WHERE id IN (')) {
                    return array_map(static fn (string $id): array => [
                        'id' => $id,
                        'name' => 'Track ' . $id,
                        'type' => 'track',
                        'library_id' => 'lib-1',
                        'parent_id' => null,
                        'path' => '/music/' . $id . '.mp3',
                        'metadata_json' => json_encode([]),
                    ], $trackIds);
                }
                if (str_contains($sql, 'parent_id = ?')) {
                    return [];
                }

                return [[
                    'id' => 'item-1',
                    'name' => 'Test Container',
                    'type' => $type,
                    'library_id' => 'lib-1',
                    'parent_id' => null,
                    'path' => '',
                    'metadata_json' => json_encode([]),
                ]];
            }
        );

        $itemRepo = new ItemRepository($db);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getTrackMediaItemIdsForAlbum')->willReturn($trackIds);
        $music->method('getTrackMediaItemIdsForArtist')->willReturn($trackIds);

        return new MediaItemController(
            $itemRepo,
            new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo)),
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($db),
            null,
            null,
            $music
        );
    }

    /**
     * Shuffling an ALBUM must return its TRACK ids — the ids `/media/{id}/stream`
     * can actually serve. Before S97 this 404'd, and the `parent_id`-hierarchy
     * alternative would have returned the album's own (unplayable) id.
     */
    public function testShufflingAnAlbumReturnsItsTrackIds(): void
    {
        $response = $this->musicControllerFor('album', ['tr-1', 'tr-2', 'tr-3'])
            ->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('shuffle', $body['mode']);
        $ids = $body['shuffled_ids'];
        $this->assertIsArray($ids);
        sort($ids);
        $this->assertSame(['tr-1', 'tr-2', 'tr-3'], $ids);
    }

    /**
     * Shuffling an ARTIST spans every album — one indexed read of the
     * denormalized `music_tracks.artist_id`, not a walk of the album list.
     */
    public function testShufflingAnArtistReturnsEveryTrackId(): void
    {
        $response = $this->musicControllerFor('artist', ['tr-1', 'tr-2'])
            ->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $ids = $body['shuffled_ids'];
        $this->assertIsArray($ids);
        sort($ids);
        $this->assertSame(['tr-1', 'tr-2'], $ids);
    }

    /**
     * An album the music tables know nothing about (every track lost, or an
     * orphaned `media_items` mirror row) still 404s rather than returning its own
     * unplayable id.
     */
    public function testShufflingAnAlbumWithNoTracksReturns404(): void
    {
        $response = $this->musicControllerFor('album', [])
            ->shufflePlay($this->requestFor('item-1'), []);

        $this->assertSame(404, $response->statusCode);
    }
}
