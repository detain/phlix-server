<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Library\ItemRepository;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 *
 * X8: pins that GET /media/{id}/stream serves a music track (type='track')
 * media_item Range-safe with the correct audio/* Content-Type. Without the
 * audio MIME mapping the track streamed as application/octet-stream and would
 * not play in the UI's <audio> element.
 */
final class HttpHandlerServeMediaStreamTest extends TestCase
{
    private string $trackPath = '';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->trackPath = sys_get_temp_dir() . '/phlix-track-' . bin2hex(random_bytes(6)) . '.flac';
        // 16 deterministic bytes so Range slicing is verifiable.
        file_put_contents($this->trackPath, 'ABCDEFGHIJKLMNOP');
    }

    protected function tearDown(): void
    {
        if ($this->trackPath !== '' && is_file($this->trackPath)) {
            @unlink($this->trackPath);
        }
        SignedUrl::resetSharedForTesting();
    }

    /** @param array<string, mixed>|null $item */
    private function makeHandler(?array $item): HttpHandler
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);

        // The signed-URL path (userId=null) only resolves ItemRepository — it
        // skips the stream-limit check that would otherwise fetch other services.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($repo);

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            sys_get_temp_dir(),
            $this->createMock(Application::class),
            null,
        );
    }

    private function invoke(HttpHandler $handler, WorkermanRequest $wr): ?WorkermanResponse
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'serveMediaStream');
        $m->setAccessible(true);
        /** @var WorkermanResponse|null $result */
        $result = $m->invoke($handler, $wr, null);

        return $result;
    }

    private function signedRequest(string $id, ?string $rangeHeader = null): WorkermanRequest
    {
        // userId=null path → signed-URL auth (skips stream-limit).
        $signed = SignedUrl::fromEnv()->mint('/media/' . $id . '/stream');
        $range = $rangeHeader !== null ? "Range: {$rangeHeader}\r\n" : '';

        return new WorkermanRequest(
            "GET {$signed} HTTP/1.1\r\nHost: localhost\r\n{$range}\r\n"
        );
    }

    public function testServesTrackWithAudioContentType(): void
    {
        $handler = $this->makeHandler([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->trackPath,
        ]);

        $resp = $this->invoke($handler, $this->signedRequest('track-1'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('audio/flac', $resp->getHeader('Content-Type'));
    }

    public function testServesTrackRangeAsPartialContent(): void
    {
        $handler = $this->makeHandler([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->trackPath,
        ]);

        $resp = $this->invoke($handler, $this->signedRequest('track-1', 'bytes=0-3'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(206, $resp->getStatusCode());
        self::assertSame('audio/flac', $resp->getHeader('Content-Type'));
    }

    public function testUnsatisfiableRangeYields416(): void
    {
        $handler = $this->makeHandler([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->trackPath,
        ]);

        // File is 16 bytes; a start well past the end is unsatisfiable.
        $resp = $this->invoke($handler, $this->signedRequest('track-1', 'bytes=999-1000'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(416, $resp->getStatusCode());
    }

    /**
     * Directly pins the extension→MIME mapping for the audio formats added in
     * X8 (plus a couple of retained video mappings).
     *
     * @dataProvider mimeCases
     */
    public function testStreamMimeForMapsAudioExtensions(string $file, string $expected): void
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'streamMimeFor');
        $m->setAccessible(true);
        self::assertSame($expected, $m->invoke(null, $file));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mimeCases(): array
    {
        return [
            'mp3'  => ['/m/song.mp3', 'audio/mpeg'],
            'm4a'  => ['/m/song.m4a', 'audio/mp4'],
            'aac'  => ['/m/song.aac', 'audio/aac'],
            'flac' => ['/m/song.flac', 'audio/flac'],
            'ogg'  => ['/m/song.ogg', 'audio/ogg'],
            'opus' => ['/m/song.opus', 'audio/opus'],
            'wav'  => ['/m/song.wav', 'audio/wav'],
            'mp4-retained'  => ['/m/clip.mp4', 'video/mp4'],
            'webm-retained' => ['/m/clip.webm', 'video/webm'],
            'unknown'       => ['/m/song.xyz', 'application/octet-stream'],
        ];
    }
}
