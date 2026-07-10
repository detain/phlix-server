<?php

/**
 * Phlix media server tests: Library.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Transcoding\FfmpegRunner;
use Psr\Log\NullLogger;

/**
 * Lazy playback-info stream backfill (migration 071): pre-071 items get ONE
 * blocking ffprobe on their first playback-info request; the
 * `streams_probed_at` marker (stamped on success AND failure) guarantees the
 * probe never runs twice — including for files that genuinely have one audio
 * track and no subtitles.
 */
class StreamProbeBackfillTest extends TestCase
{
    /** @var list<string> Temp files to unlink in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /** Creates a real (empty) media file on disk so the is_file() gate passes. */
    private function makeTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-probe-');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string, mixed> A realistic multi-track ffprobe result. */
    private function multiTrackProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264',
                 'width' => 1920, 'height' => 1080, 'bit_rate' => '5000000'],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'ac3',
                 'channels' => 6, 'disposition' => ['default' => 1],
                 'tags' => ['language' => 'eng', 'title' => 'Surround 5.1']],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
                ['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'subrip',
                 'tags' => ['language' => 'eng']],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    /**
     * The single pre-071 shape that triggers the backfill: one audio row and
     * no subtitle rows.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyStreams(): array
    {
        return [
            ['id' => 's-v', 'stream_type' => 'video', 'stream_index' => 0, 'codec' => 'h264'],
            ['id' => 's-a', 'stream_type' => 'audio', 'stream_index' => 1, 'codec' => 'aac'],
        ];
    }

    public function testUnprobedItemGetsProbedPersistedMarkedAndReRead(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->with($path)->willReturn($this->multiTrackProbe());

        $fresh = [['id' => 'new-1', 'stream_type' => 'video'], ['id' => 'new-2', 'stream_type' => 'subtitle']];
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('deleteStreamsByItem')->with('movie-1');
        $added = [];
        $repo->expects($this->exactly(4))->method('addStream')
            ->willReturnCallback(function (string $itemId, array $data) use (&$added): string {
                $added[] = $data;
                return 'stream-' . count($added);
            });
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');
        $repo->expects($this->once())->method('getItemStreams')->with('movie-1')->willReturn($fresh);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $result = $backfill->ensureFor($item, $this->legacyStreams());

        $this->assertSame($fresh, $result, 'returns the re-read rows after persisting');
        // The full set flowed through: 1 video + 2 audio + 1 subtitle, with
        // the scan-time metadata fields (channels/title/is_default).
        $this->assertSame(
            ['video', 'audio', 'audio', 'subtitle'],
            array_map(fn ($s) => $s['stream_type'], $added)
        );
        $this->assertSame(6, $added[1]['channels']);
        $this->assertSame('Surround 5.1', $added[1]['title']);
        $this->assertSame(1, $added[1]['is_default']);
    }

    public function testProbedMarkerPreventsSecondProbe(): void
    {
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // The whole point: across BOTH requests the blocking probe runs once.
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->multiTrackProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('getItemStreams')->willReturn([['id' => 'new', 'stream_type' => 'subtitle']]);
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        // First playback-info request: unprobed → probes + stamps the marker.
        $backfill->ensureFor(['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null], $this->legacyStreams());

        // Second request re-reads the item, which now carries the marker —
        // even though its rows would still "look" legacy, no probe runs.
        $stored = $this->legacyStreams();
        $result = $backfill->ensureFor(
            ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => '2026-07-10 12:00:00'],
            $stored
        );
        $this->assertSame($stored, $result, 'marked item is served its stored rows untouched');
    }

    public function testFullyProbedLookingRowsAreTrustedWithoutProbing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        // Any subtitle row → trusted.
        $withSubs = [['stream_type' => 'video'], ['stream_type' => 'subtitle']];
        $this->assertSame(
            $withSubs,
            $backfill->ensureFor(['id' => 'm1', 'path' => $this->makeTempFile(), 'streams_probed_at' => null], $withSubs)
        );

        // Two audio rows → trusted.
        $twoAudio = [['stream_type' => 'audio'], ['stream_type' => 'audio']];
        $this->assertSame(
            $twoAudio,
            $backfill->ensureFor(['id' => 'm2', 'path' => $this->makeTempFile(), 'streams_probed_at' => null], $twoAudio)
        );
    }

    public function testProbeFailureMarksProbedAndDegradesToStoredRows(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturn(null); // ffprobe failed

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        // Stamped anyway — a broken file must not re-run the probe every request.
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testProbeThrowingMarksProbedAndDegradesToStoredRows(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willThrowException(new \RuntimeException('boom'));

        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testMissingFileSkipsProbeWithoutStampingSoItCanRetryLater(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        // NOT stamped: the item keeps its one-shot probe for when the file
        // re-appears (e.g. a temporarily unmounted share).
        $repo->expects($this->never())->method('markStreamsProbed');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();
        $item = ['id' => 'movie-1', 'path' => '/nonexistent/movie.mkv', 'streams_probed_at' => null];

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testEmptyProbedStreamSetKeepsStoredRowsButStillMarks(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        // Probe succeeds but exposes no playable streams (image/data-only).
        $ffmpeg->expects($this->once())->method('probe')->willReturn(['streams' => [], 'format' => []]);

        $repo = $this->createMock(ItemRepository::class);
        // Existing rows are NOT wiped for an empty replacement set.
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        $repo->expects($this->once())->method('markStreamsProbed')->with('movie-1');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }

    public function testItemWithoutIdIsServedUnchanged(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor([], $stored));
    }

    public function testMarkerWriteFailureInsideCatchIsSwallowed(): void
    {
        $path = $this->makeTempFile();
        $item = ['id' => 'movie-1', 'path' => $path, 'streams_probed_at' => null];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willThrowException(new \RuntimeException('probe boom'));

        $repo = $this->createMock(ItemRepository::class);
        // Pre-071 schema: even the marker UPDATE fails — must not escape.
        $repo->method('markStreamsProbed')->willThrowException(new \RuntimeException('no such column'));

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $stored = $this->legacyStreams();

        $this->assertSame($stored, $backfill->ensureFor($item, $stored));
    }
}
