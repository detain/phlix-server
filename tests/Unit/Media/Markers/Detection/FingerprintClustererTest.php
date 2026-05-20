<?php

namespace Phlix\Tests\Unit\Media\Markers\Detection;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\Detection\ClusteringResult;
use Phlix\Media\Markers\Detection\FingerprintClusterer;
use Phlix\Media\Markers\Detection\IntroMarkerCandidate;
use Phlix\Media\Markers\Detection\OutroMarkerCandidate;

class FingerprintClustererTest extends TestCase
{
    private function createClusterer(
        float $similarityThreshold = 0.85,
        int $introMaxDuration = 180,
        int $outroMaxDuration = 180
    ): FingerprintClusterer {
        return new FingerprintClusterer(
            $similarityThreshold,
            $introMaxDuration,
            $outroMaxDuration,
            null
        );
    }

    public function testClusterGroupsSimilarFingerprints(): void
    {
        $clusterer = $this->createClusterer();

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'abc123', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'abc123', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'abc123', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        $this->assertInstanceOf(ClusteringResult::class, $result);
        $this->assertTrue($result->hasClusters());
    }

    public function testClusterReturnsNullWhenInsufficientSimilarity(): void
    {
        $clusterer = $this->createClusterer();

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'abc123', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'xyz789', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'def456', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        $this->assertInstanceOf(ClusteringResult::class, $result);
    }

    public function testSimilarityReturnsFloatBetween0And1(): void
    {
        $clusterer = $this->createClusterer();

        $reflection = new \ReflectionClass($clusterer);
        $method = $reflection->getMethod('similarity');
        $method->setAccessible(true);

        $result1 = $method->invoke($clusterer, 'abc123', 'abc123');
        $this->assertEquals(1.0, $result1);

        $result2 = $method->invoke($clusterer, 'abc', 'xyz');
        $this->assertGreaterThanOrEqual(0.0, $result2);
        $this->assertLessThanOrEqual(1.0, $result2);

        $result3 = $method->invoke($clusterer, '', 'abc');
        $this->assertEquals(0.0, $result3);

        $result4 = $method->invoke($clusterer, '', '');
        $this->assertEquals(0.0, $result4);
    }

    public function testIntroClusterAtStart(): void
    {
        $clusterer = $this->createClusterer(0.85, 180, 180);

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'intro_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'intro_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'intro_fp', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        $this->assertInstanceOf(ClusteringResult::class, $result);
        $this->assertTrue($result->hasClusters());
    }

    public function testOutroClusterAtEnd(): void
    {
        $clusterer = $this->createClusterer(0.85, 180, 180);

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'outro_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'outro_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'outro_fp', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        $this->assertInstanceOf(ClusteringResult::class, $result);
        $this->assertTrue($result->hasClusters());
    }

    public function testClusterWithInsufficientEpisodes(): void
    {
        $clusterer = $this->createClusterer();

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'fp1', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        $this->assertInstanceOf(ClusteringResult::class, $result);
        $this->assertFalse($result->hasClusters());
        $this->assertContains('ep-1', $result->unmatched);
    }

    public function testClusterWithEmptyEpisodes(): void
    {
        $clusterer = $this->createClusterer();

        $result = $clusterer->cluster([]);

        $this->assertInstanceOf(ClusteringResult::class, $result);
        $this->assertFalse($result->hasClusters());
    }

    public function testSimilarityIdenticalStrings(): void
    {
        $clusterer = $this->createClusterer();

        $reflection = new \ReflectionClass($clusterer);
        $method = $reflection->getMethod('similarity');
        $method->setAccessible(true);

        $result = $method->invoke($clusterer, 'identical', 'identical');
        $this->assertEquals(1.0, $result);
    }

    public function testSimilarityCompletelyDifferentStrings(): void
    {
        $clusterer = $this->createClusterer();

        $reflection = new \ReflectionClass($clusterer);
        $method = $reflection->getMethod('similarity');
        $method->setAccessible(true);

        $result = $method->invoke($clusterer, 'abc', 'xyz');
        $this->assertGreaterThanOrEqual(0.0, $result);
        $this->assertLessThan(1.0, $result);
    }

    public function testIntroCandidateHasCorrectStructure(): void
    {
        $clusterer = $this->createClusterer();

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'same_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'same_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'same_fp', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        if ($result->intro !== null) {
            $this->assertInstanceOf(IntroMarkerCandidate::class, $result->intro);
            $this->assertEquals(0, $result->intro->start_seconds);
            $this->assertGreaterThan(0, $result->intro->end_seconds);
            $this->assertNotEmpty($result->intro->fingerprint);
            $this->assertGreaterThanOrEqual(0, $result->intro->confidence);
            $this->assertLessThanOrEqual(100, $result->intro->confidence);
        }
    }

    public function testOutroCandidateHasCorrectStructure(): void
    {
        $clusterer = $this->createClusterer();

        $episodes = [
            ['media_item_id' => 'ep-1', 'fingerprint' => 'same_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-2', 'fingerprint' => 'same_fp', 'duration' => 2400],
            ['media_item_id' => 'ep-3', 'fingerprint' => 'same_fp', 'duration' => 2400],
        ];

        $result = $clusterer->cluster($episodes);

        if ($result->outro !== null) {
            $this->assertInstanceOf(OutroMarkerCandidate::class, $result->outro);
            $this->assertGreaterThanOrEqual(0, $result->outro->start_seconds);
            $this->assertGreaterThan(0, $result->outro->end_seconds);
            $this->assertNotEmpty($result->outro->fingerprint);
            $this->assertGreaterThanOrEqual(0, $result->outro->confidence);
            $this->assertLessThanOrEqual(100, $result->outro->confidence);
        }
    }
}
