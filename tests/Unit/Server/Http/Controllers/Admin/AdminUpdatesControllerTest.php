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

    private function service(?string $markerBody = null): CoreUpdateCheckService
    {
        return $this->serviceWithFetcher(new RecordingVersionMarkerFetcher($markerBody));
    }

    /**
     * The REAL service over the in-memory `server_settings` table, with a
     * caller-supplied transport double — the shape S273's check() tests need
     * so they can flip the outcome between two endpoint calls.
     */
    private function serviceWithFetcher(RecordingVersionMarkerFetcher $fetcher): CoreUpdateCheckService
    {
        return new CoreUpdateCheckService(
            new SettingsRepository($this->db, dirname(__DIR__, 6) . '/config'),
            $fetcher,
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
    // check() — S273
    // ------------------------------------------------------------------

    /**
     * AC ① of S273: a test drives the ENDPOINT with a stubbed transport and
     * asserts the cached status is REPLACED. Both the seed and the trigger go
     * through `check()` — the endpoint, not the service — and the assertion
     * lands on the persisted `server_settings` row plus the response payload,
     * so "replaced" is proven against storage, not against a mock.
     */
    public function testTheCheckEndpointReplacesTheCachedStatus(): void
    {
        $fetcher = new RecordingVersionMarkerFetcher('5.5.5');
        $service = $this->serviceWithFetcher($fetcher);
        $controller = $this->controller($service);

        // Seed: the first endpoint call caches 5.5.5.
        self::assertSame(202, $controller->check($this->request('POST', self::ADMIN_ID))->statusCode);
        self::assertSame('5.5.5', $this->db->storedValue(CoreUpdateCheckService::STATE_LATEST_VERSION));

        // Trigger: a newer marker must REPLACE the cached value.
        $fetcher->willReturn('8.8.8');
        $response = $controller->check($this->request('POST', self::ADMIN_ID));

        self::assertSame(202, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        self::assertTrue($payload['success']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame('8.8.8', $data['latestVersion']);
        self::assertTrue($data['updateAvailable']);
        self::assertSame('8.8.8', $this->db->storedValue(CoreUpdateCheckService::STATE_LATEST_VERSION));
        self::assertSame(
            ['https://example.invalid/VERSION', 'https://example.invalid/VERSION'],
            $fetcher->urls,
            'Each endpoint call must drive the transport exactly once.',
        );
    }

    /**
     * AC ② of S273: a transport failure leaves the prior cached value
     * UNTOUCHED. The version row survives the failed check; only the error
     * text and the check timestamp move, so the operator still sees the last
     * release the server genuinely observed.
     */
    public function testCheckTransportFailureLeavesThePriorCachedStatusUntouched(): void
    {
        $fetcher = new RecordingVersionMarkerFetcher('7.7.7');
        $service = $this->serviceWithFetcher($fetcher);
        $controller = $this->controller($service);

        // Seed: a successful endpoint-driven check caches 7.7.7.
        $controller->check($this->request('POST', self::ADMIN_ID));
        self::assertSame('7.7.7', $this->db->storedValue(CoreUpdateCheckService::STATE_LATEST_VERSION));

        // Fail: the transport errors. The value must survive, not blank out.
        $fetcher->willReturn(null, 'connect timeout');
        $response = $controller->check($this->request('POST', self::ADMIN_ID));

        self::assertSame(202, $response->statusCode);
        self::assertSame(
            '7.7.7',
            $this->db->storedValue(CoreUpdateCheckService::STATE_LATEST_VERSION),
            'A failed check must not blank the last known version.',
        );
        /** @var array<string, mixed> $data */
        $data = $this->decode((string) $response->body)['data'];
        self::assertSame('7.7.7', $data['latestVersion']);
        self::assertTrue($data['updateAvailable']);
        // The failure is still REPORTED beside the surviving value.
        self::assertSame('connect timeout', $data['lastError']);
    }

    public function testTheCheckEndpointIsRefusedForAnAnonymousCaller(): void
    {
        $fetcher = new RecordingVersionMarkerFetcher('9.9.9');
        $service = $this->serviceWithFetcher($fetcher);

        $response = $this->controller($service)->check($this->request('POST', null));

        self::assertSame(401, $response->statusCode);
        self::assertSame('auth.required', $this->decode((string) $response->body)['code']);
        self::assertSame([], $fetcher->urls, 'A refused check must not touch the transport.');
    }

    public function testTheCheckEndpointIsRefusedForANonAdmin(): void
    {
        $fetcher = new RecordingVersionMarkerFetcher('9.9.9');
        $service = $this->serviceWithFetcher($fetcher);

        $response = $this->controller($service)->check($this->request('POST', self::PLAIN_ID));

        self::assertSame(403, $response->statusCode);
        self::assertSame('auth.not_admin', $this->decode((string) $response->body)['code']);
        self::assertSame([], $fetcher->urls, 'A refused check must not touch the transport.');
    }

    /**
     * A transport that THROWS SYNCHRONOUSLY (instead of calling back with an
     * error) must not become an unhandled 500 inside the resident worker, and
     * must leave nothing persisted. The S273 survival token lives in the
     * controller const asserted here — code-resident exactly once.
     */
    public function testCheckReportsServiceUnavailableWhenTheTransportThrowsSynchronously(): void
    {
        $fetcher = new RecordingVersionMarkerFetcher(null, null, true);
        $service = $this->serviceWithFetcher($fetcher);

        $response = $this->controller($service)->check($this->request('POST', self::ADMIN_ID));

        self::assertSame(503, $response->statusCode);
        $payload = $this->decode((string) $response->body);
        self::assertFalse($payload['success']);
        self::assertSame(
            AdminUpdatesController::SURVIVAL_TOKEN . ' core update check could not be dispatched',
            $payload['error'],
            'The dispatch-failure message must carry the token const — the literal lives once, in src.',
        );
        self::assertSame('update_check_dispatch_failed', $payload['code']);
        self::assertNull($this->db->storedValue(CoreUpdateCheckService::STATE_LATEST_VERSION));
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
            (new \ReflectionClass(AdminUpdatesController::class))->hasMethod('apply'),
            'No inline update-apply action — explicitly out of scope for S74.',
        );
    }

    /**
     * S273: the check endpoint dispatches its fetch through the callback-driven
     * transport, so the HANDLER itself must contain no wait primitive
     * (`usleep`/`sleep`) and no synchronous fetch (`file_get_contents`/cURL).
     * Any of those inside a resident Workerman/Swoole HTTP worker stalls every
     * connection that worker holds — exactly the hazard the step text flags.
     */
    public function testTheControllerNeverBlocksTheEventLoop(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 6) . '/src/Server/Http/Controllers/Admin/AdminUpdatesController.php',
        );

        foreach (['usleep(', 'sleep(', 'time_nanosleep(', 'file_get_contents(', 'curl_'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('Handler-level blocking primitive %s found — the outbound fetch must stay on the loop.', $forbidden),
            );
        }
    }
}
