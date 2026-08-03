<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * `ffmpeg.max_concurrent_transcodes` must actually reach the job-concurrency
 * ceiling.
 *
 * Before this, `TranscodeManager::__construct()` assigned a bare literal
 * `$this->maxConcurrentTranscodes = 4;` — not a constructor parameter, not a
 * config read, not a settings read. `config/ffmpeg.php`'s
 * `max_concurrent_transcodes` was threaded in by nobody, while the schema
 * shipped a fully-formed admin setting (min 1 / max 64 / default 4) whose own
 * help text advises that "a 16-core CPU with an NVIDIA GPU can typically handle
 * 6–8". An admin who followed that advice and set 8 still got 4: a setting that
 * was documented, validated, persisted — and inert.
 *
 */
final class TranscodeManagerConcurrencyLimitTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segmentDir = sys_get_temp_dir() . '/phlix_tmcl_' . uniqid('', true);
        mkdir($this->segmentDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->segmentDir)) {
            @rmdir($this->segmentDir);
        }
        parent::tearDown();
    }

    private function manager(?int $maxConcurrentTranscodes): TranscodeManager
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new TranscodeManager(
            $db,
            $this->createMock(FfmpegRunner::class),
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $maxConcurrentTranscodes,
        );
    }

    private function ceilingOf(TranscodeManager $manager): int
    {
        $prop = new ReflectionProperty(TranscodeManager::class, 'maxConcurrentTranscodes');
        $prop->setAccessible(true);

        /** @var int $value */
        $value = $prop->getValue($manager);

        return $value;
    }

    public function testConfiguredCeilingIsHonoured(): void
    {
        self::assertSame(8, $this->ceilingOf($this->manager(8)));
        self::assertSame(1, $this->ceilingOf($this->manager(1)));
        self::assertSame(64, $this->ceilingOf($this->manager(64)));
    }

    public function testNullFallsBackToTheDocumentedDefault(): void
    {
        self::assertSame(
            TranscodeManager::DEFAULT_MAX_CONCURRENT_TRANSCODES,
            $this->ceilingOf($this->manager(null)),
        );
    }

    public function testNonPositiveValuesFallBackRatherThanDeadlockingTheQueue(): void
    {
        // A ceiling of 0 would refuse every transcode; treat it as "unset".
        self::assertSame(
            TranscodeManager::DEFAULT_MAX_CONCURRENT_TRANSCODES,
            $this->ceilingOf($this->manager(0)),
        );
        self::assertSame(
            TranscodeManager::DEFAULT_MAX_CONCURRENT_TRANSCODES,
            $this->ceilingOf($this->manager(-3)),
        );
    }

    /**
     * The shipped config default and the class fallback must agree, or the
     * "default" shown in the admin UI is not the value the server uses.
     */
    public function testConfigDefaultMatchesTheClassFallback(): void
    {
        /** @var array<string, mixed> $ffmpegConfig */
        $ffmpegConfig = include dirname(__DIR__, 4) . '/config/ffmpeg.php';

        self::assertSame(
            TranscodeManager::DEFAULT_MAX_CONCURRENT_TRANSCODES,
            $ffmpegConfig['max_concurrent_transcodes'] ?? null,
            'config/ffmpeg.php max_concurrent_transcodes must match '
            . 'TranscodeManager::DEFAULT_MAX_CONCURRENT_TRANSCODES',
        );
    }
}
