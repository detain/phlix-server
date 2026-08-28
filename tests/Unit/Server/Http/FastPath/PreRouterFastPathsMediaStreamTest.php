<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\BodylessResponse;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * S301 — the direct-play byte stream, moved verbatim from
 * `HttpHandler::serveMediaStream()` into {@see PreRouterFastPaths} so the relay
 * dispatcher serves it too.
 *
 * Covers the X8 audio-MIME mapping, Range/206, the 416 control, the signed-URL
 * auth path and the single-Content-Length HEAD wire shape (the S52-class
 * defect twin: `Content-Length: strlen('')` must never be appended after the
 * real one).
 */
final class PreRouterFastPathsMediaStreamTest extends TestCase
{
    private const BODY = 'ABCDEFGHIJKLMNOP';

    private string $mediaPath = '';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->mediaPath = sys_get_temp_dir() . '/phlix-stream-' . bin2hex(random_bytes(6)) . '.flac';
        // 16 deterministic bytes so Range slicing is verifiable.
        file_put_contents($this->mediaPath, self::BODY);
    }

    protected function tearDown(): void
    {
        if ($this->mediaPath !== '' && is_file($this->mediaPath)) {
            @unlink($this->mediaPath);
        }
        SignedUrl::resetSharedForTesting();
    }

    /** @param array<string, mixed>|null $item */
    private function makeFastPaths(?array $item, ?array $profile = null): PreRouterFastPaths
    {
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->method('effectiveContentRatingsForIds')->willReturn([]);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveProfile')->willReturn($profile);
        $profiles->method('getActiveRatingFilter')->willReturn(null);

        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 'u1', 'is_admin' => 0]);

        $gate = new RatingGate($repo, $profiles, $users);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $class): object => match ($class) {
                RatingGate::class         => $gate,
                UserProfileManager::class => $profiles,
                default                   => $repo,
            },
        );

        return new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->createMock(AvatarStorage::class),
            $container,
        );
    }

    private function signedRequest(string $id, ?string $rangeHeader = null): Request
    {
        // userId=null path → signed-URL auth (skips stream-limit).
        $signed = SignedUrl::fromEnv()->mint('/media/' . $id . '/stream');

        $request = new Request();
        $request->method = 'GET';
        $request->path = strtok($signed, '?') ?: '/media/' . $id . '/stream';
        $queryString = (string) (strpos($signed, '?') !== false ? substr($signed, strpos($signed, '?') + 1) : '');
        $request->queryString = $queryString;
        $request->query = self::parsedQuery($queryString);
        if ($rangeHeader !== null) {
            $request->headers['Range'] = $rangeHeader;
        }

        return $request;
    }

    /**
     * Parse a query string into the string-keyed shape {@see Request::$query}
     * declares (parse_str yields int|string keys — parse at the boundary).
     *
     * Public because the sibling {@see PreRouterFastPathsStreamLimitTest} uses
     * the identical boundary for its signed-URL case.
     *
     * @return array<string, mixed>
     */
    public static function parsedQuery(string $queryString): array
    {
        parse_str($queryString, $parsed);
        $out = [];
        foreach ($parsed as $key => $value) {
            $out[(string) $key] = $value;
        }
        return $out;
    }

    private function userRequest(string $id, string $userId, string $method = 'GET'): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/media/' . $id . '/stream';
        $request->userId = $userId;

        return $request;
    }

    public function testServesTrackWithAudioContentType(): void
    {
        $fastPaths = $this->makeFastPaths([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->mediaPath,
        ], ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest('track-1'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);
        self::assertSame('audio/flac', $response->headers['Content-Type'] ?? null);
        // The bytes really are the file's — the materialized window is what
        // RelayConsumer::streamFileChunks() would carry over the tunnel.
        self::assertSame(self::BODY, $response->materializeFileWindow()->body);
    }

    public function testServesTrackRangeAsPartialContent(): void
    {
        $fastPaths = $this->makeFastPaths([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->mediaPath,
        ], ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest('track-1', 'bytes=0-3'));

        self::assertNotNull($response);
        self::assertSame(206, $response->statusCode);
        self::assertSame('audio/flac', $response->headers['Content-Type'] ?? null);
        self::assertSame('ABCD', $response->materializeFileWindow()->body);
    }

    public function testUnsatisfiableRangeYields416(): void
    {
        $fastPaths = $this->makeFastPaths([
            'id' => 'track-1',
            'type' => 'track',
            'path' => $this->mediaPath,
        ], ['id' => 'p1']);

        // File is 16 bytes; a start well past the end is unsatisfiable.
        $response = $fastPaths->dispatch($this->signedRequest('track-1', 'bytes=999-1000'));

        self::assertNotNull($response);
        self::assertSame(416, $response->statusCode);
        self::assertSame('bytes */16', $response->headers['Content-Range'] ?? null);
    }

    public function testAnonymousRequestWithoutSignatureIsUnauthorized(): void
    {
        $fastPaths = $this->makeFastPaths(null, ['id' => 'p1']);

        $request = new Request();
        $request->method = 'GET';
        $request->path = '/media/track-1/stream';

        $response = $fastPaths->dispatch($request);

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode);
        self::assertSame('Unauthorized', $response->body);
    }

    public function testMissingItemIsNotFound(): void
    {
        $fastPaths = $this->makeFastPaths(null, ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->signedRequest('no-such-item'));

        self::assertNotNull($response);
        self::assertSame(404, $response->statusCode);
        self::assertSame('Media not found', $response->body);
    }

    /**
     * THE S52-TWIN DEFECT: exactly one `Content-Length`, the real file size,
     * and no body on the wire.
     *
     * `asHeadReply()` pins the length and `toWorkermanResponse()` renders
     * through {@see BodylessResponse}; reverting to a plain buffered response
     * puts `Content-Length: 0` LAST and this fails.
     */
    public function testHeadPutsExactlyOneRealContentLengthOnTheWire(): void
    {
        $fastPaths = $this->makeFastPaths([
            'id' => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ], ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->userRequest('m1', 'u1', 'HEAD'));

        self::assertNotNull($response);
        self::assertSame(200, $response->statusCode);

        $wire = (string) $response->toWorkermanResponse();

        self::assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        self::assertStringContainsString('Content-Length: ' . strlen(self::BODY) . "\r\n", $wire);
        self::assertStringNotContainsString('Content-Length: 0', $wire);
        self::assertStringNotContainsString(self::BODY, $wire, 'A HEAD reply must not carry the bytes.');
    }

    /** LOCK-IN on the mechanism: the Workerman encoder must be BodylessResponse. */
    public function testTheHeadArmRendersThroughTheBodylessEncoder(): void
    {
        $fastPaths = $this->makeFastPaths([
            'id' => 'm1',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ], ['id' => 'p1']);

        $response = $fastPaths->dispatch($this->userRequest('m1', 'u1', 'HEAD'));

        self::assertNotNull($response);
        self::assertInstanceOf(BodylessResponse::class, $response->toWorkermanResponse());
    }

    /**
     * Directly pins the extension→MIME mapping for the audio formats added in
     * X8 (plus a couple of retained video mappings), moved with the method.
     *
     * @dataProvider mimeCases
     */
    public function testStreamMimeForMapsAudioExtensions(string $file, string $expected): void
    {
        $m = new ReflectionMethod(PreRouterFastPaths::class, 'streamMimeFor');
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