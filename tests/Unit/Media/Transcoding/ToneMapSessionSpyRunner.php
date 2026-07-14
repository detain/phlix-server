<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * SV-1.1 SESSION test double: a spy {@see FfmpegRunner} that counts EVERY
 * probe()/needsToneMapping()/getToneMappingProfile() call across a whole
 * simulated multi-segment playback session so
 * {@see TranscodeManagerTest::testMultiSegmentPlaybackSessionMakesAtMostOneProbe}
 * can assert the TOTAL probe count is ≤1 (the single {@see TranscodeManager::ensureHlsJob()}
 * subtitle/audio-detection probe) with ZERO per-segment HDR re-derivation — the
 * exact "~3 probes per segment" storm plan §2 SV-1.1 (L654-662) exists to kill.
 *
 * Unlike the sibling {@see ToneMapThreadingSpyRunner} (whose probe() returns null,
 * for the FfmpegRunner-level builder unit tests), this spy returns a fixed,
 * correctly-shaped HDR HEVC 4K probe so the REAL {@see TranscodeManager::ensureHlsJob()}
 * runs end-to-end, and overrides {@see startSegmentEncode()} to drive the REAL
 * {@see FfmpegRunner::buildSegmentCommand()} — the code path where a per-segment
 * tone-map re-derive would live — WITHOUT spawning ffmpeg, publishing the segment
 * file directly so {@see TranscodeManager::produceSegment()}'s poll resolves at once.
 *
 * A named (PSR-4) class rather than an anonymous one so its counters AND the
 * inherited FfmpegRunner command builders are both visible to static analysis.
 */
final class ToneMapSessionSpyRunner extends FfmpegRunner
{
    public int $probeCalls = 0;
    public int $needsToneMappingCalls = 0;
    public int $getToneMappingProfileCalls = 0;

    /**
     * @param string|null $fallbackFilter tone-map string a per-segment re-derive WOULD
     *                                     return (kept non-null so a re-deriving mutant
     *                                     is caught by BOTH the counter AND a visibly
     *                                     different `-vf`).
     */
    public function __construct(
        private readonly ?string $fallbackFilter = 'FALLBACK_DERIVED_TONEMAP_FILTER',
    ) {
        parent::__construct('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    /**
     * A scanned HDR HEVC 4K source: a fixed, non-null, correctly-shaped probe so
     * ensureHlsJob() builds its ladder + duration without a live ffprobe (the HDR
     * DECISION itself comes from the persisted color columns, not this probe).
     *
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}
     */
    public function probe(string $inputPath): array
    {
        $this->probeCalls++;
        return [
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '600.0'],
        ];
    }

    public function needsToneMapping(string $inputPath): bool
    {
        $this->needsToneMappingCalls++;
        return true; // an HDR re-derive would proceed to getToneMappingProfile()
    }

    public function getToneMappingProfile(string $inputPath, string $outputPath, string $codec): ?string
    {
        $this->getToneMappingProfileCalls++;
        return $this->fallbackFilter;
    }

    /**
     * Exercise the REAL SV-1.1 command builder for EVERY segment — this is where a
     * per-segment tone-map re-derive would call needsToneMapping()/getToneMappingProfile()
     * (both counted above) — WITHOUT spawning ffmpeg, then publish the segment so
     * produceSegment()'s `is_file($final)` poll resolves immediately.
     *
     * @param array<string, mixed> $params
     */
    public function startSegmentEncode(
        string $inputPath,
        string $outFile,
        float $start,
        float $duration,
        array $params,
        ?string $cancelKey = null,
        ?string $cancelGroup = null
    ): int {
        // Build the genuine command (the tone-map re-derive branch lives here); the
        // returned string is discarded — we only need the side effect of any
        // needsToneMapping()/getToneMappingProfile() call it would make.
        $this->buildSegmentCommand($inputPath, $outFile, $start, $duration, $params);
        file_put_contents($outFile, 'encoded');
        return 4242;
    }
}
