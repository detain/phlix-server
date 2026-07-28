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
 * `StreamProbeBackfill::ensureVideoCodecFor()` — the NARROW trigger the media
 * DETAIL endpoint uses. It probes only when the item's video codec is genuinely
 * unknown (≥1 `video` row, none carrying a codec), because detail is hit on
 * every item view and the broad pre-071 trigger matches 79,218 of the production
 * library's 116,325 items — mostly music tracks on sshfs shares.
 *
 * {@see StreamProbeBackfillTest} covers `ensureFor()` (the playback-info
 * trigger); the probe/persist/stamp behaviour is shared code, so only the
 * trigger differences are pinned here.
 */
class StreamProbeBackfillVideoCodecTest extends TestCase
{
    /** @var list<string> Temp files unlinked in tearDown. */
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

    /** Real (empty) file on disk so the is_file() gate passes. */
    private function makeTempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-vcodec-');
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string, mixed> A probe result carrying a real video codec. */
    private function probeResult(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'hevc',
                 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    public function testProbesWhenTheOnlyVideoRowHasNoCodec(): void
    {
        $path = $this->makeTempFile();
        $fresh = [['stream_type' => 'video', 'codec' => 'hevc']];

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->with($path)->willReturn($this->probeResult());

        $repo = $this->createMock(ItemRepository::class);
        // ONE atomic replacement call carrying the whole probed set (the delete
        // and every insert live inside its transaction — see
        // ItemRepository::replaceStreams()).
        $replaced = null;
        $repo->expects($this->once())->method('replaceStreams')
            ->with('m1', $this->anything())
            ->willReturnCallback(function (string $itemId, array $streams) use (&$replaced): void {
                $replaced = $streams;
            });
        $repo->expects($this->never())->method('deleteStreamsByItem');
        $repo->expects($this->never())->method('addStream');
        $repo->expects($this->once())->method('markStreamsProbed')->with('m1');
        $repo->expects($this->once())->method('getItemStreams')->with('m1')->willReturn($fresh);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $result = $backfill->ensureVideoCodecFor(
            ['id' => 'm1', 'path' => $path, 'streams_probed_at' => null],
            [['stream_type' => 'video', 'codec' => null], ['stream_type' => 'audio', 'codec' => 'aac']]
        );

        $this->assertSame($fresh, $result);
        $this->assertIsArray($replaced);
        $this->assertSame(
            ['video', 'audio'],
            array_map(fn ($s) => is_array($s) ? $s['stream_type'] : null, $replaced),
            'the probed video + audio rows were handed to the atomic replacement'
        );
    }

    /**
     * The narrow trigger deliberately IGNORES looksFullyProbed(): a `video` row
     * with no codec is direct evidence the stored set is wrong, so a subtitle row
     * (or a second audio row) must not veto the repair the way it does for
     * ensureFor()'s pre-071 trigger.
     */
    public function testSubtitleRowDoesNotVetoTheVideoCodecRepair(): void
    {
        $path = $this->makeTempFile();

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->willReturn($this->probeResult());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('addStream')->willReturn('s');
        $repo->expects($this->once())->method('markStreamsProbed')->with('m2');
        $repo->method('getItemStreams')->willReturn([['stream_type' => 'video', 'codec' => 'hevc']]);

        $stored = [
            ['stream_type' => 'video', 'codec' => ''],
            ['stream_type' => 'audio', 'codec' => 'aac'],
            ['stream_type' => 'audio', 'codec' => 'ac3'],
            ['stream_type' => 'subtitle', 'codec' => 'subrip'],
        ];

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $result = $backfill->ensureVideoCodecFor(
            ['id' => 'm2', 'path' => $path, 'streams_probed_at' => null],
            $stored
        );

        $this->assertSame([['stream_type' => 'video', 'codec' => 'hevc']], $result);
    }

    /**
     * A junk `video` row with no codec alongside a real one is NOT a defect: the
     * client's videoCodecFromStreams() skips empty-codec rows and keeps looking,
     * so the codec is known and nothing must be probed. This is the shape 14
     * production items are actually in.
     */
    public function testExtraCodeclessVideoRowAlongsideARealOneIsNotProbed(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');
        $repo->expects($this->never())->method('getItemStreams');

        $stored = [
            ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'hevc'],
            ['stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac'],
            ['stream_index' => 2, 'stream_type' => 'video', 'codec' => null],
        ];

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        $this->assertSame(
            $stored,
            $backfill->ensureVideoCodecFor(
                ['id' => 'm3', 'path' => $this->makeTempFile(), 'streams_probed_at' => null],
                $stored
            )
        );
    }

    /**
     * No `video` row at all → never probed, whatever else the rows look like.
     * This is the ffprobe-storm guard: 61,130 unprobed music tracks (plus albums,
     * artists, and the `series`/`season` container rows whose `path` is a
     * directory) all land here.
     *
     * @param list<array<string, mixed>> $stored
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonVideoRowSets')]
    public function testItemsWithoutAVideoRowAreNeverProbed(array $stored): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');
        $repo->expects($this->never())->method('getItemStreams');

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        $this->assertSame(
            $stored,
            $backfill->ensureVideoCodecFor(
                ['id' => 'm4', 'path' => $this->makeTempFile(), 'streams_probed_at' => null],
                $stored
            )
        );
    }

    /** @return array<string, array{list<array<string, mixed>>}> */
    public static function nonVideoRowSets(): array
    {
        return [
            'music track (1 audio row, no subtitles — the broad trigger shape)' => [
                [['stream_type' => 'audio', 'codec' => 'flac']],
            ],
            'series/season container row (no stream rows at all)' => [[]],
            'audio + subtitle only' => [
                [['stream_type' => 'audio', 'codec' => 'aac'], ['stream_type' => 'subtitle', 'codec' => 'subrip']],
            ],
        ];
    }

    public function testMarkerGuardStillPreventsTheProbe(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');

        $stored = [['stream_type' => 'video', 'codec' => null]];
        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        $this->assertSame(
            $stored,
            $backfill->ensureVideoCodecFor(
                ['id' => 'm5', 'path' => $this->makeTempFile(), 'streams_probed_at' => '2026-07-16 04:16:42'],
                $stored
            )
        );
    }

    /**
     * "Is the codec known?" uses the same leniency as the client's
     * videoCodecFromStreams(): trimmed + case-insensitive `stream_type`, trimmed
     * `codec`, junk entries skipped. A whitespace-only codec is UNKNOWN.
     */
    public function testCodecDetectionMatchesTheClientLeniency(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);

        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());
        $item = ['id' => 'm6', 'path' => $this->makeTempFile(), 'streams_probed_at' => null];

        // Upper-case type + padded codec still counts as KNOWN → no probe.
        $stored = [['stream_type' => ' Video ', 'codec' => ' h264 ']];
        $this->assertSame($stored, $backfill->ensureVideoCodecFor($item, $stored));

        // A row with a non-string stream_type is skipped, not fatal — and the
        // remaining rows carry no video row, so still no probe.
        $withJunk = [['stream_type' => 42, 'codec' => 'aac'], ['stream_type' => 'audio', 'codec' => 'aac']];
        $this->assertSame($withJunk, $backfill->ensureVideoCodecFor($item, $withJunk));
    }

    /**
     * A missing file on disk is NOT stamped, so the one-shot probe still happens
     * once the share is back — same degrade contract as ensureFor().
     */
    public function testMissingFileSkipsTheProbeWithoutStamping(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);
        $repo->expects($this->never())->method('markStreamsProbed');

        $stored = [['stream_type' => 'video', 'codec' => null]];
        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        $this->assertSame(
            $stored,
            $backfill->ensureVideoCodecFor(
                ['id' => 'm7', 'path' => '/vault1/gone/never-mounted.mkv', 'streams_probed_at' => null],
                $stored
            )
        );
    }

    /** An item with no usable id can never be probed (nothing to key writes on). */
    public function testBlankItemIdIsANoOp(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');
        $repo = $this->createMock(ItemRepository::class);

        $stored = [['stream_type' => 'video', 'codec' => null]];
        $backfill = new StreamProbeBackfill($repo, $ffmpeg, new NullLogger());

        $this->assertSame(
            $stored,
            $backfill->ensureVideoCodecFor(['id' => null, 'path' => $this->makeTempFile()], $stored)
        );
    }
}
