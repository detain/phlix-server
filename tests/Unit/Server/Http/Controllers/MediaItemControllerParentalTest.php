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
 * children() honours effective (inherited) ratings; the owner is never gated.
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

    private function controller(ItemRepository $repo, RatingGate $gate): MediaItemController
    {
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
            $gate
        );
    }

    private function cappedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'u1';
        return $req;
    }

    /**
     * Decode a JSON response body's `items` array.
     *
     * @return array<int, mixed>
     */
    private function itemsOf(\Phlix\Server\Http\Response $resp): array
    {
        $body = json_decode($resp->body, true);
        $this->assertIsArray($body);
        $items = $body['items'] ?? null;
        $this->assertIsArray($items);
        return array_values($items);
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

    public function testChildrenKeepsEpisodesOfAllowedSeries(): void
    {
        // Episodes have NULL own rating + parent series → effective from series.
        $children = [
            ['id' => 'ep-1', 'name' => 'E1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'metadata_json' => '{}'],
            ['id' => 'ep-2', 'name' => 'E2', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection([], $children));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter(), false, ['show-1' => 'PG']));

        $resp = $controller->children($this->cappedRequest(), ['id' => 'show-1']);
        $this->assertSame(200, $resp->statusCode);
        $this->assertCount(2, $this->itemsOf($resp));
    }

    public function testChildrenHidesEpisodesOfBlockedSeries(): void
    {
        $children = [
            ['id' => 'ep-1', 'name' => 'E1', 'type' => 'episode', 'content_rating' => null,
                'parent_id' => 'show-1', 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection([], $children));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter(), false, ['show-1' => 'R']));

        $resp = $controller->children($this->cappedRequest(), ['id' => 'show-1']);
        $this->assertSame(200, $resp->statusCode);
        $this->assertCount(0, $this->itemsOf($resp));
    }

    public function testChildrenUnfilteredForOwner(): void
    {
        $children = [
            ['id' => 'ep-1', 'name' => 'E1', 'type' => 'episode', 'content_rating' => 'R',
                'parent_id' => 'show-1', 'metadata_json' => '{}'],
        ];
        $repo = new ItemRepository($this->connection([], $children));
        $controller = $this->controller($repo, $this->gate($this->pg13Filter(), true));

        $resp = $controller->children($this->cappedRequest(), ['id' => 'show-1']);
        $this->assertCount(1, $this->itemsOf($resp));
    }
}
