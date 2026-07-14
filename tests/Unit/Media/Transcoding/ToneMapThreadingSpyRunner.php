<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * SV-1.1(b) test double: a spy {@see FfmpegRunner} that counts every
 * probe()/needsToneMapping()/getToneMappingProfile() call so a test can assert
 * the threaded tone-map path makes NONE of them, and stubs those methods so no
 * real ffprobe/ffmpeg process is spawned.
 *
 * A named (PSR-4) class rather than an anonymous one so its counter properties
 * AND the inherited FfmpegRunner command builders are both visible to static
 * analysis.
 */
final class ToneMapThreadingSpyRunner extends FfmpegRunner
{
    public int $probeCalls = 0;
    public int $needsToneMappingCalls = 0;
    public int $getToneMappingProfileCalls = 0;

    public function __construct(
        private readonly ?string $fallbackFilter,
        private readonly bool $needsToneMappingReturn = false,
    ) {
        parent::__construct('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
    }

    public function probe(string $inputPath): ?array
    {
        $this->probeCalls++;
        return null;
    }

    public function needsToneMapping(string $inputPath): bool
    {
        $this->needsToneMappingCalls++;
        return $this->needsToneMappingReturn;
    }

    public function getToneMappingProfile(string $inputPath, string $outputPath, string $codec): ?string
    {
        $this->getToneMappingProfileCalls++;
        return $this->fallbackFilter;
    }

    public function resetCounters(): void
    {
        $this->probeCalls = 0;
        $this->needsToneMappingCalls = 0;
        $this->getToneMappingProfileCalls = 0;
    }
}
