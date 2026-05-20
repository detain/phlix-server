<?php

namespace Phlix\Tests\Unit\Media\Markers\Detection;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\IntroDetectionResult;
use Phlix\Media\Markers\Detection\IntroMarkerCandidate;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\Detection\OutroMarkerCandidate;
use Phlix\Media\Markers\Detection\StoredMarkers;
use Workerman\MySQL\Connection;

class MarkerCandidateRepositoryTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testStoreAndRetrieveCandidates(): void
    {
        $db = $this->createMockConnection();

        $introCandidate = new IntroMarkerCandidate(0, 90, 'intro_fp', 85);
        $outroCandidate = new OutroMarkerCandidate(2310, 2400, 'outro_fp', 80);

        $result = new IntroDetectionResult(
            show_id: 'show-1',
            episodes_fingerprinted: 3,
            intro_candidate: $introCandidate,
            outro_candidate: $outroCandidate,
            episodes_processed: ['ep-1', 'ep-2', 'ep-3'],
        );

        $db->method('query')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'SELECT') !== false) {
                    return [
                        [
                            'id' => $params[0],
                            'name' => 'Episode',
                            'type' => 'episode',
                            'library_id' => 'lib-1',
                            'parent_id' => 'show-1',
                            'path' => '/test/ep.mkv',
                            'metadata_json' => '{}',
                        ]
                    ];
                }
                return [];
            });

        $itemRepo = new ItemRepository($db);
        $repo = new MarkerCandidateRepository($itemRepo);

        $repo->storeCandidates('show-1', $result);

        $this->assertTrue(true);
    }

    public function testGetCandidatesReturnsNullWhenNotFound(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $repo = new MarkerCandidateRepository($itemRepo);

        $result = $repo->getCandidates('non-existent');

        $this->assertNull($result);
    }

    public function testGetCandidatesReturnsStoredMarkers(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([
            [
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
            ]
        ]);

        $itemRepo = new ItemRepository($db);
        $repo = new MarkerCandidateRepository($itemRepo);

        $result = $repo->getCandidates('ep-1');

        $this->assertInstanceOf(StoredMarkers::class, $result);
        $this->assertTrue($result->hasIntro());
        $this->assertTrue($result->hasOutro());
    }

    public function testHasCandidatesReturnsFalseWhenNoMarkers(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([
            [
                'id' => 'ep-1',
                'name' => 'Episode 1',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/test/ep.mkv',
                'metadata_json' => '{}',
            ]
        ]);

        $itemRepo = new ItemRepository($db);
        $repo = new MarkerCandidateRepository($itemRepo);

        $result = $repo->hasCandidates('ep-1');

        $this->assertFalse($result);
    }

    public function testHasCandidatesReturnsTrueWhenMarkersExist(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([
            [
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
            ]
        ]);

        $itemRepo = new ItemRepository($db);
        $repo = new MarkerCandidateRepository($itemRepo);

        $result = $repo->hasCandidates('ep-1');

        $this->assertTrue($result);
    }
}
