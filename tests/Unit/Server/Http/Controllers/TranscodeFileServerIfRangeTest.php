<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Server\Http\Controllers\TranscodeFileServer;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * minors M-SERVER-48 (W-min108) — RFC 7233 §3.2 on the transcode-file range
 * leg: an If-Range validator that no longer matches the file must NOT splice a
 * 206 (nor answer 416) out of changed bytes; the FULL 200 representation
 * ships, because a resumed request whose window was negotiated against older
 * bytes would otherwise corrupt the reassembly.
 *
 * Drives the private serveJobFile() directly (ReflectionMethod — the house
 * fast-path idiom) with real temp files, so the validator math is the live
 * filemtime, never a fixture.
 */
final class TranscodeFileServerIfRangeTest extends TestCase
{
    private const SEG = 'TSDATA1234567890';
    private const STALE = 'Wed, 21 Oct 2015 07:28:00 GMT';

    private string $jobDir = '';

    protected function setUp(): void
    {
        $this->jobDir = sys_get_temp_dir() . '/phlix_s48_' . bin2hex(random_bytes(6));
        mkdir($this->jobDir . '/job-s48', 0o755, true);
        file_put_contents($this->jobDir . '/job-s48/seg-v1080p-00000.ts', self::SEG);
        file_put_contents($this->jobDir . '/job-s48/master.m3u8', '#EXTM3U');
    }

    protected function tearDown(): void
    {
        if ($this->jobDir !== '') {
            @unlink($this->jobDir . '/job-s48/seg-v1080p-00000.ts');
            @unlink($this->jobDir . '/job-s48/master.m3u8');
            @rmdir($this->jobDir . '/job-s48');
            @rmdir($this->jobDir);
        }
    }

    /** @param array<string, string> $headers */
    private function serve(string $file, array $headers): Response
    {
        $request = new Request();
        $request->userId = 'u-s48';
        foreach ($headers as $name => $value) {
            $request->headers[$name] = $value;
        }

        // TranscodeFileServer is a TRAIT (mixed into Hls/Dash/LiveTvStream
        // controllers); serveJobFile itself is host-independent, so an
        // anonymous host exercises the exact shipped code path.
        $host = new class () {
            use TranscodeFileServer;
        };
        $method = new ReflectionMethod($host, 'serveJobFile');

        return $method->invoke($host, $request, $this->jobDir . '/job-s48', $file);
    }

    public function testStaleIfRangeWithSatisfiableRangeShipsTheWholeFile(): void
    {
        $resp = $this->serve('seg-v1080p-00000.ts', [
            'Range' => 'bytes=0-3',
            'If-Range' => self::STALE,
        ]);

        self::assertSame(200, $resp->statusCode);
        self::assertArrayNotHasKey('Content-Range', $resp->headers);
        self::assertSame(self::SEG, $resp->materializeFileWindow()->body);
    }

    public function testStaleIfRangeWithUnsatisfiableRangeShipsWholeFileNot416(): void
    {
        $resp = $this->serve('seg-v1080p-00000.ts', [
            'Range' => 'bytes=999-1000',
            'If-Range' => self::STALE,
        ]);

        self::assertSame(200, $resp->statusCode);
        self::assertSame(self::SEG, $resp->materializeFileWindow()->body);
    }

    public function testMatchingIfRangeStillHonorsTheRange(): void
    {
        $mtime = filemtime($this->jobDir . '/job-s48/seg-v1080p-00000.ts');
        self::assertIsInt($mtime);

        $resp = $this->serve('seg-v1080p-00000.ts', [
            'Range' => 'bytes=0-3',
            'If-Range' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ]);

        self::assertSame(206, $resp->statusCode);
        self::assertSame('TSDA', $resp->materializeFileWindow()->body);
    }

    public function testAbsentIfRangeKeepsTheExisting206Law(): void
    {
        $resp = $this->serve('seg-v1080p-00000.ts', ['Range' => 'bytes=0-3']);

        self::assertSame(206, $resp->statusCode);
    }

    public function testNoCachePlaylistUnderStaleIfRangeShipsWholeFile(): void
    {
        $resp = $this->serve('master.m3u8', [
            'Range' => 'bytes=0-3',
            'If-Range' => self::STALE,
        ]);

        self::assertSame(200, $resp->statusCode);
        self::assertArrayNotHasKey('Content-Range', $resp->headers);
    }
}
