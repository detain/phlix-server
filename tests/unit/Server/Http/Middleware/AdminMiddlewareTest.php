<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Server\Http\Middleware;

use Phlex\Auth\UserRepository;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Server\Http\Middleware\AdminMiddleware;
use Phlex\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AdminMiddleware} (Step A.5).
 *
 * @covers \Phlex\Server\Http\Middleware\AdminMiddleware
 */
final class AdminMiddlewareTest extends TestCase
{
    public function test_passes_through_admin_user(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findAdminById')
            ->with('user-1')
            ->willReturn(['id' => 'user-1', 'username' => 'root', 'is_admin' => 1]);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logPermissionDenied');

        $middleware = new AdminMiddleware($users, $audit);

        $request = $this->makeRequest('user-1');
        $result  = $middleware($request);

        $this->assertNull($result);
    }

    public function test_returns_403_for_non_admin_user(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())
            ->method('findAdminById')
            ->with('user-2')
            ->willReturn(null);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())
            ->method('logPermissionDenied')
            ->with('user-2', 'admin', 'access');

        $middleware = new AdminMiddleware($users, $audit);
        $request    = $this->makeRequest('user-2');

        $response = $middleware($request);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('auth.not_admin', $body['code'] ?? null);
    }

    public function test_returns_401_when_no_user_id_on_request(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findAdminById');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logPermissionDenied');

        $middleware = new AdminMiddleware($users, $audit);
        $request    = $this->makeRequest(null);

        $response = $middleware($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('auth.required', $body['code'] ?? null);
    }

    public function test_returns_401_when_user_id_is_empty_string(): void
    {
        $users = $this->createMock(UserRepository::class);
        $audit = $this->createMock(AuditLogger::class);

        $middleware = new AdminMiddleware($users, $audit);
        $request    = $this->makeRequest('');

        $response = $middleware($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->statusCode);
    }

    public function test_checkAccess_returns_null_for_admin_user(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->with('admin-1')
            ->willReturn(['id' => 'admin-1', 'is_admin' => 1]);
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logPermissionDenied');

        $middleware = new AdminMiddleware($users, $audit);
        $this->assertNull($middleware->checkAccess($this->makeRequest('admin-1')));
    }

    public function test_checkAccess_returns_401_when_anonymous(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findAdminById');
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->never())->method('logPermissionDenied');

        $middleware = new AdminMiddleware($users, $audit);
        $this->assertSame(401, $middleware->checkAccess($this->makeRequest(null)));
        $this->assertSame(401, $middleware->checkAccess($this->makeRequest('')));
    }

    public function test_checkAccess_returns_403_and_logs_when_not_admin(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->with('user-x')->willReturn(null);

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())
            ->method('logPermissionDenied')
            ->with('user-x', 'admin', 'access');

        $middleware = new AdminMiddleware($users, $audit);
        $this->assertSame(403, $middleware->checkAccess($this->makeRequest('user-x')));
    }

    private function makeRequest(?string $userId): Request
    {
        $request           = new Request();
        $request->method   = 'GET';
        $request->path     = '/api/v1/admin/plugins';
        $request->headers  = [];
        $request->query    = [];
        $request->body     = [];
        $request->files    = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId   = $userId;
        return $request;
    }
}
