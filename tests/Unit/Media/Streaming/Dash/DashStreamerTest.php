<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Dash;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\DashStreamer;
use Phlix\Media\Streaming\Dash\AdaptationSet;
use Phlix\Media\Streaming\Dash\Representation;
use Phlix\Media\Streaming\Dash\SegmentTemplate;
use Phlix\Tests\Support\Dash\MpdSchema;

class DashStreamerTest extends TestCase
{
    private DashStreamer $dashStreamer;
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_test_dash_' . uniqid();
        mkdir($this->segmentDir, 0755, true);

        $this->dashStreamer = new DashStreamer(
            $this->segmentDir,
            'http://localhost:8096'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupDirectory($this->segmentDir);
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob("{$dir}/*") ?: [];
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->cleanupDirectory($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    public function testGenerateMasterMpd(): void
    {
        $mpd = $this->dashStreamer->generateMasterMpd('test-job', [$this->videoSet(), $this->audioSet()], 1234.5);

        $this->assertStringContainsString('<MPD', $mpd);
        $this->assertStringContainsString(DashStreamer::PROFILE_ISOFF_LIVE, $mpd);
        $this->assertStringContainsString('<AdaptationSet', $mpd);
        $this->assertSame([], MpdSchema::errors($mpd), 'the MPD must validate against the real DASH schema');
    }

    /**
     * The pre-S58 hardcodes: `mediaPresentationDuration="PT0H0M0S"` (an empty
     * presentation, so nothing could ever be played) and `Period@duration`
     * `"PT0H1M0S"` (a bogus one minute that could disagree with it). The real
     * length is now a required argument and the Period carries a start instead.
     */
    public function testTheDurationIsTheRealOneAndThePeriodCarriesAStartNotABogusDuration(): void
    {
        $mpd = $this->dashStreamer->generateMasterMpd('test-job', [$this->videoSet()], 1234.5);

        $this->assertStringContainsString('mediaPresentationDuration="PT1234.500S"', $mpd);
        $this->assertStringNotContainsString('PT0H0M0S', $mpd);
        $this->assertStringNotContainsString('PT0H1M0S', $mpd);
        $this->assertStringContainsString('<Period id="1" start="PT0S"', $mpd);
        $this->assertStringNotContainsString('<Period id="1" duration=', $mpd);
    }

    public function testAZeroDurationIsRefusedRatherThanPublishedAsAnEmptyPresentation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dashStreamer->generateMasterMpd('test-job', [$this->videoSet()], 0.0);
    }

    /**
     * A Period with no AdaptationSet is schema-valid and completely unplayable,
     * and its mere existence on disk looks like a working manifest.
     */
    public function testAnEmptyAdaptationSetListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->dashStreamer->generateMasterMpd('test-job', [], 12.0);
    }

    public function testTheJobIdBecomesTheMpdId(): void
    {
        $mpd = $this->dashStreamer->generateMasterMpd('job-abc', [$this->videoSet()], 12.0);

        $this->assertStringContainsString('id="job-abc"', $mpd);
    }

    public function testTheAdaptationSetCacheRecordsTheHighestRepresentationBandwidth(): void
    {
        $this->dashStreamer->generateMasterMpd('test-job', [$this->videoSet(), $this->audioSet()], 12.0);

        $this->assertSame(
            [
                0 => ['id' => 0, 'content_type' => 'video', 'bandwidth' => 5000000],
                1 => ['id' => 1, 'content_type' => 'audio', 'bandwidth' => 128000],
            ],
            $this->dashStreamer->getCachedAdaptationSets()
        );
    }

    /**
     * @dataProvider durations
     */
    public function testXsDurationRoundsUpToTheMillisecond(float $seconds, string $expected): void
    {
        $this->assertSame($expected, DashStreamer::xsDuration($seconds));
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function durations(): array
    {
        return [
            'exact' => [6.0, 'PT6.000S'],
            'rounds up, never down' => [24.0834567, 'PT24.084S'],
            'a sliver is still a millisecond' => [0.0000001, 'PT0.001S'],
            'already at a millisecond boundary' => [1.234, 'PT1.234S'],
        ];
    }

    private function videoSet(): AdaptationSet
    {
        return new AdaptationSet(
            0,
            'video',
            'video/mp4',
            SegmentTemplate::fromSeconds(
                6,
                0,
                'seg-v$RepresentationID$-$Number%05d$.m4s',
                'init-v$RepresentationID$.m4s'
            ),
            [new Representation('1080p', 'avc1.64001f', 5000000, 1920, 1080)],
            null,
            AdaptationSet::ROLE_MAIN
        );
    }

    private function audioSet(): AdaptationSet
    {
        return new AdaptationSet(
            1,
            'audio',
            'audio/mp4',
            SegmentTemplate::fromSeconds(
                6,
                0,
                'seg-$RepresentationID$-$Number%05d$.m4s',
                'init-$RepresentationID$.m4s'
            ),
            [new Representation('a0', 'mp4a.40.2', 128000)],
            'eng',
            AdaptationSet::ROLE_MAIN
        );
    }

    public function testGenerateAdaptationSetMpd(): void
    {
        $segments = [
            ['duration' => 6.0, 'url' => 'segment_1.m4s'],
            ['duration' => 6.0, 'url' => 'segment_2.m4s'],
        ];

        $mpd = $this->dashStreamer->generateAdaptationSetMpd('test-job', 0, $segments, [
            'content_type' => 'video',
            'bandwidth' => 5000000,
            'width' => 1920,
            'height' => 1080,
            'codec' => 'avc1.64001f',
        ]);

        $this->assertStringContainsString('<MPD', $mpd);
        $this->assertStringContainsString('SegmentTemplate', $mpd);
        $this->assertStringContainsString('initialization', $mpd);
        $this->assertStringContainsString('media', $mpd);
    }

    public function testGetMasterMpdUrl(): void
    {
        $url = $this->dashStreamer->getMasterMpdUrl('job-123');

        $this->assertEquals('/dash/job-123/manifest.mpd', $url);
    }

    public function testGetAdaptationSetMpdUrl(): void
    {
        $url = $this->dashStreamer->getAdaptationSetMpdUrl('job-123', 1);

        $this->assertEquals('/dash/job-123/1/manifest.mpd', $url);
    }

    public function testGetSegmentPath(): void
    {
        $path = $this->dashStreamer->getSegmentPath('job-123', 0, 5);

        $this->assertStringContainsString('job-123', $path);
        $this->assertStringContainsString('segment_0_00005', $path);
        $this->assertStringEndsWith('.m4s', $path);
    }

    public function testSaveMpd(): void
    {
        $jobId = 'test-job';
        $content = '<?xml version="1.0"?><MPD></MPD>';
        $filename = 'manifest.mpd';

        $this->dashStreamer->saveMpd($jobId, $content, $filename);

        $expectedPath = "{$this->segmentDir}/{$jobId}/{$filename}";
        $this->assertTrue(file_exists($expectedPath));
        $this->assertEquals($content, file_get_contents($expectedPath));
    }

    public function testSaveSegment(): void
    {
        $jobId = 'test-job';
        $setId = 0;
        $segmentNumber = 1;
        $content = 'segment data';

        $this->dashStreamer->saveSegment($jobId, $setId, $segmentNumber, $content);

        $path = $this->dashStreamer->getSegmentPath($jobId, $setId, $segmentNumber);
        $this->assertTrue(file_exists($path));
        $this->assertEquals($content, file_get_contents($path));
    }

    public function testCleanupJob(): void
    {
        $jobId = 'cleanup-test-job';
        $jobDir = "{$this->segmentDir}/{$jobId}";
        mkdir($jobDir, 0755, true);
        file_put_contents("{$jobDir}/manifest.mpd", 'test content');
        file_put_contents("{$jobDir}/segment_0_00001.m4s", 'segment');

        $this->assertTrue(is_dir($jobDir));

        $this->dashStreamer->cleanupJob($jobId);

        $this->assertFalse(is_dir($jobDir));
    }

    public function testGetJobDirectory(): void
    {
        $jobId = 'test-job';
        $dir = $this->dashStreamer->getJobDirectory($jobId);

        $this->assertEquals("{$this->segmentDir}/{$jobId}", $dir);
    }

    public function testGetSegmentUrl(): void
    {
        $url = $this->dashStreamer->getSegmentUrl('job-123', 0, 5);

        $this->assertEquals('/dash/job-123/0/segment_00005.m4s', $url);
    }
}
