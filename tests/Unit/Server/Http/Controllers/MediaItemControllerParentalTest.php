<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Parental-control ACCESS gate coverage for MediaItemController: show(),
 * getDownload(), getPlaybackInfo() deny over-cap items (404, no signed URL);
 * the S97 music shuffle path honours the cap on the tracks it resolves from
 * `music_*`; the owner is never gated.
 */
class MediaItemControllerParentalTest extends TestCase
{
    /**
     * @return array{allowedRatings: list<string>, allowUnrated: bool}
     */
    private function pg13Filter(): array
    {
        return [
            'allowedRatings' => ['G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG', 'PG-13', 'TV-14'],
            'allowUnrated' => true,
        ];
    }

    /**
     * Mock Connection answering the media-item id SELECT from a fixture, plus a
     * findByParent SELECT for children; everything else returns [].
     *
     * @param array<int, array<string, mixed>> $byId       Rows keyed by returned order for id lookup.
     * @param array<int, array<string, mixed>> $children   Rows returned for parent lookups.
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(array $byId, array $children = [])
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($byId, $children): array {
                if (str_contains($sql, 'WHERE parent_id = ?')) {
                    return $children;
                }
                // S97: the music shuffle path resolves track ids through
                // `music_*` and then batch-loads the rows by id.
                if (str_contains($sql, 'WHERE id IN (')) {
                    return $children;
                }
                if (str_contains($sql, 'FROM media_items WHERE id = ?')) {
                    return $byId;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     * @param array<string, string|null> $effective id => effective rating stub
     */
    private function gate(?array $filter, bool $isAdmin = false, array $effective = []): RatingGate
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('effectiveContentRatingsForIds')->willReturnCallback(
            static function (array $ids) use ($effective): array {
                $out = [];
                foreach ($ids as $id) {
                    $out[$id] = $effective[$id] ?? null;
                }
                return $out;
            }
        );

        $pm = $this->createMock(UserProfileManager::class);
        $pm->method('getActiveRatingFilter')->willReturn($filter);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => $isAdmin ? 1 : 0]);

        return new RatingGate($items, $pm, $users);
    }

    private function controller(
        ItemRepository $repo,
        RatingGate $gate,
        ?MusicLibraryService $music = null
    ): MediaItemController {
        $candidateRepo = new MarkerCandidateRepository($repo);
        $markerService = new MarkerService($repo, $candidateRepo);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        return new MediaItemController(
            $repo,
            $markerService,
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($this->createMock(Connection::class)),
            null,
            $gate,
            $music
        );
    }

    /**
     * A {@see MusicLibraryService} double whose album lookup answers `$trackIds`.
     *
     * @param list<string> $trackIds
     */
    private function musicWithAlbumTracks(array $trackIds): MusicLibraryService
    {
        $music = $this->createMock(MusicLibraryService::class);
        $music->method('getTrackMediaItemIdsForAlbum')->willReturn($trackIds);
        $music->method('getTrackMediaItemIdsForArtist')->willReturn($trackIds);

        return $music;
    }

    /**
     * Decode a shuffle response body's `shuffled_ids` array.
     *
     * @return list<mixed>
     */
    private function shuffledIdsOf(\Phlix\Server\Http\Response $resp): array
    {
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $ids = $body['shuffled_ids'] ?? null;
        $this->assertIsArray($ids);
        return array_values($ids);
    }

    /**
     * A shuffle request body for `$mediaId`.
     */
    private function shuffleRequest(string $mediaId): Request
    {
        $req = $this->cappedRequest();
        $req->body = ['media_id' => $mediaId];
        return $req;
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    public function testShowBlocksOverCapItem(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'R', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

        $resp = $controller->show($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
        $this->assertStringNotContainsString('stream_url', $resp->body);
    }

    public function testShowAllowsWithinCapItem(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Family', 'type' => 'movie',
            'content_rating' => 'PG', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

        $resp = $controller->show($this->cappedRequest(), ['id' => 'm1']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testShowAllowsOverCapForOwnerAdmin(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'NC-17', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        // Admin owner → resolveFilterForUser returns null → no gate.
        $controller = $this->controller($repo, $this->gate($this->pg13Filter(), true));

        $resp = $controller->show($this->cappedRequest(), ['id' => 'm1']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testShowAllowsWhenNoProfileContext(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'NC-17', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate(null));

        $resp = $controller->show($this->cappedRequest(), ['id' => 'm1']);
        $this->assertSame(200, $resp->statusCode);
    }

    public function testGetDownloadBlocksOverCapItem(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'R', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

        $resp = $controller->getDownload($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
        // No signed URL is disclosed in the 404 body.
        $this->assertStringNotContainsString('/media/', $resp->body);
    }

    public function testGetPlaybackInfoBlocksOverCapItem(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'R', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

        $resp = $controller->getPlaybackInfo($this->cappedRequest(), ['id' => 'm1']);
        $this->assertSame(404, $resp->statusCode);
    }

    /**
     * S97 — the music shuffle path resolves tracks through `music_*` instead of
     * `findByParent()`, so the parental cap has to be re-applied there. Without it
     * the new path is a hole in the cap: a capped profile could reach an over-cap
     * track simply by shuffling its album.
     *
     * (This replaces three tests of `MediaItemController::children()`, which S97
     * deleted — it was never registered on any route.)
     */
    public function testMusicShuffleDropsOverCapTracks(): void
    {
        $tracks = [
            ['id' => 'tr-1', 'name' => 'Clean', 'type' => 'track', 'content_rating' => 'PG',
                'parent_id' => null, 'metadata_json' => '{}'],
            ['id' => 'tr-2', 'name' => 'Explicit', 'type' => 'track', 'content_rating' => 'R',
                'parent_id' => null, 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection(
            [['id' => 'al-1', 'name' => 'An Album', 'type' => 'album', 'metadata_json' => '{}', 'path' => '']],
            $tracks
        ));
        $controller = $this->controller(
            $repo,
            $this->gate($this->pg13Filter()),
            $this->musicWithAlbumTracks(['tr-1', 'tr-2'])
        );

        $resp = $controller->shufflePlay($this->shuffleRequest('al-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $this->assertSame(['tr-1'], $this->shuffledIdsOf($resp));
    }

    public function testMusicShuffleIsUnfilteredForOwner(): void
    {
        $tracks = [
            ['id' => 'tr-1', 'name' => 'Explicit', 'type' => 'track', 'content_rating' => 'R',
                'parent_id' => null, 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection(
            [['id' => 'ar-1', 'name' => 'An Artist', 'type' => 'artist', 'metadata_json' => '{}', 'path' => '']],
            $tracks
        ));
        $controller = $this->controller(
            $repo,
            $this->gate($this->pg13Filter(), true),
            $this->musicWithAlbumTracks(['tr-1'])
        );

        $resp = $controller->shufflePlay($this->shuffleRequest('ar-1'), []);

        $this->assertSame(200, $resp->statusCode);
        $this->assertSame(['tr-1'], $this->shuffledIdsOf($resp));
    }

    public function testMusicShuffle404sWhenEveryTrackIsOverCap(): void
    {
        $tracks = [
            ['id' => 'tr-1', 'name' => 'Explicit', 'type' => 'track', 'content_rating' => 'R',
                'parent_id' => null, 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection(
            [['id' => 'al-1', 'name' => 'An Album', 'type' => 'album', 'metadata_json' => '{}', 'path' => '']],
            $tracks
        ));
        $controller = $this->controller(
            $repo,
            $this->gate($this->pg13Filter()),
            $this->musicWithAlbumTracks(['tr-1'])
        );

        $resp = $controller->shufflePlay($this->shuffleRequest('al-1'), []);

        $this->assertSame(404, $resp->statusCode);
    }
}
