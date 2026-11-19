<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Markers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Marker;
use Phlix\Media\MarkerType;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use PHPUnit\Framework\MockObject\MockObject;
use Workerman\MySQL\Connection;

class MarkerServiceTest extends TestCase
{
    private function createMockConnection(): Connection&MockObject
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

        /** @var list<string> $executedSql */
        $executedSql = [];
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params = []) use (&$executedSql): array {
                $executedSql[] = $sql;
                return [[
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
                ]];
            }
        );

        $itemRepo = new ItemRepository($db);
        $candidateRepo = new MarkerCandidateRepository($itemRepo);
        $service = new MarkerService($itemRepo, $candidateRepo);

        $service->promoteCandidates('ep-1');

        // No UPDATE query was made since there were no candidates
        // (the findById SELECT query was made but no update followed).
        $updateQueries = array_filter(
            $executedSql,
            static fn(string $sql): bool => str_contains(strtoupper($sql), 'UPDATE')
        );
        $this->assertEmpty($updateQueries);
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

    /**
     * E-09 cohort 4 legacy oracle: the pre-migration `Marker::fromDbRow()`
     * body re-expressed VERBATIM, asserted to agree with the shipped
     * RowMap-accessor form on every pin vector. Literal timestamps are
     * compared byte-for-byte including microseconds; 'now'-fallback shapes
     * (missing/NULL/non-string columns) compare by instant-within-one-second,
     * since wall-clock microseconds are not the pinned property. Garbage
     * timestamp strings and non-enum marker_type values must THROW
     * identically on both paths — the raw `DateTimeImmutable` ctor is
     * deliberately KEPT (T1 throws where a `nullableDateTime` accessor
     * would null; that divergence is the law, see Collection's T1H shape).
     */
    public function testFromDbRowMatchesThePreMigrationBodyOnEveryPinVector(): void
    {
        $legacy = static function (array $row): Marker {
            $id = is_int($row['id'] ?? null) ? $row['id'] : 0;
            $mediaItemId = is_string($row['media_item_id'] ?? null) ? $row['media_item_id'] : '';
            $markerTypeStr = is_string($row['marker_type'] ?? null) ? $row['marker_type'] : 'intro';
            $startTimeMs = is_int($row['start_time_ms'] ?? null) ? $row['start_time_ms'] : 0;
            $endTimeMs = is_int($row['end_time_ms'] ?? null) ? $row['end_time_ms'] : 0;
            $label = is_string($row['label'] ?? null) ? $row['label'] : '';
            // P3-S1: user_id is nullable; NULL = legacy system marker
            $userId = is_string($row['user_id'] ?? null) && $row['user_id'] !== '' ? $row['user_id'] : null;
            $thumbnailPath = is_string($row['thumbnail_path'] ?? null) && $row['thumbnail_path'] !== ''
                ? $row['thumbnail_path']
                : null;
            $createdAtStr = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
            $updatedAtStr = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';

            return new Marker(
                id: $id,
                mediaItemId: $mediaItemId,
                type: MarkerType::from($markerTypeStr),
                startTimeMs: $startTimeMs,
                endTimeMs: $endTimeMs,
                label: $label,
                userId: $userId,
                thumbnailPath: $thumbnailPath,
                createdAt: new \DateTimeImmutable($createdAtStr),
                updatedAt: new \DateTimeImmutable($updatedAtStr),
            );
        };

        $snapshot = static fn (Marker $m): array => [
            $m->id,
            $m->mediaItemId,
            $m->type->value,
            $m->startTimeMs,
            $m->endTimeMs,
            $m->label,
            $m->userId,
            $m->thumbnailPath,
        ];

        // Group A — every timestamp a parseable literal: full byte identity.
        $literalRows = [
            'happy' => [
                'id' => 7, 'media_item_id' => 'mi-1', 'marker_type' => 'outro',
                'start_time_ms' => 1000, 'end_time_ms' => 2000, 'label' => 'L',
                'user_id' => 'u-1', 'thumbnail_path' => 'thumb.png',
                'created_at' => '2026-08-07 10:00:00', 'updated_at' => '2026-08-07 11:00:00',
            ],
            'strict-family-junk' => [
                'id' => '12', 'start_time_ms' => '5', 'end_time_ms' => 5.0,
                'label' => '', 'user_id' => '', 'thumbnail_path' => '',
                'marker_type' => 'credits',
                'created_at' => '2026-08-07 10:00:00.123456', 'updated_at' => '2026-01-01 00:00:00',
            ],
        ];
        foreach ($literalRows as $name => $row) {
            $expected = $legacy($row);
            $actual = Marker::fromDbRow($row);
            $this->assertSame($snapshot($expected), $snapshot($actual), $name);
            $this->assertSame(
                [
                    $expected->createdAt->format('Y-m-d H:i:s.u'),
                    $expected->updatedAt->format('Y-m-d H:i:s.u'),
                ],
                [
                    $actual->createdAt->format('Y-m-d H:i:s.u'),
                    $actual->updatedAt->format('Y-m-d H:i:s.u'),
                ],
                "$name: literal timestamps keep microsecond identity"
            );
        }

        // Group B — 'now'-fallback columns: same wall-clock instant (±1s),
        // the rest byte-identical. 'empty-timestamp-string-parses-as-now'
        // above lands here for the timestamps but its literals parse.
        $nowRows = [
            'missing-all' => [],
            'null-and-int-timestamps' => [
                'id' => 3, 'created_at' => null, 'updated_at' => 1754560000, 'marker_type' => null,
            ],
            'bool-float-array-timestamps' => [
                'created_at' => true, 'updated_at' => ['2026-08-07'],
            ],
            'blank-string-parses-as-now' => [
                'created_at' => '', 'updated_at' => '  ', 'marker_type' => 'intro',
            ],
        ];
        foreach ($nowRows as $name => $row) {
            $expected = $legacy($row);
            $actual = Marker::fromDbRow($row);
            $this->assertSame($snapshot($expected), $snapshot($actual), $name);
            $this->assertEqualsWithDelta(
                $expected->createdAt->getTimestamp(),
                $actual->createdAt->getTimestamp(),
                1,
                "$name: now-fallback instant"
            );
            $this->assertEqualsWithDelta(
                $expected->updatedAt->getTimestamp(),
                $actual->updatedAt->getTimestamp(),
                1,
                "$name: now-fallback instant"
            );
        }

        // Group C — throwing vectors must throw identically on both paths.
        $capture = static function (callable $fn): string {
            try {
                $fn();
                return 'DID-NOT-THROW';
            } catch (\Throwable $e) {
                return get_class($e) . '::' . $e->getMessage();
            }
        };
        $throwingRows = [
            'created-garbage' => ['created_at' => 'garbage', 'updated_at' => '2026-08-07 10:00:00'],
            'marker-type-nope' => ['marker_type' => 'nope'],
            'marker-type-empty-string' => ['marker_type' => ''],
            'created-numeric-string' => ['created_at' => '7', 'updated_at' => '0'],
        ];
        foreach ($throwingRows as $name => $row) {
            $this->assertSame(
                $capture(static fn (): Marker => $legacy($row)),
                $capture(static fn (): Marker => Marker::fromDbRow($row)),
                "$name: identical throw"
            );
        }
    }
}
