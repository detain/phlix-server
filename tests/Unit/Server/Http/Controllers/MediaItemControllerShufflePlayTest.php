<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Tests for MediaItemController::shufflePlay().
 *
 * @covers \Phlix\Server\Http\Controllers\MediaItemController::shufflePlay
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
}
