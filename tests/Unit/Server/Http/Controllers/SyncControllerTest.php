<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Arr;

use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Arr\CustomFormatSyncer;
use Phlix\Server\Http\Controllers\Arr\SyncController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Shared\Arr\SyncResult;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SyncController.
 *
 * Covers all three controller actions: triggerSync, getSyncStatus, setEnabled.
 *
 * ## S323 — every case here now runs THROUGH the admin gate
 *
 * Before S323 the controller was built with no middleware at all, so
 * `requireAdmin()` waved every authenticated request through on its
 * `if ($this->adminMiddleware !== null)` branch. Each case below already sent
 * `userId = 'admin-1'` and asserted a handler outcome, which meant they pinned
 * that fail-open in EFFECT while their names and docblocks only ever described
 * the handler. The gate is now a required constructor dependency and the fixture
 * below admits exactly `admin-1`, so every assertion in this file is unchanged
 * but is now reached by a request an admin decision actually admitted.
 *
 * The gate's own three arms (anonymous / non-admin / admin) live in
 * {@see \Phlix\Tests\Unit\Server\Http\Controllers\SyncControllerAdminGateIsStructuralTest}.
 */
final class SyncControllerTest extends TestCase
{
    private SyncController $controller;
    private FakeCustomFormatSyncer $syncer;

    protected function setUp(): void
    {
        $this->syncer = new FakeCustomFormatSyncer();

        // A REAL gate that admits exactly `admin-1` — the user id every case
        // below sends. Not a permissive stub: a request from anyone else is
        // refused here exactly as it would be in production.
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1'
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null
        );

        $this->controller = new SyncController(
            $this->syncer,
            new AdminMiddleware($users, $this->createMock(AuditLogger::class))
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Failed to decode JSON body: ' . $body);
        }
        return $decoded;
    }

    public function test_triggerSync_returns_success_response(): void
    {
        $this->syncer->syncResult = new SyncResult(
            customFormatsAdded: 5,
            customFormatsUpdated: 2,
            qualityProfilesAdded: 1,
            qualityProfilesUpdated: 0,
            version: '1.0.0',
            syncedAt: new DateTimeImmutable()
        );

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->triggerSync($request, []);

        self::assertSame(200, $response->statusCode);
        /** @var array{success: mixed, message: mixed, data: array<string, mixed>} $body */
        $body = $this->decodeBody($response->body);
        self::assertTrue($body['success']);
        self::assertSame('TRaSH-Guides sync completed', $body['message']);
        self::assertSame(5, $body['data']['custom_formats_added']);
    }

    public function test_triggerSync_when_sync_fails_returns_500(): void
    {
        $this->syncer->syncException = new \RuntimeException('Network error');

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->triggerSync($request, []);

        self::assertSame(500, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Sync failed', $body['error']);
    }

    public function test_getSyncStatus_returns_status_info(): void
    {
        $this->syncer->lastSyncTime = 1700000000;
        $this->syncer->enabled = true;

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->getSyncStatus($request, []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertTrue($body['enabled']);
        self::assertNotNull($body['last_sync_at']);
        self::assertSame(1700000000, $body['last_sync_timestamp']);
    }

    public function test_getSyncStatus_when_never_synced_returns_null_time(): void
    {
        $this->syncer->lastSyncTime = null;
        $this->syncer->enabled = false;

        $request = new Request();
        $request->userId = 'admin-1';
        $response = $this->controller->getSyncStatus($request, []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertFalse($body['enabled']);
        self::assertNull($body['last_sync_at']);
        self::assertNull($body['last_sync_timestamp']);
    }

    public function test_setEnabled_with_true_enables_sync(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['enabled' => true];

        $response = $this->controller->setEnabled($request, []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertTrue($body['success']);
        self::assertTrue($body['enabled']);
        self::assertSame('TRaSH-Guides sync enabled', $body['message']);
        self::assertTrue($this->syncer->enabled);
    }

    public function test_setEnabled_with_false_disables_sync(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = ['enabled' => false];

        $response = $this->controller->setEnabled($request, []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertTrue($body['success']);
        self::assertFalse($body['enabled']);
        self::assertSame('TRaSH-Guides sync disabled', $body['message']);
        self::assertFalse($this->syncer->enabled);
    }

    public function test_setEnabled_without_enabled_field_returns_400(): void
    {
        $request = new Request();
        $request->userId = 'admin-1';
        $request->body = [];

        $response = $this->controller->setEnabled($request, []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decodeBody($response->body);
        self::assertFalse($body['success']);
        self::assertSame('Missing required field: enabled', $body['error']);
    }
}

/**
 * Fake CustomFormatSyncer for testing.
 *
 * @internal Test fixture only.
 */
final class FakeCustomFormatSyncer extends CustomFormatSyncer
{
    public ?SyncResult $syncResult = null;
    public ?\Throwable $syncException = null;
    public ?int $lastSyncTime = null;
    public bool $enabled = true;

    public function __construct()
    {
        // Skip parent constructor which needs real dependencies
    }

    public function syncAll(): SyncResult
    {
        if ($this->syncException !== null) {
            throw $this->syncException;
        }

        return $this->syncResult ?? new SyncResult(
            customFormatsAdded: 0,
            customFormatsUpdated: 0,
            qualityProfilesAdded: 0,
            qualityProfilesUpdated: 0,
            version: '0.0.0',
            syncedAt: new DateTimeImmutable()
        );
    }

    public function getLastSyncTime(): ?int
    {
        return $this->lastSyncTime;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
