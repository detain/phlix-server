<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\ChapterMarker;
use Phlex\Media\Markers\Detection\MarkerCandidateRepository;
use Phlex\Media\Markers\IntroMarker;
use Phlex\Media\Markers\MarkerService;
use Phlex\Media\Markers\MarkerSet;
use Phlex\Media\Markers\OutroMarker;
use Phlex\Media\Markers\SkipButtonSpec;
use Phlex\Server\Http\Controllers\MediaItemController;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
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
}
