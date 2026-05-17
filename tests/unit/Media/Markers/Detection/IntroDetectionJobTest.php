<?php

namespace Phlex\Tests\Unit\Media\Markers\Detection;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\Detection\FingerprintClusterer;
use Phlex\Media\Markers\Detection\IntroDetectionJob;
use Phlex\Media\Markers\Detection\IntroDetectionResult;
use Phlex\Media\Markers\Detection\IntroMarkerCandidate;
use Phlex\Media\Markers\Detection\OutroMarkerCandidate;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintInterface;
use Phlex\Media\Markers\Fingerprinting\FingerprintRepository;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

class IntroDetectionJobTest extends TestCase
{
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    private function createMockChromaPrint(): ChromaPrintInterface
    {
        $mock = $this->createMock(ChromaPrintInterface::class);
        $mock->method('isAvailable')->willReturn(true);
        return $mock;
    }

    public function testIntroDetectionResultHasMarkersWhenIntroPresent(): void
    {
        $introCandidate = new IntroMarkerCandidate(0, 90, 'fp1', 85);

        $result = new IntroDetectionResult(
            show_id: 'show-1',
            episodes_fingerprinted: 3,
            intro_candidate: $introCandidate,
            outro_candidate: null,
            episodes_processed: ['ep-1', 'ep-2', 'ep-3'],
        );

        $this->assertTrue($result->hasMarkers());
    }

    public function testIntroDetectionResultHasMarkersWhenOutroPresent(): void
    {
        $outroCandidate = new OutroMarkerCandidate(2310, 2400, 'fp1', 80);

        $result = new IntroDetectionResult(
            show_id: 'show-1',
            episodes_fingerprinted: 3,
            intro_candidate: null,
            outro_candidate: $outroCandidate,
            episodes_processed: ['ep-1', 'ep-2', 'ep-3'],
        );

        $this->assertTrue($result->hasMarkers());
    }

    public function testIntroDetectionResultHasNoMarkersWhenNeitherPresent(): void
    {
        $result = new IntroDetectionResult(
            show_id: 'show-1',
            episodes_fingerprinted: 0,
            intro_candidate: null,
            outro_candidate: null,
            episodes_processed: [],
        );

        $this->assertFalse($result->hasMarkers());
    }

    public function testDetectForShowReturnsResult(): void
    {
        $db = $this->createMockConnection();

        $db->method('query')->willReturn([
            [
                'id' => 'ep-1',
                'name' => 'Episode 1',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e01.mkv',
                'metadata_json' => '{"fingerprint": "fp1", "duration": 2400}',
            ],
            [
                'id' => 'ep-2',
                'name' => 'Episode 2',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e02.mkv',
                'metadata_json' => '{"fingerprint": "fp1", "duration": 2400}',
            ],
            [
                'id' => 'ep-3',
                'name' => 'Episode 3',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e03.mkv',
                'metadata_json' => '{"fingerprint": "fp1", "duration": 2400}',
            ],
        ]);

        $itemRepo = new ItemRepository($db);
        $fingerprintRepo = new FingerprintRepository($itemRepo);
        $chromaPrint = $this->createMockChromaPrint();

        $job = new IntroDetectionJob(
            $fingerprintRepo,
            $itemRepo,
            $chromaPrint,
            new NullLogger(),
            3
        );

        $result = $job->detectForShow('show-1');

        $this->assertInstanceOf(IntroDetectionResult::class, $result);
        $this->assertEquals('show-1', $result->show_id);
    }

    public function testDetectForShowNeedsMinEpisodes(): void
    {
        $db = $this->createMockConnection();

        $db->method('query')->willReturn([
            [
                'id' => 'ep-1',
                'name' => 'Episode 1',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e01.mkv',
                'metadata_json' => '{"fingerprint": "fp1", "duration": 2400}',
            ],
            [
                'id' => 'ep-2',
                'name' => 'Episode 2',
                'type' => 'episode',
                'library_id' => 'lib-1',
                'parent_id' => 'show-1',
                'path' => '/shows/test/s01e02.mkv',
                'metadata_json' => '{"fingerprint": "fp1", "duration": 2400}',
            ],
        ]);

        $itemRepo = new ItemRepository($db);
        $fingerprintRepo = new FingerprintRepository($itemRepo);
        $chromaPrint = $this->createMockChromaPrint();

        $job = new IntroDetectionJob(
            $fingerprintRepo,
            $itemRepo,
            $chromaPrint,
            new NullLogger(),
            3
        );

        $result = $job->detectForShow('show-1');

        $this->assertInstanceOf(IntroDetectionResult::class, $result);
        $this->assertNull($result->intro_candidate);
        $this->assertNull($result->outro_candidate);
        $this->assertEquals(0, $result->episodes_fingerprinted);
    }

    public function testDetectAllPendingYieldsGenerator(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $fingerprintRepo = new FingerprintRepository($itemRepo);
        $chromaPrint = $this->createMockChromaPrint();

        $job = new IntroDetectionJob(
            $fingerprintRepo,
            $itemRepo,
            $chromaPrint,
            new NullLogger(),
            3
        );

        $results = $job->detectAllPending();

        $this->assertInstanceOf(\Generator::class, $results);

        // Iterate the generator to exercise the code path
        $count = 0;
        foreach ($results as $result) {
            $count++;
            $this->assertInstanceOf(IntroDetectionResult::class, $result);
        }
        // No shows were enqueued, so we should get 0 iterations
        $this->assertEquals(0, $count);
    }

    public function testDetectForShowReturnsEmptyResultWhenNoEpisodes(): void
    {
        $db = $this->createMockConnection();
        $db->method('query')->willReturn([]);

        $itemRepo = new ItemRepository($db);
        $fingerprintRepo = new FingerprintRepository($itemRepo);
        $chromaPrint = $this->createMockChromaPrint();

        $job = new IntroDetectionJob(
            $fingerprintRepo,
            $itemRepo,
            $chromaPrint,
            new NullLogger(),
            3
        );

        $result = $job->detectForShow('empty-show');

        $this->assertInstanceOf(IntroDetectionResult::class, $result);
        $this->assertEquals('empty-show', $result->show_id);
        $this->assertEquals(0, $result->episodes_fingerprinted);
        $this->assertEmpty($result->episodes_processed);
    }
}
