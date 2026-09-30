<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * F-08 hardening: PATCH /api/v1/media/{id}/metadata must parse the user-supplied
 * `metadata_json` patch at the HTTP boundary against a closed top-level allowlist
 * before merging it into the stored provider blob.
 *
 * Pinned here:
 *  - Unknown top-level keys are REFUSED (400, offending keys named). Evidence: zero
 *    legitimate HTTP senders of metadata_json exist in the estate (web-ui, phlix-ui
 *    callers, roku/tizen/windows/console/mobile), so fail-loud breaks nothing.
 *  - Rating-identity keys (rating/official_rating/content_rating) are refused — they
 *    materialize the content_rating COLUMN the parental gate reads.
 *  - The merge base is the DECODED stored blob (hydrate puts it in `metadata`; the
 *    raw `metadata_json` string would otherwise make every PATCH clobber the blob).
 *  - Values are type-parsed per allowlisted key; nested objects/arrays-of-arrays,
 *    oversized payloads and non-object metadata_json all fail with 400.
 *  - The allowlist lives at the HTTP boundary ONLY: internal writers (scanner,
 *    matcher, MediaPosterController) keep writing the full vocabulary through
 *    ItemRepository::update() directly — pinned by the last test.
 */
class MediaItemControllerMetadataMergeTest extends TestCase
{
    /**
     * Set by-reference from the query mock once an UPDATE lands:
     * ['sql' => string, 'values' => list<mixed>]. Declared plain-?array so
     * phpstan does not flag the shape as never-assigned (reference capture is
     * invisible to it); uses narrow locally.
     */
    private ?array $capturedUpdate = null;

    /** @param array<string, mixed> $storedBlob */
    private function storedRow(array $storedBlob): array
    {
        return [
            'id' => 'item-1',
            'name' => 'Stored Title',
            'type' => 'movie',
            'library_id' => 'lib-1',
            'parent_id' => null,
            'path' => '/media/movie.mkv',
            'metadata_json' => json_encode($storedBlob),
            'content_rating' => 'PG-13',
        ];
    }

    /**
     * @param array<string, mixed> $storedBlob The provider blob on the row.
     * @return MediaItemController
     */
    private function controller(array $storedBlob): MediaItemController
    {
        $this->capturedUpdate = null;
        $row = $this->storedRow($storedBlob);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @param list<mixed> $bind */
            function (string $sql, array $bind = []) use ($row): array|int {
                if ($sql === 'SELECT * FROM media_items WHERE id = ?') {
                    return [$row];
                }
                if (str_starts_with($sql, 'UPDATE media_items SET')) {
                    $this->capturedUpdate = ['sql' => $sql, 'values' => $bind];
                    return 1;
                }
                return [];
            }
        );

        $itemRepo = new ItemRepository($db);
        $markerService = new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo));
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        return new MediaItemController(
            $itemRepo,
            $markerService,
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($db)
        );
    }

    /** @param array<string, mixed> $body */
    private function patch(MediaItemController $controller, array $body): \Phlix\Server\Http\Response
    {
        $request = new Request();
        $request->body = $body;
        return $controller->updateMetadata($request, ['id' => 'item-1']);
    }

    /**
     * The metadata_json array actually bound into the UPDATE (decoded), or null
     * when no UPDATE ran / no blob was written.
     *
     * @return array<string, mixed>|null
     */
    private function writtenMetadata(): ?array
    {
        $update = $this->capturedUpdate;
        if ($update === null) {
            return null;
        }
        $sets = [];
        if (preg_match_all('/`?(\w+)`? = \?/', $update['sql'], $matches) === false) {
            return null;
        }
        foreach ($matches[1] as $index => $column) {
            if (!array_key_exists($index, $update['values'])) {
                continue;
            }
            $sets[$column] = $update['values'][$index];
        }
        $bound = $sets['metadata_json'] ?? null;
        if (!is_string($bound)) {
            return null;
        }
        $decoded = json_decode($bound, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function fullProviderBlob(): array
    {
        return [
            'rating' => 'PG-13',
            'official_rating' => 'PG-13',
            'canonical_key' => 'tmdb:movie:123',
            'source' => ['width' => 1920, 'height' => 1080, 'video_codec' => 'h264'],
            'overview' => 'Stored overview',
            'genres' => ['Drama'],
            'external_ids' => ['tmdb_id' => 123],
            'streams' => [['codec' => 'h264']],
        ];
    }

    public function testUnknownTopLevelKeyIsRefused400NamingTheKey(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, ['metadata_json' => ['evil_key' => 'payload']]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('evil_key', (string) ($body['error'] ?? ''));
        $this->assertNull($this->capturedUpdate, 'a refused patch must not reach the repository');
    }

    public function testRatingKeyIsRefusedBecauseItMaterializesTheParentalColumn(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, ['metadata_json' => ['rating' => null]]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('rating', (string) ($body['error'] ?? ''));
        $this->assertNull($this->capturedUpdate, 'a rating-nulling patch must never reach update()');
    }

    public function testOfficialRatingKeyIsRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, ['metadata_json' => ['official_rating' => 'G']]);

        $this->assertSame(400, $response->statusCode);
    }

    public function testCanonicalKeyAndSourceAreRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, [
            'metadata_json' => ['canonical_key' => 'evil:identity', 'source' => ['width' => 1]],
        ]);

        $this->assertSame(400, $response->statusCode);
    }

    public function testOversizedPatchIsRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, [
            'metadata_json' => ['overview' => str_repeat('x', 200_000)],
        ]);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('metadata_json', (string) ($body['error'] ?? ''));
        $this->assertNull($this->capturedUpdate);
    }

    public function testNestedObjectValueIsRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, [
            'metadata_json' => ['overview' => ['a' => ['b' => ['c' => 1]]]],
        ]);

        $this->assertSame(400, $response->statusCode);
    }

    public function testNestedBombInsideListValueIsRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, [
            'metadata_json' => ['genres' => [['deep' => ['nested' => 1]]]],
        ]);

        $this->assertSame(400, $response->statusCode);
    }

    public function testMetadataJsonMustBeAnObject(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, ['metadata_json' => 'not-an-array']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertStringContainsString('metadata_json', (string) ($body['error'] ?? ''));
    }

    public function testScalarTypeMismatchOnAllowlistedKeyIsRefused(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $yearAsString = $this->patch($controller, ['metadata_json' => ['year' => '2024']]);
        $this->assertSame(400, $yearAsString->statusCode);

        $controller2 = $this->controller($this->fullProviderBlob());
        $genresAsString = $this->patch($controller2, ['metadata_json' => ['genres' => 'Drama']]);
        $this->assertSame(400, $genresAsString->statusCode);

        $controller3 = $this->controller($this->fullProviderBlob());
        $overviewAsInt = $this->patch($controller3, ['metadata_json' => ['overview' => 42]]);
        $this->assertSame(400, $overviewAsInt->statusCode);
    }

    public function testMergeBaseIsTheDecodedStoredBlobNotTheRawString(): void
    {
        $stored = $this->fullProviderBlob();
        $controller = $this->controller($stored);

        $response = $this->patch($controller, ['metadata_json' => ['overview' => 'User overview']]);

        $this->assertSame(200, $response->statusCode);
        $written = $this->writtenMetadata();
        $this->assertIsArray($written);
        // The user key landed…
        $this->assertSame('User overview', $written['overview']);
        // …and every stored provider key SURVIVED the merge (pre-fix, hydrate keeps
        // metadata_json as a raw string, the merge base collapsed to [], and this
        // PATCH replaced the whole blob).
        $this->assertSame('tmdb:movie:123', $written['canonical_key']);
        $this->assertSame($stored['source'], $written['source']);
        $this->assertSame($stored['external_ids'], $written['external_ids']);
        $this->assertSame($stored['streams'], $written['streams']);
        $this->assertSame('PG-13', $written['rating']);
        // content_rating column keeps deriving from the preserved blob.
        $this->assertSame('PG-13', $stored['rating']);
    }

    public function testAllowlistedKeysRoundTrip(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $patch = [
            'summary' => 'user summary',
            'overview' => 'user overview',
            'tagline' => 'user tagline',
            'episode_title' => 'user episode title',
            'artist' => 'user artist',
            'album' => 'user album',
            'year' => 2024,
            'runtime' => 95,
            'season' => 2,
            'episode' => 3,
            'genres' => ['Drama', 'Comedy'],
            'tags' => ['family-favorite'],
        ];
        $response = $this->patch($controller, ['metadata_json' => $patch]);

        $this->assertSame(200, $response->statusCode);
        $written = $this->writtenMetadata();
        $this->assertIsArray($written);
        foreach ($patch as $key => $value) {
            $this->assertArrayHasKey($key, $written);
            $this->assertSame($value, $written[$key], "allowlisted key {$key} must round-trip unchanged");
        }
    }

    public function testTopLevelSummaryAndOverviewBranchesStillMergeOntoTheStoredBlob(): void
    {
        $stored = $this->fullProviderBlob();
        $controller = $this->controller($stored);

        $response = $this->patch($controller, ['summary' => 'plain summary']);

        $this->assertSame(200, $response->statusCode);
        $written = $this->writtenMetadata();
        $this->assertIsArray($written);
        $this->assertSame('plain summary', $written['summary']);
        $this->assertSame(
            'tmdb:movie:123',
            $written['canonical_key'],
            'the plain summary branch must not clobber the blob either'
        );
    }

    public function testTitleOnlyPatchStillWritesJustTheColumn(): void
    {
        $controller = $this->controller($this->fullProviderBlob());

        $response = $this->patch($controller, ['title' => 'Renamed']);

        $this->assertSame(200, $response->statusCode);
        $update = $this->capturedUpdate;
        $this->assertIsArray($update);
        $this->assertStringContainsString('title = ?', $update['sql']);
        $this->assertStringNotContainsString('metadata_json = ?', $update['sql']);
    }

    public function testInternalWritersKeepTheFullProviderVocabulary(): void
    {
        // The allowlist is the HTTP boundary ONLY. ItemRepository::update() stays
        // permissive — scanner/matcher/poster paths write the full blob directly.
        $captured = null;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, array $bind = []) use (&$captured) {
                if (str_starts_with($sql, 'UPDATE media_items SET')) {
                    $captured = ['sql' => $sql, 'values' => $bind];
                }
                return [];
            }
        );

        $repo = new ItemRepository($db);
        $repo->update('item-1', [
            'metadata_json' => [
                'rating' => 'R',
                'official_rating' => 'R',
                'canonical_key' => 'tmdb:movie:999',
                'source' => ['width' => 3840],
            ],
        ]);

        $this->assertIsArray($captured);
        $bound = null;
        preg_match_all('/`?(\w+)`? = \?/', $captured['sql'], $matches);
        foreach ($matches[1] as $index => $column) {
            if ($column === 'metadata_json') {
                $bound = $captured['values'][$index];
            }
        }
        $this->assertIsString($bound);
        $decoded = json_decode((string) $bound, true);
        $this->assertIsArray($decoded);
        $this->assertSame('R', $decoded['rating']);
        $this->assertStringContainsString(
            'content_rating = ?',
            $captured['sql'],
            'column derivation stays intact for internal writers'
        );
    }
}
