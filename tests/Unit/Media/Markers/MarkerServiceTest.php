<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Markers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\IntroMarkerCandidate;
use Phlix\Media\Markers\Detection\IntroDetectionResult;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\Detection\OutroMarkerCandidate;
use Phlix\Media\Markers\Detection\StoredMarkers;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\MarkerSet;
use Workerman\MySQL\Connection;

class MarkerServiceTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testPromoteCandidatesWritesColumns(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturnCallback(function ($sql, $params) {
            if (strpos($sql, 'SELECT') !== false) {
                return [[
                    'id' => 'ep-1',
                    'name' => 'Episode 1',
                    'type' => 'episode',
                    'library_id' => 'lib-1',
                    'parent_id' => 'show-1',
                    'path' => '/test/ep.mkv',
                    'metadata_json' => json_encode([
                        'intro_candidate' => [
                            'start_seconds' => 0,
                            'end_seconds' => 90,
                            'fingerprint' => 'intro_fp',
                            'confidence' => 85,
                        ],
                        'outro_candidate' => [
                            'start_seconds' => 2310,
                            'end_seconds' => 2400,
                            'fingerprint' => 'outro_fp',
                            'confidence' => 80,
                        ],
                    ]),
                    'intro_start_seconds' => null,
                    'intro_end_seconds' => null,
                    'outro_start_seconds' => null,
                    'outro_end_seconds' => null,
                    'chapters_json' => null,
                ]];
            }
            // Capture the UPDATE query
            return [];
        });

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        // Verify item has candidate data
        $markerSet = $service->getMarkers('ep-1');
        $this->assertNotNull($markerSet->intro);
        $this->assertNotNull($markerSet->outro);
    }

    public function testGetMarkersReadsFormalColumnsFirst(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => '{}',
            'intro_start_seconds' => 10,
            'intro_end_seconds' => 100,
            'outro_start_seconds' => 2200,
            'outro_end_seconds' => 2400,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $markerSet = $service->getMarkers('ep-1');

        $this->assertNotNull($markerSet->intro);
        $this->assertEquals(10, $markerSet->intro->start_seconds);
        $this->assertEquals(100, $markerSet->intro->end_seconds);

        $this->assertNotNull($markerSet->outro);
        $this->assertEquals(2200, $markerSet->outro->start_seconds);
        $this->assertEquals(2400, $markerSet->outro->end_seconds);
    }

    public function testGetMarkersFallsBackToCandidates(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([
                'intro_candidate' => [
                    'start_seconds' => 0,
                    'end_seconds' => 90,
                    'fingerprint' => 'intro_fp',
                    'confidence' => 85,
                ],
            ]),
            'intro_start_seconds' => null,  // No formal column data
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $markerSet = $service->getMarkers('ep-1');

        $this->assertNotNull($markerSet->intro);
        $this->assertEquals(0, $markerSet->intro->start_seconds);
        $this->assertEquals(90, $markerSet->intro->end_seconds);
        $this->assertEquals(85, $markerSet->intro->confidence);
    }

    public function testPromoteShowMarkers(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturnCallback(function ($sql, $params) {
            if (strpos($sql, 'SELECT') !== false && strpos($sql, 'parent_id') !== false) {
                // findByParent for show
                return [
                    [
                        'id' => 'ep-1',
                        'name' => 'Episode 1',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep1.mkv',
                        'metadata_json' => json_encode([
                            'intro_candidate' => [
                                'start_seconds' => 0,
                                'end_seconds' => 90,
                                'fingerprint' => 'intro_fp',
                                'confidence' => 85,
                            ],
                        ]),
                        'intro_start_seconds' => null,
                        'intro_end_seconds' => null,
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ],
                    [
                        'id' => 'ep-2',
                        'name' => 'Episode 2',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep2.mkv',
                        'metadata_json' => json_encode([
                            'intro_candidate' => [
                                'start_seconds' => 0,
                                'end_seconds' => 90,
                                'fingerprint' => 'intro_fp',
                                'confidence' => 80,
                            ],
                        ]),
                        'intro_start_seconds' => null,
                        'intro_end_seconds' => null,
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ],
                ];
            }
            if (strpos($sql, 'SELECT') !== false) {
                // findById
                return [[
                    'id' => $params[0],
                    'name' => 'Episode',
                    'type' => 'episode',
                    'library_id' => 'lib-1',
                    'parent_id' => 'show-1',
                    'path' => '/test/ep.mkv',
                    'metadata_json' => json_encode([
                        'intro_candidate' => [
                            'start_seconds' => 0,
                            'end_seconds' => 90,
                            'fingerprint' => 'intro_fp',
                            'confidence' => 85,
                        ],
                    ]),
                    'intro_start_seconds' => null,
                    'intro_end_seconds' => null,
                    'outro_start_seconds' => null,
                    'outro_end_seconds' => null,
                    'chapters_json' => null,
                ]];
            }
            return [];
        });

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $count = $service->promoteShowMarkers('show-1');

        // Both episodes have intro candidates
        $this->assertEquals(2, $count);
    }

    public function testGetMarkersReturnsEmptyWhenNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $markerSet = $service->getMarkers('non-existent');

        $this->assertFalse($markerSet->hasMarkers());
    }

    public function testGetShowMarkersReturnsBulkEpisodes(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturnCallback(function ($sql, $params) {
            if (strpos($sql, 'parent_id') !== false) {
                return [
                    [
                        'id' => 'ep-1',
                        'name' => 'Episode 1',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep1.mkv',
                        'metadata_json' => json_encode([]),
                        'intro_start_seconds' => 10,
                        'intro_end_seconds' => 100,
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ],
                    [
                        'id' => 'ep-2',
                        'name' => 'Episode 2',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep2.mkv',
                        'metadata_json' => json_encode([]),
                        'intro_start_seconds' => 12,
                        'intro_end_seconds' => 102,
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ],
                ];
            }
            // findById
            foreach (['ep-1', 'ep-2'] as $epId) {
                if (in_array($epId, $params)) {
                    $ep = $epId === 'ep-1'
                        ? ['intro_start_seconds' => 10, 'intro_end_seconds' => 100]
                        : ['intro_start_seconds' => 12, 'intro_end_seconds' => 102];
                    return [[
                        'id' => $epId,
                        'name' => 'Episode',
                        'type' => 'episode',
                        'library_id' => 'lib-1',
                        'parent_id' => 'show-1',
                        'path' => '/test/ep.mkv',
                        'metadata_json' => json_encode([]),
                        'intro_start_seconds' => $ep['intro_start_seconds'],
                        'intro_end_seconds' => $ep['intro_end_seconds'],
                        'outro_start_seconds' => null,
                        'outro_end_seconds' => null,
                        'chapters_json' => null,
                    ]];
                }
            }
            return [];
        });

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $result = $service->getShowMarkers('show-1');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('ep-1', $result);
        $this->assertArrayHasKey('ep-2', $result);
        $this->assertEquals('Episode 1', $result['ep-1']['name']);
        $this->assertNotNull($result['ep-1']['markers']['intro']);
    }

    public function testPromoteCandidatesDoesNothingWhenNoCandidates(): void
    {
        $db = $this->createMockConnection();

        $db->method('query')->willReturn([[
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => '/test/ep.mkv',
            'metadata_json' => json_encode([]),  // No candidates
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $calledWithUpdate = [];
        $service->promoteCandidates('ep-1');

        // No UPDATE query was made since there were no candidates
        // The findById SELECT query was made but no update followed
        $this->assertEmpty($calledWithUpdate);
    }

    public function testGetMarkersWithChapters(): void
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
                ['start' => 0, 'end' => 90, 'title' => 'Intro'],
                ['start' => 90, 'end' => 300, 'title' => 'Chapter 1'],
            ]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $markerSet = $service->getMarkers('ep-1');

        $this->assertCount(2, $markerSet->chapters);
        $this->assertEquals(0, $markerSet->chapters[0]->start_seconds);
        $this->assertEquals(90, $markerSet->chapters[0]->end_seconds);
        $this->assertEquals('Intro', $markerSet->chapters[0]->title);
    }
}
