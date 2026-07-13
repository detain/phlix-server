<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * SV-3.1 f security regression tests for the time-shift buffer writer.
 *
 * Two guards are proven load-bearing here:
 *  1. Command-injection: the REAL {@see Recorder::spawnTimeShiftBuffer()} command
 *     builder must `escapeshellarg`-quote EVERY interpolated value (tuner URL,
 *     segment pattern, playlist path, ffmpeg binary). The detached launch is
 *     captured — never executed — via a protected {@see Recorder::launchDetached()}
 *     override, so a hostile URL/path is asserted against, not run.
 *  2. Path-jail: {@see Recorder::removeBufferDir()} must REFUSE to delete anything
 *     outside the `<storage_path>/timeshift/` subtree (traversal / out-of-jail).
 *
 * @covers \Phlix\LiveTv\Recorder::spawnTimeShiftBuffer
 * @covers \Phlix\LiveTv\Recorder::launchDetached
 * @covers \Phlix\LiveTv\Recorder::removeBufferDir
 *
 * @since SV-3.1 f (fix pass)
 */
final class RecorderTimeShiftSecurityTest extends TestCase
{
    /** @return Connection&MockObject */
    private function mockDb(): Connection
    {
        /** @var Connection&MockObject $db */
        $db = $this->createMock(Connection::class);
        return $db;
    }

    /**
     * Hostile tuner URL + buffer-dir pairs that would break out of the ffmpeg
     * command line if any value reached the shell unquoted.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function hostileInputs(): array
    {
        return [
            'semicolon + touch'  => ['http://x/;touch /tmp/pwn', '/var/rec/timeshift/a;rm -rf ~'],
            'command substitution' => ['http://x/$(id)', '/var/rec/timeshift/$(reboot)'],
            'spaces + pipe + amp' => ['http://x/ a | b & c', '/var/rec/timeshift/d e;f'],
            'backticks + quotes' => ['http://x/`id`', "/var/rec/timeshift/g'h\"i"],
        ];
    }

    /**
     * The REAL command builder escapes EVERY interpolated value. Reverting any
     * `escapeshellarg(...)` to a raw interpolation drops the quoted form from the
     * emitted command string, flipping the matching assertion RED.
     *
     * @dataProvider hostileInputs
     */
    public function testSpawnTimeShiftBufferEscapesEveryInterpolatedValue(
        string $hostileUrl,
        string $hostileDir
    ): void {
        $ffmpegPath = '/usr/bin/ffmpeg';
        $recorder = new class (
            $this->mockDb(),
            new DbTimeShiftSessionStore($this->mockDb()),
            '/var/rec',
            0,
            $this->createMock(StructuredLogger::class),
            null,
            $ffmpegPath
        ) extends Recorder {
            public string $capturedCommand = '';

            protected function launchDetached(string $ffmpegCmd, string $logFile): int
            {
                // Capture the fully-built command WITHOUT executing a shell.
                $this->capturedCommand = $ffmpegCmd;
                return 4242;
            }

            /** Expose the protected builder for the test. */
            public function buildAndCapture(string $streamUrl, string $bufferDir): int
            {
                return $this->spawnTimeShiftBuffer($streamUrl, $bufferDir);
            }
        };

        $pid = $recorder->buildAndCapture($hostileUrl, $hostileDir);
        $this->assertSame(4242, $pid);

        $cmd = $recorder->capturedCommand;
        $this->assertNotSame('', $cmd, 'the command builder ran');

        // Every interpolated value appears ONLY in its escapeshellarg-quoted form.
        $this->assertStringContainsString(
            escapeshellarg($ffmpegPath),
            $cmd,
            'ffmpeg binary path must be shell-quoted'
        );
        $this->assertStringContainsString(
            escapeshellarg($hostileUrl),
            $cmd,
            'tuner stream URL must be shell-quoted (revert escapeshellarg($streamUrl) -> RED)'
        );
        $this->assertStringContainsString(
            escapeshellarg($hostileDir . '/seg_%05d.ts'),
            $cmd,
            'segment pattern (derived from buffer dir) must be shell-quoted'
        );
        $this->assertStringContainsString(
            escapeshellarg($hostileDir . '/' . Recorder::TIMESHIFT_PLAYLIST_NAME),
            $cmd,
            'playlist path (derived from buffer dir) must be shell-quoted'
        );

        // Belt-and-braces: the raw, UNQUOTED "-i <hostileUrl>" must never appear —
        // that is exactly the shape a reverted escapeshellarg would emit.
        $this->assertStringNotContainsString(
            ' -i ' . $hostileUrl . ' ',
            $cmd,
            'the raw unquoted tuner URL must not reach the command line'
        );
    }

    public function testRemoveBufferDirRefusesToDeleteOutsideTheJail(): void
    {
        $storage = sys_get_temp_dir() . '/phlix-jail-' . uniqid('', true);
        $root = $storage . '/timeshift';
        $victim = $storage . '/victim';
        $secret = $victim . '/secret.txt';

        @mkdir($root, 0755, true);
        @mkdir($victim, 0755, true);
        file_put_contents($secret, 'do-not-delete');
        $this->assertFileExists($secret);

        $recorder = new Recorder(
            $this->mockDb(),
            new DbTimeShiftSessionStore($this->mockDb()),
            $storage,
            0,
            $this->createMock(StructuredLogger::class)
        );

        $remove = new ReflectionMethod(Recorder::class, 'removeBufferDir');
        $remove->setAccessible(true);

        // Case A — a plain out-of-jail absolute path (caught by the string-prefix
        // guard: it does not start with "<storage>/timeshift/").
        $remove->invoke($recorder, $victim);
        $this->assertFileExists($secret, 'out-of-jail path must delete nothing');

        // Case B — a traversal path that string-prefixes the jail root but resolves
        // OUTSIDE it (caught by the realpath guard). This is the case that flips RED
        // if the jail is reverted to a no-op — removeBufferDir would then scandir the
        // resolved <storage>/victim and unlink secret.txt.
        $remove->invoke($recorder, $root . '/../victim');
        $this->assertFileExists($secret, 'traversal out of the jail must delete nothing');

        // A legitimate in-jail buffer dir IS removed (proves the guard is not a
        // blanket refuse-everything no-op).
        $legit = $root . '/sess-legit';
        @mkdir($legit, 0755, true);
        file_put_contents($legit . '/seg_00001.ts', 'x');
        $remove->invoke($recorder, $legit);
        $this->assertDirectoryDoesNotExist($legit, 'a real in-jail buffer dir is removed');

        // Cleanup.
        @unlink($secret);
        @rmdir($victim);
        @rmdir($root);
        @rmdir($storage);
    }
}
