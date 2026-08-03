<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use Phlix\Stats\Metrics\MetricsCollector;
use Phlix\Stats\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 *
 * Pins the metrics-recording behaviour of {@see HttpHandler} — the per-request
 * `finally` hook that feeds {@see \Phlix\Stats\Metrics}. Two audit findings are
 * guarded here:
 *
 *  - #4: the recorded route must be a low-cardinality TEMPLATE (variable
 *    segments collapsed to `{id}`), not the raw path — otherwise every uuid
 *    mints its own route row and the cardinality cap folds everything into
 *    `__other__`.
 *  - #5 (and the finally/$request LOW): the recorder must persist the REAL
 *    captured status and read method/path from the always-defined Workerman
 *    request, so an early parse failure cannot corrupt the record.
 */
final class HttpHandlerMetricsRecordingTest extends TestCase
{
    private function makeHandler(?MetricsCollector $metrics): HttpHandler
    {
        $container = $this->createMock(ContainerInterface::class);
        $authenticator = new RequestAuthenticator($this->createMock(AuthManager::class));
        $application = $this->createMock(Application::class);

        return new HttpHandler(
            $container,
            $authenticator,
            '/var/www/phlix/public',
            $application,
            $metrics,
        );
    }

    /**
     * @param array{in?: int, out?: int} $bytes
     */
    private function makeConnection(array $bytes = []): TcpConnection
    {
        $conn = $this->createMock(TcpConnection::class);
        $conn->bytesRead = $bytes['in'] ?? 0;
        $conn->bytesWritten = $bytes['out'] ?? 0;

        return $conn;
    }

    private function makeRequest(string $method, string $path): WorkermanRequest
    {
        return new WorkermanRequest("{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    /**
     * @return array{registry: MetricsRegistry, collector: MetricsCollector}
     */
    private function makeMetrics(bool $enabled = true): array
    {
        $registry = new MetricsRegistry();
        // Fixed clock so the drained bucket is deterministic.
        $collector = new MetricsCollector($registry, $enabled, static fn (): int => 1_725_000_000);

        return ['registry' => $registry, 'collector' => $collector];
    }

    private function invokeRecord(
        HttpHandler $handler,
        TcpConnection $conn,
        WorkermanRequest $wr,
        int $status,
        int $startIn = 0,
        int $startOut = 0,
    ): void {
        $m = new \ReflectionMethod(HttpHandler::class, 'recordRequestMetrics');
        $m->setAccessible(true);
        // startTime is a monotonic hrtime(true) nanosecond reading (as captured in
        // __invoke); place it ~50 ms in the past so the elapsed millis are > 0.
        $m->invoke($handler, $conn, $wr, $status, hrtime(true) - 50_000_000, $startIn, $startOut);
    }

    private function invokeRouteTemplate(string $path): string
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'routeTemplate');
        $m->setAccessible(true);
        $result = $m->invoke(null, $path);
        self::assertIsString($result);

        return $result;
    }

    // --- routeTemplate() normalisation -------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routeTemplateProvider(): array
    {
        return [
            'root'                 => ['/', '/'],
            'empty'                => ['', '/'],
            'static words kept'    => ['/api/v1/media', '/api/v1/media'],
            'uuid collapsed'       => [
                '/api/v1/media/550e8400-e29b-41d4-a716-446655440000/stream',
                '/api/v1/media/{id}/stream',
            ],
            'numeric id collapsed' => ['/photo/photos/12345/full', '/photo/photos/{id}/full'],
            'hex object id'        => ['/library/item/a1b2c3d4e5f6', '/library/item/{id}'],
            'vite asset hash'      => ['/assets/app/index-DaB12cd3.js', '/assets/app/{id}'],
            'season/episode kept'  => ['/library/show/s01e02', '/library/show/s01e02'],
            'version segment kept' => ['/api/v1/health', '/api/v1/health'],
            'stable asset kept'    => ['/favicon.ico', '/favicon.ico'],
            'quality token kept'   => ['/stream/1080p/master', '/stream/1080p/master'],
        ];
    }

    /**
     * @dataProvider routeTemplateProvider
     */
    public function testRouteTemplateNormalisation(string $path, string $expected): void
    {
        self::assertSame($expected, $this->invokeRouteTemplate($path));
    }

    // --- recordRequestMetrics() end-to-end ---------------------------------

    public function testRecordsNormalisedRouteAndByteDeltas(): void
    {
        ['registry' => $registry, 'collector' => $collector] = $this->makeMetrics();
        $handler = $this->makeHandler($collector);
        $conn = $this->makeConnection(['in' => 1_500, 'out' => 5_500]);
        $wr = $this->makeRequest('GET', '/api/v1/media/550e8400-e29b-41d4-a716-446655440000/stream');

        $this->invokeRecord($handler, $conn, $wr, 206, startIn: 500, startOut: 500);

        $drained = $registry->drainRollups(1_725_000_000);

        self::assertCount(1, $drained['routes']);
        $route = $drained['routes'][0];
        self::assertSame('GET', $route['method']);
        self::assertSame('/api/v1/media/{id}/stream', $route['route']);
        self::assertSame(1, $route['request_count']);
        self::assertSame(0, $route['error_count']);

        $overall = array_values($drained['overall'])[0];
        self::assertSame(1_000, $overall['bytes_in']);
        self::assertSame(5_000, $overall['bytes_out']);
        self::assertSame(1, $overall['request_count']);
    }

    public function testCapturedServerErrorStatusIncrementsErrorCount(): void
    {
        ['registry' => $registry, 'collector' => $collector] = $this->makeMetrics();
        $handler = $this->makeHandler($collector);

        $this->invokeRecord($handler, $this->makeConnection(), $this->makeRequest('POST', '/api/v1/scan'), 503);

        $overall = array_values($registry->drainRollups(1_725_000_000)['overall'])[0];
        self::assertSame(1, $overall['request_count']);
        self::assertSame(1, $overall['error_count'], 'A 5xx status must classify as an error.');
    }

    public function testCapturedRedirectStatusIsNotAnError(): void
    {
        ['registry' => $registry, 'collector' => $collector] = $this->makeMetrics();
        $handler = $this->makeHandler($collector);

        // A 302 (e.g. "/" -> "/app") must NOT count as an error, and previously
        // every request was hardcoded to 200 regardless of the real status.
        $this->invokeRecord($handler, $this->makeConnection(), $this->makeRequest('GET', '/'), 302);

        $overall = array_values($registry->drainRollups(1_725_000_000)['overall'])[0];
        self::assertSame(0, $overall['error_count']);
    }

    public function testDisabledCollectorRecordsNothing(): void
    {
        ['registry' => $registry, 'collector' => $collector] = $this->makeMetrics(enabled: false);
        $handler = $this->makeHandler($collector);

        $this->invokeRecord($handler, $this->makeConnection(['in' => 10, 'out' => 20]), $this->makeRequest('GET', '/x'), 200);

        $drained = $registry->drainRollups(1_725_000_000);
        self::assertSame([], $drained['routes']);
        self::assertSame([], $drained['overall']);
    }

    public function testNullCollectorIsANoOp(): void
    {
        $handler = $this->makeHandler(null);

        // Must not throw when no collector was injected (metrics-off construction).
        $this->invokeRecord($handler, $this->makeConnection(), $this->makeRequest('GET', '/x'), 200);

        $this->expectNotToPerformAssertions();
    }
}
