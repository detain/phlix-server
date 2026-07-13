<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Recorder;
use Phlix\Server\Http\Controllers\LiveTvStreamController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see LiveTvStreamController} (SV-3.1 f-c / h).
 *
 * Covers the three handlers wired in Application::loadStreamingRoutes():
 *   GET /livetv/recording/{id}/stream          -> streamRecording
 *   GET /livetv/timeshift/{sessionId}/stream    -> streamTimeShift
 *   GET /livetv/timeshift/{sessionId}/{segment} -> streamTimeShiftSegment
 *
 * The Recorder is mocked (createMock) so no real DB/ffmpeg runs; on-disk buffer
 * dirs / recording files are created as temp fixtures for the byte-serving paths.
 */
class LiveTvStreamControllerTest extends TestCase
{
    /** @var list<string> Temp paths (files + dirs) to clean up after each test. */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // streamRecording
    // ---------------------------------------------------------------------

    public function testStreamRecordingReturns200ForFullGet(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/rec-1.ts';
        file_put_contents($file, '0123456789');
        $this->tempPaths[] = $file;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->with('rec-1')
            ->willReturn(['status' => Recorder::STATUS_COMPLETED]);

        $controller = new LiveTvStreamController($recorder, $dir);
        $response = $controller->streamRecording(new Request(), ['id' => 'rec-1']);
        $response->materializeFileWindow();

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('bytes', $response->headers['Accept-Ranges']);
        $this->assertSame('0123456789', $response->body);
    }

    public function testStreamRecordingHonoursRangeWith206(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/rec-1.ts';
        file_put_contents($file, '0123456789');
        $this->tempPaths[] = $file;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->willReturn(['status' => Recorder::STATUS_RECORDING]);

        $controller = new LiveTvStreamController($recorder, $dir);
        $response = $controller->streamRecording($this->rangeRequest('bytes=2-5'), ['id' => 'rec-1']);
        $response->materializeFileWindow();

        $this->assertSame(206, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('bytes 2-5/10', $response->headers['Content-Range']);
        $this->assertSame('2345', $response->body);
    }

    public function testStreamRecordingUnsatisfiableRangeReturns416(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir . '/rec-1.ts';
        file_put_contents($file, '0123456789');
        $this->tempPaths[] = $file;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->willReturn(['status' => Recorder::STATUS_COMPLETED]);

        $controller = new LiveTvStreamController($recorder, $dir);
        $response = $controller->streamRecording($this->rangeRequest('bytes=100-200'), ['id' => 'rec-1']);

        $this->assertSame(416, $response->statusCode);
        $this->assertSame('bytes */10', $response->headers['Content-Range']);
    }

    public function testStreamRecordingReturns400WhenIdEmpty(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->expects($this->never())->method('getRecording');

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamRecording(new Request(), ['id' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testStreamRecordingReturns404WhenRecordingMissing(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->willReturn(null);

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamRecording(new Request(), ['id' => 'nope']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Recording not found', $this->body($response));
    }

    public function testStreamRecordingReturns404ForNonStreamableStatus(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->willReturn(['status' => 'scheduled']);

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamRecording(new Request(), ['id' => 'rec-1']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Recording not available', $this->body($response));
    }

    public function testStreamRecordingReturns404WhenFileNotOnDisk(): void
    {
        $dir = $this->makeTempDir();
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getRecording')->willReturn(['status' => Recorder::STATUS_COMPLETED]);

        $controller = new LiveTvStreamController($recorder, $dir);
        $response = $controller->streamRecording(new Request(), ['id' => 'ghost']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Recording file not found', $this->body($response));
    }

    // ---------------------------------------------------------------------
    // streamTimeShift (playlist)
    // ---------------------------------------------------------------------

    public function testStreamTimeShiftServesPlaylist(): void
    {
        $dir = $this->makeTempDir();
        $playlist = $dir . '/' . Recorder::TIMESHIFT_PLAYLIST_NAME;
        file_put_contents($playlist, "#EXTM3U\n#EXT-X-VERSION:3\n");
        $this->tempPaths[] = $playlist;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->with('sess-1')
            ->willReturn($this->timeShiftRow($dir));

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShift(new Request(), ['sessionId' => 'sess-1']);
        $response->materializeFileWindow();

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers['Content-Type']);
        $this->assertSame("#EXTM3U\n#EXT-X-VERSION:3\n", $response->body);
    }

    public function testStreamTimeShiftReturns400WhenSessionEmpty(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->expects($this->never())->method('getTimeShift');

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShift(new Request(), ['sessionId' => '']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testStreamTimeShiftReturns404WhenSessionNotFound(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn(null);

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShift(new Request(), ['sessionId' => 'gone']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Timeshift session not found', $this->body($response));
    }

    public function testStreamTimeShiftReturns503WhenBufferNotReady(): void
    {
        // Session exists (no-tuner: NULL pid + empty dir) but ffmpeg has not
        // written buffer.m3u8 yet — must be 503, never a 500 on the missing file.
        $dir = $this->makeTempDir();
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn($this->timeShiftRow($dir, null));

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShift(new Request(), ['sessionId' => 'sess-1']);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('2', $response->headers['Retry-After']);
        $this->assertSame('Timeshift buffer not ready', $this->body($response));
    }

    public function testStreamTimeShiftReturns404WhenBufferDirBlank(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn(['buffer_dir' => '']);

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShift(new Request(), ['sessionId' => 'sess-1']);

        $this->assertSame(404, $response->statusCode);
    }

    // ---------------------------------------------------------------------
    // streamTimeShiftSegment
    // ---------------------------------------------------------------------

    public function testStreamTimeShiftSegmentServesSegmentFullGet(): void
    {
        $dir = $this->makeTempDir();
        $seg = $dir . '/seg_00001.ts';
        file_put_contents($seg, 'SEGMENTBYTES');
        $this->tempPaths[] = $seg;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn($this->timeShiftRow($dir));

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShiftSegment(
            new Request(),
            ['sessionId' => 'sess-1', 'segment' => 'seg_00001.ts']
        );
        $response->materializeFileWindow();

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('SEGMENTBYTES', $response->body);
    }

    public function testStreamTimeShiftSegmentHonoursRangeWith206(): void
    {
        $dir = $this->makeTempDir();
        $seg = $dir . '/seg_00042.ts';
        file_put_contents($seg, '0123456789');
        $this->tempPaths[] = $seg;

        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn($this->timeShiftRow($dir));

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShiftSegment(
            $this->rangeRequest('bytes=0-3'),
            ['sessionId' => 'sess-1', 'segment' => 'seg_00042.ts']
        );
        $response->materializeFileWindow();

        $this->assertSame(206, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('bytes 0-3/10', $response->headers['Content-Range']);
        $this->assertSame('0123', $response->body);
    }

    public function testStreamTimeShiftSegmentReturns404WhenAgedOut(): void
    {
        // A validly-named segment that ffmpeg's delete_segments has already
        // pruned out of the rolling window resolves to a missing file -> 404.
        $dir = $this->makeTempDir();
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn($this->timeShiftRow($dir));

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShiftSegment(
            new Request(),
            ['sessionId' => 'sess-1', 'segment' => 'seg_99999.ts']
        );

        $this->assertSame(404, $response->statusCode);
    }

    /**
     * The path-jail rejects any name that is not an exact seg_<digits>.ts BEFORE
     * the session is even resolved (getTimeShift must never be called) and BEFORE
     * any name touches the filesystem.
     *
     * @dataProvider unsafeSegmentNameProvider
     */
    public function testStreamTimeShiftSegmentRejectsUnsafeNames(string $segment): void
    {
        $recorder = $this->createMock(Recorder::class);
        // Jail runs first: the session is never resolved for a bad name.
        $recorder->expects($this->never())->method('getTimeShift');

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShiftSegment(
            new Request(),
            ['sessionId' => 'sess-1', 'segment' => $segment]
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Segment not found', $this->body($response));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSegmentNameProvider(): array
    {
        return [
            'parent traversal'        => ['../../etc/passwd'],
            'dot-dot only'            => ['..'],
            'absolute path'           => ['/etc/passwd'],
            'nested traversal'        => ['seg_00001.ts/../../secret'],
            'playlist not a segment'  => ['buffer.m3u8'],
            'wrong extension'         => ['seg_00001.tsx'],
            'no digits'               => ['seg_.ts'],
            'prefix mismatch'         => ['xseg_00001.ts'],
            'suffix junk'             => ['seg_00001.ts.bak'],
            'empty'                   => [''],
            'trailing newline'        => ["seg_00001.ts\n"],
        ];
    }

    public function testStreamTimeShiftSegmentReturns404WhenSessionNotFound(): void
    {
        $recorder = $this->createMock(Recorder::class);
        $recorder->method('getTimeShift')->willReturn(null);

        $controller = new LiveTvStreamController($recorder, '/var/recordings');
        $response = $controller->streamTimeShiftSegment(
            new Request(),
            ['sessionId' => 'gone', 'segment' => 'seg_00001.ts']
        );

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Timeshift session not found', $this->body($response));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * The in-memory/store array shape Recorder::getTimeShift() returns.
     *
     * @return array<string, mixed>
     */
    private function timeShiftRow(string $bufferDir, ?int $pid = 4321): array
    {
        return [
            'id' => 'ts-1',
            'session_id' => 'sess-1',
            'channel_id' => 'chan-1',
            'started_at' => 1000,
            'buffer_start' => 1000,
            'buffer_end' => 1000,
            'buffer_dir' => $bufferDir,
            'pid' => $pid,
            'current_position' => 0,
        ];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_ts_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempPaths[] = $dir;
        return $dir;
    }

    private function rangeRequest(string $range): Request
    {
        $request = new Request();
        $request->headers['Range'] = $range;
        return $request;
    }

    private function body(\Phlix\Server\Http\Response $response): string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body, true) ?: [];
        return is_string($decoded['error'] ?? null) ? $decoded['error'] : '';
    }
}
