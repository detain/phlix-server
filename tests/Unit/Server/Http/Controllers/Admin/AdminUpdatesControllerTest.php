<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Controllers\Admin\AdminUpdatesController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use Phlix\Tests\Support\Updates\RecordingVersionMarkerFetcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * {@see AdminUpdatesController} — S74 / updates.md #48.
 *
 * The service underneath is REAL (over an in-memory `server_settings` table),
 * so the assertions below are about the endpoint's observable payload rather
 * than about a mock's return value.
 *
 * @package Phlix\Tests\Unit\Server\Http\Controllers\Admin
 */
final class AdminUpdatesControllerTest extends TestCase
{
    private const ADMIN_ID = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    private const PLAIN_ID = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

    private InMemoryServerSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new InMemoryServerSettingsConnection();
    }

    private function fetcher(?string $body, ?string $error = null): VersionMarkerFetcherInterface
    {
        return new RecordingVersionMarkerFetcher($body, $error);
    }

    private function service(?string $markerBody = null): CoreUpdateCheckService
    {
        return new CoreUpdateCheckService(
            new SettingsRepository($this->db, dirname(__DIR__, 6) . '/config'),
            $this->fetcher($markerBody),
            $this->createMock(StructuredLogger::class),
            'https://example.invalid/VERSION',
            'sudo bash install.sh --update -y',
            '1.2.2',
        );
    }

    private function adminMiddleware(): AdminMiddleware
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === self::ADMIN_ID
                ? ['id' => $id, 'is_admin' => 1, 'status' => 'active']
                : null,
        );

        return new AdminMiddleware($users, new AuditLogger($this->createMock(StructuredLogger::class)));
    }

    private function controller(?CoreUpdateCheckService $service = null): AdminUpdatesController
    {
        return new AdminUpdatesController($service ?? $this->service(), $this->adminMiddleware());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, ?string $userId, array $body = []): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/api/v1/admin/updates/status';
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true);

        return $decoded;
    }

    // ------------------------------------------------------------------
    // status()
    // ------------------------------------------------------------------

    public function testStatusReportsAnAvailableUpdateAfterANewerMarkerIsSeen(): void
    {
        $service = $this->service('9.9.9');
        $service->check();

        $response = $this->controller($service)->status($this->request('GET', self::ADMIN_ID));

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertTrue($data['updateAvailable']);
        self::assertSame('9.9.9', $data['latestVersion']);
        self::assertSame('1.2.2', $data['currentVersion']);
        self::assertSame('sudo bash install.sh --update -y', $data['updateCommand']);
    }

    public function testStatusReportsNoUpdateWhenTheMarkerMatches(): void
    {
        $service = $this->service('1.2.2');
        $service->check();

        $payload = $this->decode(
            (string) $this->controller($service)->status($this->request('GET', self::ADMIN_ID))->body,
        );
        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        self::assertFalse($data['updateAvailable']);
    }

    public function testStatusIsRefusedForAnAnonymousCaller(): void
    {
        $response = $this->controller()->status($this->request('GET', null));

        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', $this->decode((string) $response->body)['code']);
    }

    public function testStatusIsRefusedForANonAdmin(): void
    {
        $response = $this->controller()->status($this->request('GET', self::PLAIN_ID));

        self::assertSame(403, $response->statusCode);
        self::assertSame('auth.not_admin', $this->decode((string) $response->body)['code']);
    }

    // ------------------------------------------------------------------
    // updateSettings()
    // ------------------------------------------------------------------

    public function testTheToggleIsPersistedAndEchoedBack(): void
    {
        $service = $this->service('1.2.2');

        $response = $this->controller($service)
            ->updateSettings($this->request('PUT', self::ADMIN_ID, ['checkEnabled' => false]));

        self::assertSame(200, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertFalse($data['checkEnabled']);
        self::assertFalse($service->isCheckEnabled());
        self::assertSame('0', $this->db->storedValue(CoreUpdateCheckService::SETTING_CHECK_ENABLED));
    }

    public function testTheToggleCanBeTurnedBackOn(): void
    {
        $service = $this->service('1.2.2');
        $controller = $this->controller($service);

        $controller->updateSettings($this->request('PUT', self::ADMIN_ID, ['checkEnabled' => false]));
        $controller->updateSettings($this->request('PUT', self::ADMIN_ID, ['checkEnabled' => true]));

        self::assertTrue($service->isCheckEnabled());
    }

    public function testAMissingToggleIsRejected(): void
    {
        $response = $this->controller()->updateSettings($this->request('PUT', self::ADMIN_ID, []));

        self::assertSame(400, $response->statusCode);
        self::assertSame('invalid_payload', $this->decode((string) $response->body)['code']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonBooleanProvider(): array
    {
        return [
            'string "false"' => ['false'],
            'string "true"'  => ['true'],
            'integer 0'      => [0],
            'integer 1'      => [1],
            'null'           => [null],
            'array'          => [[]],
        ];
    }

    /**
     * A coerced toggle would let `"false"` (truthy) ENABLE the check and `0`
     * disable it — this setting decides whether a server ever learns about a
     * security release, so it is not coerced.
     *
     * @dataProvider nonBooleanProvider
     */
    public function testANonBooleanToggleIsRejectedRatherThanCoerced(mixed $value): void
    {
        $service = $this->service('1.2.2');

        $response = $this->controller($service)
            ->updateSettings($this->request('PUT', self::ADMIN_ID, ['checkEnabled' => $value]));

        self::assertSame(400, $response->statusCode);
        self::assertNull(
            $this->db->storedValue(CoreUpdateCheckService::SETTING_CHECK_ENABLED),
            'A rejected payload must not have written anything.',
        );
    }

    public function testTheToggleIsRefusedForANonAdmin(): void
    {
        $response = $this->controller()
            ->updateSettings($this->request('PUT', self::PLAIN_ID, ['checkEnabled' => false]));

        self::assertSame(403, $response->statusCode);
        self::assertNull($this->db->storedValue(CoreUpdateCheckService::SETTING_CHECK_ENABLED));
    }

    public function testTheToggleIsRefusedForAnAnonymousCaller(): void
    {
        $response = $this->controller()
            ->updateSettings($this->request('PUT', null, ['checkEnabled' => false]));

        self::assertSame(401, $response->statusCode);
        self::assertNull($this->db->storedValue(CoreUpdateCheckService::SETTING_CHECK_ENABLED));
    }

    // ------------------------------------------------------------------
    // Structural guarantees
    // ------------------------------------------------------------------

    /**
     * The admin gate must be a REQUIRED constructor parameter.
     *
     * PHP-DI's `autowire()` silently skips optional parameters, so a nullable
     * defaulted `AdminMiddleware` would resolve to null in production and the
     * in-handler gate would be permanently dead — while every test that passes
     * one explicitly stayed green. That exact failure has shipped in this repo
     * before (`BackupManager::$auditLogger`).
     */
    public function testTheAdminGateIsARequiredConstructorParameter(): void
    {
        $ctor = new ReflectionMethod(AdminUpdatesController::class, '__construct');
        $params = $ctor->getParameters();

        self::assertCount(2, $params);
        foreach ($params as $param) {
            self::assertFalse(
                $param->isOptional(),
                sprintf('$%s must not be optional — PHP-DI would skip it.', $param->getName()),
            );
            self::assertFalse($param->allowsNull(), sprintf('$%s must not be nullable.', $param->getName()));
        }
    }

    /**
     * There is deliberately no apply action, and this controller must never
     * shell out.
     */
    public function testTheControllerNeverShellsOut(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 6) . '/src/Server/Http/Controllers/Admin/AdminUpdatesController.php',
        );

        foreach (['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen(', 'system('] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'The update endpoint surfaces a copy-to-clipboard command; it must never run one.',
            );
        }

        self::assertFalse(
            method_exists(AdminUpdatesController::class, 'apply'),
            'No inline update-apply action — explicitly out of scope for S74.',
        );
    }
}
