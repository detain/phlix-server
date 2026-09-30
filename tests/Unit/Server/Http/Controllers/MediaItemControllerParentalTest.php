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
 * M-2 (scan @9e765895): getTrickplay() and getChapterThumbnail() deny
 * over-cap items for IDENTIFIED requests with the exact show() 404 — while
 * the documented anonymous posture on those two public routes (Application's
 * "public, no auth required" registrations) stays byte-identical;
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

    /**
     * A request with NO user at all — exactly what an attacker sent by dropping
     * the Bearer token before S423 gated `/api/v1/media/{id}/download` behind
     * AuthMiddleware. Since S423 the ROUTER refuses such a caller with 401
     * (ApplicationPlaybackAuthGateTest), so this fixture pins the SECOND layer
     * it reaches only when called directly: the handler-side deny-all.
     *
     * @return Request
     */
    private function anonymousRequest(): Request
    {
        $req = new Request();
        // Left exactly as the entry point leaves it when no session resolves.
        $this->assertNull($req->userId, 'fixture must really be anonymous');
        return $req;
    }

    /**
     * A real on-disk file for the item's `path`, so `getDownload()` reaches its
     * 200 branch when the gate lets it through. Without this every assertion
     * below would be satisfied by the unrelated "File not found on disk" 404.
     */
    private function existingMediaFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phlix-s235-');
        $this->assertIsString($file);
        file_put_contents($file, 'bytes');
        return $file;
    }

    /**
     * 🚨 S235 — THE step's acceptance criterion, and the ANONYMOUS path
     * specifically.
     *
     * The sibling `testGetDownloadBlocksOverCapItem` above sends `userId = 'u1'`
     * and passed before S235 too: it proves nothing about this defect, because
     * the capped-profile path is the one that always worked. The bypass was to
     * send NO token at all — `resolveFilterForUser('')` answered null, the guard
     * `if ($filter !== null && …)` was skipped wholesale, and the handler minted
     * a signed `/media/{id}/stream` URL for any item id.
     *
     * Measured on master before the fix: status 200 with `"url":"/media/m1/stream?exp=…&sig=…"`.
     */
    public function testAnAnonymousDownloadRequestGetsNoSignedUrlForAnOverCapItem(): void
    {
        $file = $this->existingMediaFile();
        try {
            $repo = new ItemRepository($this->connection([[
                'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
                'content_rating' => 'R', 'metadata_json' => '{}', 'path' => $file,
            ]]));
            $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

            $resp = $controller->getDownload($this->anonymousRequest(), ['id' => 'm1']);

            $this->assertSame(404, $resp->statusCode);
            $this->assertStringNotContainsString('url', $resp->body);
            $this->assertStringNotContainsString('/media/', $resp->body);
        } finally {
            @unlink($file);
        }
    }

    /**
     * The fail-closed rule cannot depend on the item's rating: an anonymous
     * caller is refused a signed URL for an UNRATED item too, because the server
     * has no user and therefore cannot prove the item is under anyone's cap.
     * (An unrated item passes an ordinary `allowUnrated: true` cap, so this is
     * the case a rating-shaped fix would miss.)
     */
    public function testAnAnonymousDownloadRequestGetsNoSignedUrlForAnUnratedItem(): void
    {
        $file = $this->existingMediaFile();
        try {
            $repo = new ItemRepository($this->connection([[
                'id' => 'm1', 'name' => 'Unrated', 'type' => 'movie',
                'content_rating' => null, 'metadata_json' => '{}', 'path' => $file,
            ]]));
            $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

            $resp = $controller->getDownload($this->anonymousRequest(), ['id' => 'm1']);

            $this->assertSame(404, $resp->statusCode);
            $this->assertStringNotContainsString('/media/', $resp->body);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Noise control — the fix must not break the endpoint for its real callers.
     * An IDENTIFIED, un-capped user still gets the signed URL, byte-for-byte the
     * pre-S235 response shape.
     */
    public function testAnIdentifiedUnCappedUserStillGetsTheSignedDownloadUrl(): void
    {
        $file = $this->existingMediaFile();
        try {
            $repo = new ItemRepository($this->connection([[
                'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
                'content_rating' => 'R', 'metadata_json' => '{}', 'path' => $file,
            ]]));
            // Admin owner → null filter → no gating.
            $controller = $this->controller($repo, $this->gate($this->pg13Filter(), true));

            $resp = $controller->getDownload($this->cappedRequest(), ['id' => 'm1']);

            $this->assertSame(200, $resp->statusCode);
            $this->assertStringContainsString('/media/m1/stream', $resp->body);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Defence in depth for the OTHER signed-URL mint on this controller.
     * `show()` mints `stream_url` the same way; it is not currently registered on
     * any route, so this pins the gate rather than a reachable hole.
     */
    public function testAnAnonymousShowRequestDisclosesNoStreamUrl(): void
    {
        $repo = new ItemRepository($this->connection([[
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'R', 'metadata_json' => '{}', 'path' => '/x.mkv',
        ]]));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter()));

        $resp = $controller->show($this->anonymousRequest(), ['id' => 'm1']);

        $this->assertSame(404, $resp->statusCode);
        $this->assertStringNotContainsString('stream_url', $resp->body);
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

    // -----------------------------------------------------------------
    // M-2 (security scan @9e765895) — trickplay + chapter-thumbnail assets
    // carry the file's own S235 rating invariant for identified requests.
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed>                 $item        media_items row.
     * @param list<array<string, mixed>>           $markerRows  media_markers rows.
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function assetConnection(array $item, array $markerRows = [])
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($item, $markerRows): array {
                if (str_contains($sql, 'FROM media_items WHERE id = ?')) {
                    return [$item];
                }
                if (str_contains($sql, 'FROM media_markers WHERE media_item_id = ?')) {
                    return $markerRows;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * @param array<string, mixed>      $item        media_items row.
     * @param list<array<string, mixed>> $markerRows media_markers rows for the
     *                                               chapter-thumbnail lookup.
     */
    private function assetController(array $item, RatingGate $gate, array $markerRows = []): MediaItemController
    {
        $repo = new ItemRepository($this->assetConnection($item, $markerRows));

        // The chapter marker lookup runs through Phlix\Media\MarkerService over
        // its OWN connection double — script it with the same rows so the
        // pre-fix path really reaches the 200 it must be reddened on.
        $markerDb = $this->createMock(Connection::class);
        $markerDb->method('query')->willReturn($markerRows);

        return new MediaItemController(
            $repo,
            new \Phlix\Media\Markers\MarkerService($repo, new MarkerCandidateRepository($repo)),
            $this->createMock(GaplessPlaybackManager::class),
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($markerDb),
            null,
            $gate,
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function overCapTrickplayItem(): array
    {
        return [
            'id' => 'm1', 'name' => 'Mature', 'type' => 'movie',
            'content_rating' => 'R', 'metadata_json' => '{}', 'path' => '/x.mkv',
            'trickplay_sprite_path' => '/data/trickplay/m1/sprite.jpg',
            'trickplay_timeline_path' => '/data/trickplay/m1/timeline.json',
        ];
    }

    public function testGetTrickplayBlocksOverCapItemForIdentifiedRequest(): void
    {
        $controller = $this->assetController(
            $this->overCapTrickplayItem(),
            $this->gate($this->pg13Filter())
        );

        $resp = $controller->getTrickplay($this->cappedRequest(), ['id' => 'm1']);

        // The exact show() refusal shape: 404 'Item not found' — never a 403,
        // so the answer cannot confirm the item exists.
        $this->assertSame(404, $resp->statusCode);
        $this->assertStringNotContainsString('sprite_url', $resp->body);
        $this->assertStringNotContainsString('trickplay', $resp->body);
    }

    public function testGetTrickplayWithinCapStillServesIdentifiedRequest(): void
    {
        $item = $this->overCapTrickplayItem();
        $item['content_rating'] = 'PG';
        $controller = $this->assetController($item, $this->gate($this->pg13Filter()));

        $resp = $controller->getTrickplay($this->cappedRequest(), ['id' => 'm1']);

        $this->assertSame(200, $resp->statusCode);
        $decoded = json_decode($resp->body, true);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['sprite_url'] ?? null);
    }

    public function testGetTrickplayAnonymousPostureIsUnchanged(): void
    {
        // The documented public posture (Application: "Trickplay sprite and
        // timeline URLs (public, no auth required)") must survive byte-for-byte:
        // an UNIDENTIFIED request is not rating-filtered here.
        $controller = $this->assetController(
            $this->overCapTrickplayItem(),
            $this->gate($this->pg13Filter())
        );

        $resp = $controller->getTrickplay($this->anonymousRequest(), ['id' => 'm1']);

        $this->assertSame(200, $resp->statusCode);
        $decoded = json_decode($resp->body, true);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['sprite_url'] ?? null);
        $this->assertIsString($decoded['timeline_url'] ?? null);
    }

    public function testGetChapterThumbnailBlocksOverCapItemForIdentifiedRequest(): void
    {
        $thumb = sys_get_temp_dir() . '/phlix_m2_thumb_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($thumb, 'JPEGDATA');
        $this->addToAssertionCount(1);

        try {
            $item = $this->overCapTrickplayItem();
            $item['chapters_json'] = json_encode([['start' => 0, 'title' => 'Chapter 1']]);

            // A fully-resolvable marker + on-disk thumbnail: PRE-FIX this
            // identified over-cap request returns 200 with the image — the red
            // proof this test exists.
            $markerRows = [[
                'id' => 7,
                'media_item_id' => 'm1',
                'marker_type' => 'chapter',
                'start_time_ms' => 0,
                'end_time_ms' => 60000,
                'label' => 'Chapter 1',
                'user_id' => null,
                'thumbnail_path' => $thumb,
            ]];

            $controller = $this->assetController($item, $this->gate($this->pg13Filter()), $markerRows);

            $resp = $controller->getChapterThumbnail($this->cappedRequest(), ['id' => 'm1', 'index' => '0']);

            $this->assertSame(404, $resp->statusCode);
            $decoded = json_decode($resp->body, true);
            $this->assertIsArray($decoded);
            $this->assertSame('Item not found', $decoded['error'] ?? null);
        } finally {
            @unlink($thumb);
        }
    }

    public function testGetChapterThumbnailAnonymousPostureIsUnchanged(): void
    {
        $thumb = sys_get_temp_dir() . '/phlix_m2_thumb_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($thumb, 'JPEGDATA');

        try {
            $item = $this->overCapTrickplayItem();
            $item['chapters_json'] = json_encode([['start' => 0, 'title' => 'Chapter 1']]);
            $markerRows = [[
                'id' => 7,
                'media_item_id' => 'm1',
                'marker_type' => 'chapter',
                'start_time_ms' => 0,
                'end_time_ms' => 60000,
                'label' => 'Chapter 1',
                'user_id' => null,
                'thumbnail_path' => $thumb,
            ]];

            $controller = $this->assetController($item, $this->gate($this->pg13Filter()), $markerRows);

            $resp = $controller->getChapterThumbnail($this->anonymousRequest(), ['id' => 'm1', 'index' => '0']);

            $this->assertSame(200, $resp->statusCode);
            $this->assertSame('image/jpeg', $resp->headers['Content-Type'] ?? null);
        } finally {
            @unlink($thumb);
        }
    }
}
