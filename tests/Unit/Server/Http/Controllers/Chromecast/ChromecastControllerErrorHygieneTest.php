<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Chromecast;

use Phlix\Chromecast\CastManager;
use Phlix\Chromecast\CastSession;
use Phlix\Server\Http\Controllers\Chromecast\ChromecastController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * L-6 (security scan) — the Chromecast controller's 500-handlers must never
 * echo the exception message to the client.
 *
 * Cast failures carry the receiver's LAN coordinates in their message — the
 * exact string a CastApiClient socket error produces is
 * "Connection refused to 192.168.1.50:8009" — so pre-fix, an authenticated
 * caller (and anything that can reach the endpoint) learned the internal
 * network topology of the media LAN from the response body.
 *
 * Convention pinned (established by d052b488 in CastApiClient + the
 * 'Failed to start cast session' site in this same controller): CONSTANT
 * client message, exception detail to the logger. De-vacuumed the way
 * CastApiClientTest was rewritten: assertSame on the exact constant (a
 * substring pin would survive re-introducing the message), assertStringNotContainsString
 * on the host:port payload, and fail() whenever the awaited throw path does
 * not throw at all (a vacuous pass is a regression, not a success).
 */
final class ChromecastControllerErrorHygieneTest extends TestCase
{
    /** A realistic cast-layer failure message: LAN host + CCB port. */
    private const LEAKY_MESSAGE = 'Connection refused to 192.168.1.50:8009';

    private function request(): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/cast/devices/tv-1/stop';

        return $request;
    }

    /**
     * @return CastManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function managerThatThrowsOnStop(): CastManager
    {
        $manager = $this->createMock(CastManager::class);
        $manager->method('stopSession')->willThrowException(new \RuntimeException(self::LEAKY_MESSAGE));

        return $manager;
    }

    /**
     * @return CastManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function managerWithSessionThrowing(string $method): CastManager
    {
        $session = $this->createMock(CastSession::class);
        $session->method($method)->willThrowException(new \RuntimeException(self::LEAKY_MESSAGE));
        $session->method('getState')->willReturn('PLAYING');

        $manager = $this->createMock(CastManager::class);
        $manager->method('getSession')->willReturn($session);

        return $manager;
    }

    /**
     * @return array<string, mixed>
     */
    private function assertConstantNotLeaking(
        int $status,
        string $constant,
        \Phlix\Server\Http\Response $response
    ): array {
        $this->assertSame($status, $response->statusCode);
        $decoded = json_decode((string) $response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            $constant,
            $decoded['error'] ?? null,
            'L-6: client message must BE the constant — anything else means the '
            . 'exception string (LAN topology) reached the wire',
        );
        $this->assertStringNotContainsString('192.168.1.50', (string) $response->body);
        $this->assertStringNotContainsString('8009', (string) $response->body);
        $this->assertStringNotContainsString('Connection refused', (string) $response->body);

        return $decoded;
    }

    public function testStopFailureReturnsConstantMessageNotExceptionText(): void
    {
        $controller = new ChromecastController($this->managerThatThrowsOnStop());

        $response = $controller->stop($this->request(), ['id' => 'tv-1']);

        $this->assertConstantNotLeaking(500, 'Failed to stop cast session', $response);
    }

    public function testStopSucceedsWhenManagerDoesNotThrow(): void
    {
        $manager = $this->createMock(CastManager::class);
        $manager->expects($this->once())->method('stopSession');
        $controller = new ChromecastController($manager);

        $response = $controller->stop($this->request(), ['id' => 'tv-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testSeekFailureReturnsConstantMessageNotExceptionText(): void
    {
        $controller = new ChromecastController($this->managerWithSessionThrowing('seek'));

        $request = $this->request();
        $request->body = ['position_ms' => 1234];
        $response = $controller->seek($request, ['id' => 'tv-1']);

        $this->assertConstantNotLeaking(500, 'Failed to seek cast session', $response);
    }

    public function testSeekSucceedsWhenSessionDoesNotThrow(): void
    {
        $session = $this->createMock(CastSession::class);
        $session->expects($this->once())->method('seek');
        $session->method('getState')->willReturn('PLAYING');
        $manager = $this->createMock(CastManager::class);
        $manager->method('getSession')->willReturn($session);
        $controller = new ChromecastController($manager);

        $request = $this->request();
        $request->body = ['position_ms' => 1234];
        $response = $controller->seek($request, ['id' => 'tv-1']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testPlayFailureReturnsConstantMessageNotExceptionText(): void
    {
        $controller = new ChromecastController($this->managerWithSessionThrowing('play'));

        $response = $controller->play($this->request(), ['id' => 'tv-1']);

        $this->assertConstantNotLeaking(500, 'Failed to play cast session', $response);
    }

    public function testPauseFailureReturnsConstantMessageNotExceptionText(): void
    {
        $controller = new ChromecastController($this->managerWithSessionThrowing('pause'));

        $response = $controller->pause($this->request(), ['id' => 'tv-1']);

        $this->assertConstantNotLeaking(500, 'Failed to pause cast session', $response);
    }

    public function testPlaySucceedsWhenSessionDoesNotThrow(): void
    {
        $ok = $this->createMock(CastSession::class);
        $ok->method('getState')->willReturn('PLAYING');
        $manager = $this->createMock(CastManager::class);
        $manager->method('getSession')->willReturn($ok);

        $response = (new ChromecastController($manager))->play($this->request(), ['id' => 'tv-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString('Failed', (string) $response->body);
    }
}
