<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Recording\ChapterMarkerService;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\ChapterMarker;

/**
 * @since 0.12.0
 */
class ChapterMarkerServiceTest extends TestCase
{
    private ChapterMarkerService $service;
    private $mockItemRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockItemRepo = $this->createMock(ItemRepository::class);
        $this->service = new ChapterMarkerService($this->mockItemRepo);
    }

    public function testToHlsChaptersFormatsCorrectly(): void
    {
        $edlSegments = [
            new ChapterMarker(0, 30, 'Commercial @ 00:00:00 (30s)'),
            new ChapterMarker(60, 120, 'Commercial @ 00:01:00 (60s)'),
            new ChapterMarker(180, 210, 'Commercial @ 00:03:00 (30s)'),
        ];

        $chapters = $this->service->toHlsChapters($edlSegments);

        $this->assertCount(3, $chapters);
        $this->assertEquals(0, $chapters[0]['start']);
        $this->assertEquals(30, $chapters[0]['end']);
        $this->assertEquals('Commercial @ 00:00:00 (30s)', $chapters[0]['title']);
        $this->assertEquals(60, $chapters[1]['start']);
        $this->assertEquals(120, $chapters[1]['end']);
        $this->assertEquals(180, $chapters[2]['start']);
        $this->assertEquals(210, $chapters[2]['end']);
    }

    public function testToHlsChaptersSortsByStartTime(): void
    {
        // Out of order input
        $edlSegments = [
            new ChapterMarker(180, 210, 'Third'),
            new ChapterMarker(0, 30, 'First'),
            new ChapterMarker(60, 120, 'Second'),
        ];

        $chapters = $this->service->toHlsChapters($edlSegments);

        // Should be sorted by start time
        $this->assertEquals(0, $chapters[0]['start']);
        $this->assertEquals('First', $chapters[0]['title']);
        $this->assertEquals(60, $chapters[1]['start']);
        $this->assertEquals('Second', $chapters[1]['title']);
        $this->assertEquals(180, $chapters[2]['start']);
        $this->assertEquals('Third', $chapters[2]['title']);
    }

    public function testToHlsChaptersHandlesEmptySegments(): void
    {
        $chapters = $this->service->toHlsChapters([]);
        $this->assertCount(0, $chapters);
    }

    public function testToHlsChaptersSkipsInvalidRanges(): void
    {
        $edlSegments = [
            new ChapterMarker(120, 60, 'Invalid range'), // end < start
            new ChapterMarker(0, 30, 'Valid'),
        ];

        $chapters = $this->service->toHlsChapters($edlSegments);

        $this->assertCount(1, $chapters);
        $this->assertEquals('Valid', $chapters[0]['title']);
    }

    public function testToHlsChaptersHandlesArrayInput(): void
    {
        $edlSegments = [
            ['start_seconds' => 0, 'end_seconds' => 30, 'title' => 'Array segment'],
        ];

        $chapters = $this->service->toHlsChapters($edlSegments);

        $this->assertCount(1, $chapters);
        $this->assertEquals(0, $chapters[0]['start']);
        $this->assertEquals(30, $chapters[0]['end']);
        $this->assertEquals('Array segment', $chapters[0]['title']);
    }

    public function testPersistChaptersStoresInMetadataJson(): void
    {
        $mediaItemId = 'media-item-id';
        $edlSegments = [
            new ChapterMarker(0, 30, 'Commercial'),
        ];

        $existingMetadata = ['title' => 'Test Show'];

        $this->mockItemRepo
            ->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn([
                'id' => $mediaItemId,
                'metadata_json' => json_encode($existingMetadata),
            ]);

        $this->mockItemRepo
            ->expects($this->once())
            ->method('update')
            ->with(
                $mediaItemId,
                $this->callback(function ($data) {
                    if (!isset($data['metadata_json'])) {
                        return false;
                    }
                    $decoded = json_decode($data['metadata_json'], true);
                    return isset($decoded['commercial_chapters'])
                        && count($decoded['commercial_chapters']) === 1
                        && $decoded['title'] === 'Test Show';
                })
            );

        $this->service->persistChapters($mediaItemId, $edlSegments);
    }

    public function testPersistChaptersHandlesNullItem(): void
    {
        $mediaItemId = 'nonexistent';

        $this->mockItemRepo
            ->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn(null);

        // Should not throw, just return early
        $this->service->persistChapters($mediaItemId, []);
    }

    public function testGetChaptersRetrievesStored(): void
    {
        $mediaItemId = 'media-item-id';
        $chaptersData = [
            ['start' => 0, 'end' => 30, 'title' => 'Commercial 1'],
            ['start' => 60, 'end' => 120, 'title' => 'Commercial 2'],
        ];

        $this->mockItemRepo
            ->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn([
                'id' => $mediaItemId,
                'metadata_json' => json_encode(['commercial_chapters' => $chaptersData]),
            ]);

        $chapters = $this->service->getChapters($mediaItemId);

        $this->assertCount(2, $chapters);
        $this->assertEquals(0, $chapters[0]['start']);
        $this->assertEquals(30, $chapters[0]['end']);
        $this->assertEquals('Commercial 1', $chapters[0]['title']);
        $this->assertEquals(60, $chapters[1]['start']);
        $this->assertEquals(120, $chapters[1]['end']);
    }

    public function testGetChaptersReturnsEmptyWhenNoChapters(): void
    {
        $mediaItemId = 'media-item-id';

        $this->mockItemRepo
            ->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn([
                'id' => $mediaItemId,
                'metadata_json' => json_encode(['title' => 'Test Show']),
            ]);

        $chapters = $this->service->getChapters($mediaItemId);

        $this->assertCount(0, $chapters);
    }

    public function testGetChaptersReturnsEmptyWhenItemNotFound(): void
    {
        $mediaItemId = 'nonexistent';

        $this->mockItemRepo
            ->expects($this->once())
            ->method('findById')
            ->with($mediaItemId)
            ->willReturn(null);

        $chapters = $this->service->getChapters($mediaItemId);

        $this->assertCount(0, $chapters);
    }

    public function testGenerateHlsChapterContent(): void
    {
        $chapters = [
            ['start' => 0, 'end' => 30, 'title' => 'Commercial 1'],
            ['start' => 60, 'end' => 120, 'title' => 'Commercial 2'],
        ];

        $content = $this->service->generateHlsChapterContent($chapters);

        $this->assertStringContainsString('#EXTM3U', $content);
        $this->assertStringContainsString('#EXTINF:00:00:00,Commercial 1', $content);
        $this->assertStringContainsString('#EXTINF:00:01:00,Commercial 2', $content);
        $this->assertStringContainsString('#EXTX-CUE-PRICE:00:00:00 - 00:00:30', $content);
        $this->assertStringContainsString('#EXTX-CUE-PRICE:00:01:00 - 00:02:00', $content);
    }

    public function testToHlsChaptersHandlesNullTitle(): void
    {
        $edlSegments = [
            new ChapterMarker(0, 30, null),
        ];

        $chapters = $this->service->toHlsChapters($edlSegments);

        $this->assertCount(1, $chapters);
        $this->assertNull($chapters[0]['title']);
    }
}
