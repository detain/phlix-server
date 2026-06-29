<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Arr;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Arr\WorkermanArrTransport;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use RuntimeException;

/**
 * Unit tests for the non-blocking {@see WorkermanArrTransport} and its injection
 * into the shared *arr clients.
 *
 * The transport's happy path runs a real {@see \Swoole\Coroutine\Http\Client}
 * inside a coroutine, which requires a live socket; rather than hit the network we
 * assert (a) the request-validation guards, (b) that the class is a drop-in
 * {@see ArrTransportInterface}, and (c) — via a deterministic recording fake — that
 * injecting a transport makes the shared RadarrClient route ALL HTTP I/O through it
 * (i.e. it never falls back to the blocking CurlArrTransport).
 *
 * @package Phlix\Tests\Unit\Server\Arr
 * @since 0.34.0
 */
class WorkermanArrTransportTest extends TestCase
{
    public function testImplementsArrTransportInterface(): void
    {
        $transport = new WorkermanArrTransport();
        $this->assertInstanceOf(ArrTransportInterface::class, $transport);
    }

    public function testRejectsEmptyUrl(): void
    {
        $transport = new WorkermanArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty request URL');
        $transport->request('GET', '', ['X-Api-Key: k'], null);
    }

    public function testRejectsEmptyMethod(): void
    {
        $transport = new WorkermanArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty request method');
        $transport->request('', 'http://localhost:7878/api', ['X-Api-Key: k'], null);
    }

    public function testRejectsMalformedUrl(): void
    {
        $transport = new WorkermanArrTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed request URL');
        // A scheme-relative-looking string with no host parses without a host.
        $transport->request('GET', 'http://', ['X-Api-Key: k'], null);
    }

    public function testInjectedTransportShapesGetResponseIntoClient(): void
    {
        $fake = new RecordingArrTransport(['status' => 200, 'body' => '[{"id":7,"name":"BR-Dish"}]']);
        $client = new RadarrClient('http://localhost:7878', 'test-key', null, 30, $fake);

        $formats = $client->getCustomFormats();

        $this->assertSame([['id' => 7, 'name' => 'BR-Dish']], $formats);
        $this->assertCount(1, $fake->calls);
        $call = $fake->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertSame('http://localhost:7878/api/v3/customformat', $call['url']);
        $this->assertContains('X-Api-Key: test-key', $call['headers']);
        $this->assertNull($call['body']);
    }

    public function testInjectedTransportCarriesPostBody(): void
    {
        $fake = new RecordingArrTransport(['status' => 201, 'body' => '{"id":42}']);
        $client = new RadarrClient('http://localhost:7878', 'test-key', null, 30, $fake);

        $id = $client->createCustomFormat(['name' => 'New CF']);

        $this->assertSame(42, $id);
        $this->assertCount(1, $fake->calls);
        $call = $fake->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('http://localhost:7878/api/v3/customformat', $call['url']);
        $this->assertIsString($call['body']);
        $decoded = json_decode((string) $call['body'], true);
        $this->assertSame(['name' => 'New CF'], $decoded);
    }

    public function testInjectedTransportNeverTouchesBlockingCurl(): void
    {
        // The fake points the client at an unroutable address. If the client were
        // falling back to the bundled blocking CurlArrTransport this would attempt
        // a real cURL connection and throw; with the transport injected it never
        // does, proving the I/O is routed through the injected seam.
        $fake = new RecordingArrTransport(['status' => 200, 'body' => '[]']);
        $client = new RadarrClient('http://0.0.0.0:1', 'test-key', null, 30, $fake);

        $this->assertSame([], $client->getCustomFormats());
        $this->assertSame('http://0.0.0.0:1/api/v3/customformat', $fake->calls[0]['url']);
    }
}

/**
 * Deterministic in-memory {@see ArrTransportInterface} that records every call and
 * returns a canned response — no network, no cURL.
 *
 * @package Phlix\Tests\Unit\Server\Arr
 */
final class RecordingArrTransport implements ArrTransportInterface
{
    /** @var list<array{method:string, url:string, headers:array<string>, body:?string}> */
    public array $calls = [];

    /**
     * @param array{status:int, body:string} $response Canned response returned for every call.
     */
    public function __construct(private readonly array $response)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<string> $headers
     * @return array{status:int, body:string}
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return $this->response;
    }
}
