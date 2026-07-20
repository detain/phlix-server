<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the graceful restart endpoint (Phase 8).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests.
 * Here we assert the controller's restart-signal behaviour.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminRestartController
 */
final class AdminRestartControllerTest extends TestCase
{
    /** Temp PID file path. */
    private string $pidFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidFile = sys_get_temp_dir() . '/phlix_test_pid_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
        }
        parent::tearDown();
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->body = [];

        return $request;
    }

    public function testRestartFailsWhenPidFileIsMissing(): void
    {
        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('PID file not found', $body['error']);
    }

    public function testRestartFailsWhenPidFileIsEmpty(): void
    {
        file_put_contents($this->pidFile, '');

        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Invalid PID in file', $body['error']);
    }

    public function testRestartFailsWhenPidFileContainsNonNumericValue(): void
    {
        file_put_contents($this->pidFile, "not-a-pid\n");

        $controller = new AdminRestartController($this->pidFile);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Invalid PID in file', $body['error']);
    }

    public function testRestartFailsWhenSignalSendFails(): void
    {
        file_put_contents($this->pidFile, '99999'); // non-existent PID

        $controller = new TestableRestartController($this->pidFile, false);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(500, $response->statusCode);

        /** @var array{success: false, error: string} $body */
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Signal send failed', $body['error']);
    }

    public function testRestartSucceedsWhenSignalIsSent(): void
    {
        file_put_contents($this->pidFile, '12345');

        $controller = new TestableRestartController($this->pidFile, true);
        $response   = $controller->restart($this->makeRequest(), []);

        self::assertSame(200, $response->statusCode);

        /** @var array{success: true, message: string} $body */
        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertSame('Restart signal sent', $body['message']);
    }

    /**
     * @param mixed $body
     *
     * @return array<string, mixed>
     */
    private function decode($body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Lightweight test double for AdminRestartController that overrides
 * sendSignal to return a controlled result.
 */
final class TestableRestartController extends AdminRestartController
{
    private ?bool $signalResult;

    public function __construct(string $pidFile, ?bool $signalResult)
    {
        parent::__construct($pidFile);
        $this->signalResult = $signalResult;
    }

    protected function sendSignal(int $pid, int $signal): bool
    {
        return $this->signalResult ?? parent::sendSignal($pid, $signal);
    }
}
