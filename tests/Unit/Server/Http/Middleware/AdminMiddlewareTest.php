<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use support\Context;

/**
 * Unit tests for {@see AdminMiddleware} (Step A.5).
 *
 * @covers \Phlix\Server\Http\Middleware\AdminMiddleware
 */
final class AdminMiddlewareTest extends TestCase
{
    /**
     * Ensure a clean coroutine-local context between tests so the
     * Context-publication assertions don't see leakage from prior runs.
     */
    protected function setUp(): void
    {
        Context::destroy();
    }

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

    /**
     * On a successful admin gate, the middleware publishes the
     * authenticated user-id into the coroutine-local request context
     * (step 0.2b). Downstream services read it via
     * {@see RequestContext::getUserId()} instead of relying on
     * static/global state, which is unsafe under the Workerman 5 +
     * Swoole coroutine runtime.
     */
    public function test_publishes_user_id_to_request_context_on_admin_pass(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->with('admin-7')
            ->willReturn(['id' => 'admin-7', 'is_admin' => 1]);
        $audit = $this->createMock(AuditLogger::class);

        $middleware = new AdminMiddleware($users, $audit);

        $this->assertNull(RequestContext::getUserId(), 'baseline: no user-id in context');

        $middleware->checkAccess($this->makeRequest('admin-7'));

        $this->assertSame('admin-7', RequestContext::getUserId());
        $this->assertTrue(RequestContext::hasUserId());
    }

    /**
     * Conversely, when the admin gate REJECTS the request (401 or 403)
     * the middleware MUST NOT publish a user-id — otherwise a rejected
     * non-admin could observe their own user-id leaking into a
     * downstream service that defensively reads the context.
     */
    public function test_does_not_publish_user_id_on_401_or_403(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->with('not-admin')->willReturn(null);
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())->method('logPermissionDenied');

        $middleware = new AdminMiddleware($users, $audit);

        // 403 path
        $this->assertSame(403, $middleware->checkAccess($this->makeRequest('not-admin')));
        $this->assertNull(RequestContext::getUserId(), 'no user-id published on 403');
        $this->assertFalse(RequestContext::hasUserId());

        // 401 path
        $this->assertSame(401, $middleware->checkAccess($this->makeRequest(null)));
        $this->assertNull(RequestContext::getUserId(), 'no user-id published on 401');
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
