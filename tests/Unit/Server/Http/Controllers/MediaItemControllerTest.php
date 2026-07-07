<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\ChapterMarker;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\IntroMarker;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\MarkerSet;
use Phlix\Media\Markers\OutroMarker;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Controllers\TranscodeController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Tests for MediaItemController::getPlaybackInfo()
 */
class MediaItemControllerTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    /**
     * Test that getPlaybackInfo returns 404 when item is not found.
     * Verifies: Negative case - item not found returns 404 error.
     */
    public function testGetPlaybackInfoReturns404WhenItemNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);  // Empty result = item not found

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'non-existent-id']);

        $this->assertEquals(404, $response->statusCode);

        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Item not found', $body['error']);
    }

    /**
     * Test that getPlaybackInfo returns proper JSON structure when item exists.
     * Verifies: Positive case - returns all expected fields in response.
     */
    public function testGetPlaybackInfoReturnsProperJsonStructure(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => json_encode([
                ['start' => 0, 'end' => 120, 'title' => 'Opening'],
                ['start' => 120, 'end' => 300, 'title' => 'Scene 1'],
            ]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify top-level structure
        $this->assertArrayHasKey('item_id', $body);
        $this->assertArrayHasKey('intro_marker', $body);
        $this->assertArrayHasKey('outro_marker', $body);
        $this->assertArrayHasKey('chapters', $body);
        $this->assertArrayHasKey('skip_button_spec', $body);

        // Verify item_id matches request
        $this->assertEquals('ep-1', $body['item_id']);
    }

    /**
     * Test that show() mints a signed, verifiable direct-play stream_url.
     */
    public function testShowMintsVerifiableStreamUrl(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $response = $controller->show(new Request(), ['id' => 'ep-1']);
        $body = json_decode($response->body, true);

        $this->assertArrayHasKey('stream_url', $body['item']);
        parse_str((string) parse_url((string) $body['item']['stream_url'], PHP_URL_QUERY), $q);
        $this->assertTrue(
            \Phlix\Auth\SignedUrl::fromEnv()->verify('/media/ep-1/stream', (string) ($q['exp'] ?? ''), (string) ($q['sig'] ?? '')),
            'stream_url must be a verifiable signed URL for /media/ep-1/stream',
        );
    }

    /**
     * Test that getPlaybackInfo returns intro_marker with correct structure.
     * Verifies: Positive case - intro marker contains start_seconds and end_seconds.
     */
    public function testGetPlaybackInfoReturnsIntroMarkerWithCorrectFields(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify intro_marker has correct structure
        $this->assertNotNull($body['intro_marker']);
        $this->assertArrayHasKey('start_seconds', $body['intro_marker']);
        $this->assertArrayHasKey('end_seconds', $body['intro_marker']);
        $this->assertEquals(10, $body['intro_marker']['start_seconds']);
        $this->assertEquals(100, $body['intro_marker']['end_seconds']);
    }

    /**
     * Test that getPlaybackInfo returns outro_marker with correct structure.
     * Verifies: Positive case - outro marker contains start_seconds and end_seconds.
     */
    public function testGetPlaybackInfoReturnsOutroMarkerWithCorrectFields(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify outro_marker has correct structure
        $this->assertNotNull($body['outro_marker']);
        $this->assertArrayHasKey('start_seconds', $body['outro_marker']);
        $this->assertArrayHasKey('end_seconds', $body['outro_marker']);
        $this->assertEquals(2200, $body['outro_marker']['start_seconds']);
        $this->assertEquals(2400, $body['outro_marker']['end_seconds']);
    }

    /**
     * Test that chapter markers include both start_seconds and end_seconds.
     * Verifies: Positive case - chapters array contains properly structured chapter markers.
     */
    public function testGetPlaybackInfoReturnsChaptersWithStartAndEndSeconds(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => json_encode([
                ['start' => 0, 'end' => 120, 'title' => 'Opening'],
                ['start' => 120, 'end' => 300, 'title' => 'Scene 1'],
                ['start' => 300, 'end' => 600, 'title' => 'Scene 2'],
            ]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify chapters structure
        $this->assertIsArray($body['chapters']);
        $this->assertCount(3, $body['chapters']);

        // Verify each chapter has start_seconds and end_seconds
        foreach ($body['chapters'] as $chapter) {
            $this->assertArrayHasKey('start_seconds', $chapter);
            $this->assertArrayHasKey('end_seconds', $chapter);
            $this->assertArrayHasKey('title', $chapter);
        }

        // Verify specific chapter values
        $this->assertEquals(0, $body['chapters'][0]['start_seconds']);
        $this->assertEquals(120, $body['chapters'][0]['end_seconds']);
        $this->assertEquals('Opening', $body['chapters'][0]['title']);

        $this->assertEquals(300, $body['chapters'][2]['start_seconds']);
        $this->assertEquals(600, $body['chapters'][2]['end_seconds']);
        $this->assertEquals('Scene 2', $body['chapters'][2]['title']);
    }

    /**
     * Test that skip_button_spec is properly returned in response.
     * Verifies: Positive case - skip_button_spec contains intro and outro skip boundaries.
     */
    public function testGetPlaybackInfoReturnsSkipButtonSpec(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify skip_button_spec structure
        $this->assertArrayHasKey('skip_button_spec', $body);
        $skipSpec = $body['skip_button_spec'];

        $this->assertArrayHasKey('skip_intro_start', $skipSpec);
        $this->assertArrayHasKey('skip_intro_end', $skipSpec);
        $this->assertArrayHasKey('skip_outro_start', $skipSpec);
        $this->assertArrayHasKey('skip_outro_end', $skipSpec);

        // Verify values match intro/outro markers
        $this->assertEquals(10, $skipSpec['skip_intro_start']);
        $this->assertEquals(100, $skipSpec['skip_intro_end']);
        $this->assertEquals(2200, $skipSpec['skip_outro_start']);
        $this->assertEquals(2400, $skipSpec['skip_outro_end']);
    }

    /**
     * Test that getPlaybackInfo returns null markers when item has no markers.
     * Verifies: Negative case - no markers returns null intro/outro and empty chapters.
     */
    public function testGetPlaybackInfoReturnsNullMarkersWhenNoMarkersExist(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $controller = new MediaItemController($itemRepo, $markerService);

        $request = new Request();
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);

        $this->assertEquals(200, $response->statusCode);

        $body = json_decode($response->body, true);

        // Verify null markers and empty chapters
        $this->assertNull($body['intro_marker']);
        $this->assertNull($body['outro_marker']);
        $this->assertEmpty($body['chapters']);

        // Skip spec should have null values when no markers
        $skipSpec = $body['skip_button_spec'];
        $this->assertNull($skipSpec['skip_intro_start']);
        $this->assertNull($skipSpec['skip_intro_end']);
        $this->assertNull($skipSpec['skip_outro_start']);
        $this->assertNull($skipSpec['skip_outro_end']);
    }

    /**
     * Test MarkerService returns proper marker set for a media item.
     * Verifies: MarkerService integration - returns correct MarkerSet with intro, outro, chapters.
     */
    public function testMarkerServiceReturnsProperMarkerSet(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => json_encode([
                ['start' => 0, 'end' => 120, 'title' => 'Opening'],
            ]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);

        $markerSet = $markerService->getMarkers('ep-1');

        // Verify MarkerSet structure
        $this->assertInstanceOf(MarkerSet::class, $markerSet);
        $this->assertTrue($markerSet->hasMarkers());

        // Verify intro marker
        $this->assertNotNull($markerSet->intro);
        $this->assertInstanceOf(IntroMarker::class, $markerSet->intro);
        $this->assertEquals(10, $markerSet->intro->start_seconds);
        $this->assertEquals(100, $markerSet->intro->end_seconds);

        // Verify outro marker
        $this->assertNotNull($markerSet->outro);
        $this->assertInstanceOf(OutroMarker::class, $markerSet->outro);
        $this->assertEquals(2200, $markerSet->outro->start_seconds);
        $this->assertEquals(2400, $markerSet->outro->end_seconds);

        // Verify chapters
        $this->assertCount(1, $markerSet->chapters);
        $this->assertInstanceOf(ChapterMarker::class, $markerSet->chapters[0]);
        $this->assertEquals(0, $markerSet->chapters[0]->start_seconds);
        $this->assertEquals(120, $markerSet->chapters[0]->end_seconds);
        $this->assertEquals('Opening', $markerSet->chapters[0]->title);
    }

    /**
     * Test SkipButtonSpec::fromMarkerSet creates correct spec from markers.
     * Verifies: SkipButtonSpec conversion from MarkerSet produces correct skip boundaries.
     */
    public function testSkipButtonSpecFromMarkerSetCreatesCorrectSpec(): void
    {
        $markerSet = new MarkerSet(
            new IntroMarker(10, 100, 100),
            new OutroMarker(2200, 2400, 100),
            [
                new ChapterMarker(0, 120, 'Opening'),
                new ChapterMarker(120, 300, 'Scene 1'),
            ]
        );

        $skipSpec = SkipButtonSpec::fromMarkerSet($markerSet);

        $this->assertEquals(10, $skipSpec->skip_intro_start);
        $this->assertEquals(100, $skipSpec->skip_intro_end);
        $this->assertEquals(2200, $skipSpec->skip_outro_start);
        $this->assertEquals(2400, $skipSpec->skip_outro_end);
    }

    /**
     * Test SkipButtonSpec::fromMarkerSet handles null markers.
     * Verifies: SkipButtonSpec correctly handles MarkerSet with null intro/outro.
     */
    public function testSkipButtonSpecFromMarkerSetHandlesNullMarkers(): void
    {
        $markerSet = new MarkerSet(null, null, []);

        $skipSpec = SkipButtonSpec::fromMarkerSet($markerSet);

        $this->assertNull($skipSpec->skip_intro_start);
        $this->assertNull($skipSpec->skip_intro_end);
        $this->assertNull($skipSpec->skip_outro_start);
        $this->assertNull($skipSpec->skip_outro_end);
    }

    /**
     * A media item with A1-persisted source metadata gets a pre-flight
     * `quality_ladder` — the flat Rendition-shape ladder with every `url` null
     * (advertisement only, no job created).
     */
    public function testGetPlaybackInfoIncludesQualityLadderFromSourceMetadata(): void
    {
        $controller = $this->controllerForItem($this->itemRowWithSource([
            'width' => 1920, 'height' => 1080, 'video_codec' => 'h264',
            'video_bitrate' => 6000000, 'audio_codec' => 'aac', 'audio_bitrate' => 128000,
            'pix_fmt' => 'yuv420p',
        ]));

        // No profile / header → defaults to `web` (1080p cap).
        $response = $controller->getPlaybackInfo(new Request(), ['id' => 'ep-1']);
        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);

        $this->assertArrayHasKey('quality_ladder', $body);
        $this->assertIsArray($body['quality_ladder']);
        $this->assertNotEmpty($body['quality_ladder']);

        $ids = array_map(static fn (array $v): string => (string) $v['id'], $body['quality_ladder']);
        $this->assertContains('1080p', $ids);
        // Preview only: nothing is playable yet, so every url must be null.
        foreach ($body['quality_ladder'] as $entry) {
            $this->assertNull($entry['url'], 'pre-flight ladder entries carry no playable url');
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('height', $entry);
            $this->assertArrayHasKey('bitrate', $entry);
            $this->assertArrayHasKey('codecs', $entry);
        }
    }

    /**
     * An explicit `?profile=` narrows the previewed ladder (mobile-low caps at
     * 480p), proving the profile selection is honored.
     */
    public function testGetPlaybackInfoQualityLadderHonorsProfileQueryParam(): void
    {
        $controller = $this->controllerForItem($this->itemRowWithSource([
            'width' => 1920, 'height' => 1080, 'video_codec' => 'h264',
            'video_bitrate' => 6000000, 'audio_codec' => 'aac',
        ]));

        $request = new Request();
        $request->query = ['profile' => 'mobile-low'];
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);
        $body = json_decode($response->body, true);

        $ids = array_map(static fn (array $v): string => (string) $v['id'], $body['quality_ladder']);
        $this->assertContains('480p', $ids);
        $this->assertNotContains('1080p', $ids, 'mobile-low profile caps the preview at 480p');
        foreach ($body['quality_ladder'] as $entry) {
            $this->assertLessThanOrEqual(480, $entry['height']);
        }
    }

    /**
     * With no `?profile=`, the `X-Phlix-Device-Type` header drives the profile
     * (mirrors TranscodeController::start()); a TV header lets the ladder reach
     * beyond the web cap.
     */
    public function testGetPlaybackInfoQualityLadderHonorsDeviceTypeHeader(): void
    {
        $controller = $this->controllerForItem($this->itemRowWithSource([
            'width' => 3840, 'height' => 2160, 'video_codec' => 'hevc',
            'video_bitrate' => 20000000, 'audio_codec' => 'ac3',
        ]));

        $request = new Request();
        $request->headers = ['X-PHLIX-DEVICE-TYPE' => 'samsung-tizen']; // → tv-4k
        $response = $controller->getPlaybackInfo($request, ['id' => 'ep-1']);
        $body = json_decode($response->body, true);

        $ids = array_map(static fn (array $v): string => (string) $v['id'], $body['quality_ladder']);
        // tv-4k (3840x2160) admits the 2160p rung a web cap would drop.
        $this->assertContains('2160p', $ids);
    }

    /**
     * An item without persisted source metadata degrades gracefully to
     * `quality_ladder: null` (no error).
     */
    public function testGetPlaybackInfoQualityLadderNullWhenSourceAbsent(): void
    {
        $controller = $this->controllerForItem($this->baseItemRow(json_encode([])));

        $response = $controller->getPlaybackInfo(new Request(), ['id' => 'ep-1']);
        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('quality_ladder', $body);
        $this->assertNull($body['quality_ladder']);
    }

    /**
     * Source metadata missing width or height → `quality_ladder: null` (incomplete
     * blob is not enough to build a ladder).
     */
    public function testGetPlaybackInfoQualityLadderNullWhenSourceIncomplete(): void
    {
        // width present but height absent.
        $controller = $this->controllerForItem($this->itemRowWithSource([
            'width' => 1920, 'video_codec' => 'h264', 'audio_codec' => 'aac',
        ]));

        $response = $controller->getPlaybackInfo(new Request(), ['id' => 'ep-1']);
        $body = json_decode($response->body, true);
        $this->assertNull($body['quality_ladder']);
    }

    /**
     * The device-type → profile mapping table in MediaItemController MUST be
     * byte-identical to TranscodeController's, so the pre-flight preview and the
     * real transcode job agree on the device profile.
     */
    public function testDeviceTypeMappingIsIdenticalToTranscodeController(): void
    {
        $mediaController = $this->controllerForItem($this->baseItemRow(json_encode([])));
        $transcodeController = new TranscodeController($this->createMock(TranscodeManager::class));

        $mediaMap = new ReflectionMethod($mediaController, 'mapDeviceTypeToProfile');
        $transcodeMap = new ReflectionMethod($transcodeController, 'mapDeviceTypeToProfile');

        $inputs = [
            'samsung-tizen', 'tizen', 'roku', 'android', 'ios', 'windows',
            '', 'unknown-device', 'SAMSUNG-TIZEN', 'Android', 'Roku', 'iOS',
            '  roku  ', 'macos', 'web',
        ];
        foreach ($inputs as $input) {
            $this->assertSame(
                $transcodeMap->invoke($transcodeController, $input),
                $mediaMap->invoke($mediaController, $input),
                "mapping for '{$input}' must match TranscodeController",
            );
        }
    }

    /**
     * Builds a MediaItemController whose repository returns exactly $itemRow.
     *
     * @param array<string, mixed> $itemRow
     */
    private function controllerForItem(array $itemRow): MediaItemController
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([$itemRow]);
        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);

        return new MediaItemController($itemRepo, $markerService);
    }

    /**
     * A minimal episode row with the given raw metadata_json string.
     *
     * @return array<string, mixed>
     */
    private function baseItemRow(string $metadataJson): array
    {
        return [
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => $metadataJson,
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ];
    }

    /**
     * A base episode row carrying an A1-style `metadata_json['source']` blob.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function itemRowWithSource(array $source): array
    {
        return $this->baseItemRow((string) json_encode(['source' => $source]));
    }
}
