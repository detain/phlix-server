<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Markers;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Markers\MarkerService;
use Phlex\Media\Markers\PlaybackMarkerService;
use Phlex\Media\Markers\MarkerSet;
use Phlex\Media\Markers\IntroMarker;
use Phlex\Media\Markers\OutroMarker;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\Detection\MarkerCandidateRepository;
use Workerman\MySQL\Connection;

class PlaybackMarkerServiceTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function test_get_full_spec_returns_all_available(): void
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
            'intro_end_seconds' => 90,
            'outro_start_seconds' => 2340,
            'outro_end_seconds' => 2520,
            'intro_confidence' => 95,
            'outro_confidence' => 88,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $playbackMarkerService = new PlaybackMarkerService($markerService);

        $spec = $playbackMarkerService->getFullSpec('ep-1');

        $this->assertEquals(10, $spec->skip_intro_start);
        $this->assertEquals(90, $spec->skip_intro_end);
        $this->assertEquals(2340, $spec->skip_outro_start);
        $this->assertEquals(2520, $spec->skip_outro_end);
    }

    public function test_get_full_spec_returns_nulls_when_no_markers(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-2',
            'name' => 'Episode 2',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep2.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'intro_confidence' => null,
            'outro_confidence' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $playbackMarkerService = new PlaybackMarkerService($markerService);

        $spec = $playbackMarkerService->getFullSpec('ep-2');

        $this->assertNull($spec->skip_intro_start);
        $this->assertNull($spec->skip_intro_end);
        $this->assertNull($spec->skip_outro_start);
        $this->assertNull($spec->skip_outro_end);
    }

    public function test_get_skip_spec_respects_position(): void
    {
        // Item with intro at 10-90 seconds, outro at 2340-2520 seconds
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-3',
            'name' => 'Episode 3',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep3.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 90,
            'outro_start_seconds' => 2340,
            'outro_end_seconds' => 2520,
            'intro_confidence' => 95,
            'outro_confidence' => 88,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $playbackMarkerService = new PlaybackMarkerService($markerService);

        // Position at 50 seconds (during intro)
        // 50 seconds = 50 * 10_000_000 = 500,000,000 ticks
        $position_ticks = 50 * 10_000_000;
        $spec = $playbackMarkerService->getSkipSpec('ep-3', $position_ticks);

        // Intro should be active, outro should be null (not in range)
        $this->assertEquals(10, $spec->skip_intro_start);
        $this->assertEquals(90, $spec->skip_intro_end);
        $this->assertNull($spec->skip_outro_start);
        $this->assertNull($spec->skip_outro_end);
    }

    public function test_get_skip_spec_nulls_outside_markers(): void
    {
        // Item with intro at 10-90 seconds, outro at 2340-2520 seconds
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-4',
            'name' => 'Episode 4',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep4.mkv',
            'metadata_json' => json_encode([]),
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 90,
            'outro_start_seconds' => 2340,
            'outro_end_seconds' => 2520,
            'intro_confidence' => 95,
            'outro_confidence' => 88,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $markerService = new MarkerService($itemRepo, $candidateRepo);
        $playbackMarkerService = new PlaybackMarkerService($markerService);

        // Position at 1000 seconds (between intro and outro)
        $position_ticks = 1000 * 10_000_000;
        $spec = $playbackMarkerService->getSkipSpec('ep-4', $position_ticks);

        // Neither intro nor outro should be active
        $this->assertNull($spec->skip_intro_start);
        $this->assertNull($spec->skip_intro_end);
        $this->assertNull($spec->skip_outro_start);
        $this->assertNull($spec->skip_outro_end);
    }
}
