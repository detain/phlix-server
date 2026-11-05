<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentCacheFullException;
use Phlix\Media\Transcoding\TranscodeManager;
use Workerman\MySQL\Connection;

/**
 * C5 law-pin: the segment-cache budget governs the PHLIX_SEGMENT_DIR location
 * EXACTLY as it governed the old temp default.
 *
 * Every budget mechanism — the 3 h idle TTL, the 8 GiB LRU ceiling, the
 * ENOSPC free-space floor — is keyed off the manager's ONE `$segmentDir`,
 * whichever path the operator (or the env override) resolved. This suite runs
 * those mechanisms against a deliberately NON-temp absolute directory (the
 * /var/phlix/hls class of persistent path) so a future refactor can never
 * quietly re-anchor any of them to sys_get_temp_dir(). The tmp-dir twins of
 * these cases live in TranscodeManagerTest; the pair together pin
 * location-independence.
 */
final class TranscodeManagerPersistentCacheBudgetTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $base = '/var/tmp';
        if (!is_dir($base) || !is_writable($base)) {
            $this->markTestSkipped('/var/tmp unavailable — persistent-path law venue missing');
        }
        $this->cacheDir = $base . '/phlix_pcb_' . uniqid('', true);
        mkdir($this->cacheDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
    }

    private function removeTree(string $path): void
    {
        if (!str_starts_with($path, '/var/tmp/phlix_pcb_')) {
            return; // paranoia guard: never delete outside the scratch prefix
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param Connection&MockObject  $db
     * @param FfmpegRunner&MockObject $ff
     */
    private function manager(
        Connection $db,
        FfmpegRunner $ff,
        ?int $cacheMaxBytes = null,
        ?int $cacheMaxAge = null,
        ?int $minDiskSpaceBytes = null
    ): TranscodeManager {
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
        return new TranscodeManager(
            $db,
            $ff,
            $this->cacheDir,
            null,
            6,
            null,
            null,
            null,
            null,
            $cacheMaxBytes,
            $cacheMaxAge,
            200,
            $minDiskSpaceBytes
        );
    }

    /**
     * @return Connection&MockObject
     */
    private function jobRowDb(array $jobRow): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql, ?array $params = null) use ($jobRow): array {
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return [$jobRow];
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * @return array<string, mixed>
     */
    private function onDemandJobRow(string $dir, string $inputPath): array
    {
        return [
            'id' => 'seg-job',
            'hls_dir' => $dir,
            'input_path' => $inputPath,
            'status' => 'completed',
            'duration_seconds' => 60,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'audio_codec' => 'aac']),
        ];
    }

    public function testIdleTtlSweepReclaimsUnderThePersistentDirectory(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $old = $this->cacheDir . '/ttl-old';
        $fresh = $this->cacheDir . '/ttl-fresh';
        mkdir($old, 0755, true);
        mkdir($fresh, 0755, true);
        file_put_contents("{$old}/seg-00000.ts", 'x');
        file_put_contents("{$fresh}/seg-00000.ts", 'x');
        touch($old, time() - 10);

        $reaped = $this->manager($db, $ff, null, 1)->sweepSegmentCache();

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($fresh);
    }

    public function testLruBudgetSweepEvictsIdleSessionUnderThePersistentDirectory(): void
    {
        $db = $this->createMock(Connection::class);
        $ff = $this->createMock(FfmpegRunner::class);
        $old = $this->cacheDir . '/lru-old';
        $active = $this->cacheDir . '/lru-active';
        mkdir($old, 0755, true);
        mkdir($active, 0755, true);
        file_put_contents("{$old}/seg-00000.ts", str_repeat('x', 4096));
        file_put_contents("{$active}/seg-00000.ts", str_repeat('y', 4096));
        touch($old, time() - 2400);
        touch($active, time());

        $reaped = $this->manager($db, $ff, 1024, 86400)->sweepSegmentCache();

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($active);
    }

    public function testEnospcFloorThrowsAgainstThePersistentDirectoryFilesystem(): void
    {
        $dir = $this->cacheDir . '/seg-enospc';
        mkdir($dir, 0755, true);
        $input = $dir . '/in.mkv';
        file_put_contents($input, 'x');
        $db = $this->jobRowDb($this->onDemandJobRow($dir, $input));
        $ff = $this->createMock(FfmpegRunner::class);
        $ff->expects($this->never())->method('startSegmentEncode');

        $this->expectException(SegmentCacheFullException::class);
        $this->manager($db, $ff, null, null, PHP_INT_MAX)->ensureSegment('seg-job', null, 2);
    }
}
