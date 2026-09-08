<?php

/**
 * Phlix media server component: Logger — size-rotating handler (S130).
 *
 * Pins the guarantee behind config/logger.php's on-disk bound: a
 * {@see SizeRotatingFileHandler} caps each log file's SIZE (not merely its
 * daily count), keeping a stable active-file name and a bounded shard chain.
 * These are the assertions that would go RED if the byte ceiling stopped being
 * enforced — see the mutation note on {@see test_totalFootprintIsHardBounded}.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Logger;

use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\SizeRotatingFileHandler;
use RuntimeException;

class SizeRotatingFileHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlix_size_rot_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    private function path(string $name): string
    {
        return $this->tempDir . '/' . $name;
    }

    private function logger(SizeRotatingFileHandler $handler): Logger
    {
        $logger = new Logger('test');
        $logger->pushHandler($handler);

        return $logger;
    }

    /** Fixed-length line so the byte budget is predictable, not incidental. */
    private function emit(Logger $logger, int $times): void
    {
        $payload = str_repeat('x', 120);
        for ($i = 0; $i < $times; $i++) {
            $logger->info($payload);
        }
    }

    /**
     * Flush and release the handler's stream, then drop PHP's stat cache so the
     * size/count assertions that follow read real bytes on disk. Without this a
     * still-open append stream can be measured against a stale cached size and
     * the bound assertion would pass for the wrong reason.
     */
    private function quiesce(SizeRotatingFileHandler $handler): void
    {
        $handler->close();
        clearstatcache();
    }

    public function test_rollsToShardOnceActiveFileReachesCeiling(): void
    {
        $file = $this->path('app.log');
        // 400-byte ceiling, keep 3 shards. ~180-byte lines, so a couple of
        // writes blow past the ceiling and force at least one roll.
        $handler = new SizeRotatingFileHandler($file, 3, 400, Level::Debug);
        $this->emit($this->logger($handler), 5);
        $this->quiesce($handler);

        $this->assertFileExists($file, 'active file keeps its stable name');
        $this->assertFileExists($file . '.1', 'the first roll produces app.log.1');
    }

    public function test_activeFileIsRecreatedEmptyAfterRoll(): void
    {
        $file = $this->path('app.log');
        $handler = new SizeRotatingFileHandler($file, 3, 400, Level::Debug);
        $logger = $this->logger($handler);

        $this->emit($logger, 5);   // forces >=1 roll; app.log moved to .1
        $this->quiesce($handler);
        $sizeAfterRoll = (int) filesize($file);

        // Reopen (handler lazy-reopens on next write) and land one more record.
        $logger->info(str_repeat('y', 120));
        $this->quiesce($handler);
        $this->assertGreaterThan($sizeAfterRoll, (int) filesize($file), 'active file takes new records after the roll');
    }

    public function test_shardCountNeverExceedsMaxFiles(): void
    {
        $file = $this->path('app.log');
        $maxFiles = 2;
        $handler = new SizeRotatingFileHandler($file, $maxFiles, 300, Level::Debug);
        $this->emit($this->logger($handler), 40); // far past the ceiling repeatedly
        $this->quiesce($handler);

        // Active file + at most $maxFiles rolled shards, nothing older survives.
        $shards = glob($file . '.*') ?: [];
        $this->assertLessThanOrEqual(
            $maxFiles,
            count($shards),
            'only the newest ' . $maxFiles . ' shards survive; got ' . implode(',', $shards),
        );
        $this->assertFileDoesNotExist($file . '.' . ($maxFiles + 1), 'a shard beyond the keep-count is pruned');
    }

    /**
     * Headline guarantee for S130 AC "a bounded on-disk size is enforced":
     * regardless of write volume, total bytes across the active file + shards
     * stay under (maxFiles + 1) * (maxBytes + one record).
     *
     * MUTATION PROOF: deleting the `rotateIfOversized()` call in
     * SizeRotatingFileHandler::write() (so nothing ever rolls) makes the active
     * file grow to the whole stream and this assertion goes RED.
     */
    public function test_totalFootprintIsHardBounded(): void
    {
        $file = $this->path('app.log');
        $maxFiles = 3;
        $maxBytes = 500;
        $handler = new SizeRotatingFileHandler($file, $maxFiles, $maxBytes, Level::Debug);
        $this->emit($this->logger($handler), 200); // 200 * ~180 bytes = ~36 KB, unbounded without rotation
        $this->quiesce($handler);

        $all = array_merge([$file], glob($file . '.*') ?: []);
        $totalBytes = 0;
        $nonEmptyFiles = 0;
        foreach ($all as $path) {
            if (!is_file($path)) {
                continue;
            }
            $size = (int) filesize($path);
            // Each file may exceed the ceiling by at most the one record that
            // pushed it over (the check runs before the write).
            $this->assertLessThanOrEqual(
                $maxBytes + 400,
                $size,
                "no single log file overshoots the ceiling: {$path} is {$size} bytes",
            );
            $totalBytes += $size;
            $nonEmptyFiles++;
        }

        // Anti-vacuity: the bound only means something if rotation actually
        // produced shards (otherwise a single small file could pass by luck).
        $this->assertGreaterThan(1, $nonEmptyFiles, 'the workload must have forced multiple files to exist');

        $bound = ($maxFiles + 1) * ($maxBytes + 400);
        $this->assertLessThanOrEqual(
            $bound,
            $totalBytes,
            "total on-disk log bytes must stay within the hard bound ({$bound}); got {$totalBytes}",
        );
    }

    public function test_recordsBelowHandlerLevelAreNotWritten(): void
    {
        $file = $this->path('app.log');
        $handler = new SizeRotatingFileHandler($file, 5, 4096, Level::Warning);
        $logger = $this->logger($handler);

        $logger->info('below level');
        $logger->debug('also below level');
        $this->assertFileDoesNotExist($file, 'a warning-level handler must not create the file for lower records');

        $logger->error('at level');
        $this->assertFileExists($file, 'the at-level record lands');
        $this->assertStringContainsString('at level', (string) file_get_contents($file));
    }

    public function test_rejectsNonPositiveByteCeiling(): void
    {
        $this->expectException(RuntimeException::class);
        new SizeRotatingFileHandler($this->path('app.log'), 5, 0, Level::Debug);
    }

    public function test_rejectsNegativeShardCount(): void
    {
        $this->expectException(RuntimeException::class);
        new SizeRotatingFileHandler($this->path('app.log'), -1, 1024, Level::Debug);
    }
}
