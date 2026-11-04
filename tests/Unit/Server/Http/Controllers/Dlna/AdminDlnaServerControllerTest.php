<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Dlna;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\CdsServer;
use Phlix\Dlna\DlnaServer;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Controllers\Dlna\AdminDlnaServerController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S28 sub-task (a): the DLNA Start/Stop toggle is HONEST.
 *
 * Before S28 `start()`/`stop()` only flipped an in-memory bool on the single
 * worker's CdsServer — nothing persisted, no route change, no cross-worker
 * effect. The honest toggle instead PERSISTS `dlna.cds_enabled` via the shared
 * {@see SettingsRepository} (the same store the generic admin settings page
 * writes, and exactly what {@see \Phlix\Server\Core\Application::loadCdsRoutes()}
 * gates on at each worker's `onWorkerStart`) and schedules a graceful reload so
 * every worker re-reads it. `status()` reports the persisted intent (`enabled`),
 * this worker's frozen route state (`running`), and a transient `reloadPending`.
 *
 * These tests mock the settings store and the reload scheduler — NO real signal
 * is ever sent — and drive `EffectiveConfig` with a throwaway config dir so the
 * per-worker `running` view is deterministic.
 */
final class AdminDlnaServerControllerTest extends TestCase
{
    /** @var list<string> Temp config dirs to clean up. */
    private array $tempConfigDirs = [];

    /** @var list<string> E2W captured-logger dirs (INFO-SERVER-51 pins). */
    private array $e2wLoggerDirs = [];

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        foreach ($this->tempConfigDirs as $dir) {
            if (is_file($dir . '/dlna.php')) {
                unlink($dir . '/dlna.php');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempConfigDirs = [];
        foreach ($this->e2wLoggerDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->e2wLoggerDirs = [];
        LoggerFactory::reset();
        parent::tearDown();
    }

    /**
     * Point EffectiveConfig at a throwaway config dir whose `dlna.php` declares
     * a known `cds_enabled`, so `isRunningInThisWorker()` is deterministic.
     */
    private function bootRunningState(bool $running): void
    {
        $dir = sys_get_temp_dir() . '/phlix_dlna_cfg_' . uniqid('', true);
        mkdir($dir);
        file_put_contents(
            $dir . '/dlna.php',
            "<?php return ['cds_enabled' => " . ($running ? 'true' : 'false') . "];\n",
        );
        $this->tempConfigDirs[] = $dir;

        EffectiveConfig::bootstrap(null, null, $dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        self::assertIsString($response->body);
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    // ------------------------------------------------------------------
    // start() / stop() persist the setting and schedule a reload.
    // ------------------------------------------------------------------

    public function testStartPersistsEnabledAndSchedulesReload(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        // Currently disabled (so the enable request is not a no-op).
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->expects(self::once())
            ->method('set')
            ->with('dlna.cds_enabled', true, 'bool');

        $restart = $this->createMock(AdminRestartController::class);
        $restart->expects(self::once())
            ->method('scheduleGracefulReload')
            ->willReturn(true);

        // Best-effort immediate SSDP announce on THIS worker.
        $cds = $this->createMock(CdsServer::class);
        $cds->expects(self::once())->method('start');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setRestartController($restart);
        $controller->setCdsServer($cds);

        $response = $controller->start(new Request(), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertTrue($body['enabled']);
        self::assertTrue($body['reloadScheduled']);
    }

    public function testStopPersistsDisabledAndSchedulesReload(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        // Currently enabled → the disable request is meaningful.
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(true);
        $settings->expects(self::once())
            ->method('set')
            ->with('dlna.cds_enabled', false, 'bool');

        $restart = $this->createMock(AdminRestartController::class);
        $restart->expects(self::once())->method('scheduleGracefulReload')->willReturn(true);

        $cds = $this->createMock(CdsServer::class);
        $cds->expects(self::once())->method('stop');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setRestartController($restart);
        $controller->setCdsServer($cds);

        $response = $controller->stop(new Request(), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertFalse($body['enabled']);
        self::assertTrue($body['reloadScheduled']);
    }

    // ------------------------------------------------------------------
    // Idempotency: 409 when already in the desired state, no write, no reload.
    // ------------------------------------------------------------------

    public function testStartIsAConflictWhenAlreadyEnabled(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(true);
        $settings->expects(self::never())->method('set');

        $restart = $this->createMock(AdminRestartController::class);
        $restart->expects(self::never())->method('scheduleGracefulReload');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setRestartController($restart);

        $response = $controller->start(new Request(), []);

        self::assertSame(409, $response->statusCode);
        $body = $this->decode($response);
        self::assertFalse($body['success']);
        self::assertTrue($body['enabled']);
    }

    public function testStopIsAConflictWhenAlreadyDisabled(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->expects(self::never())->method('set');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);

        $response = $controller->stop(new Request(), []);

        self::assertSame(409, $response->statusCode);
        self::assertFalse($this->decode($response)['success']);
    }

    // ------------------------------------------------------------------
    // Degraded DI: 503 when the settings store is unwired.
    // ------------------------------------------------------------------

    public function testStartReturns503WhenSettingsStoreUnavailable(): void
    {
        // No settings repository set → cannot persist intent.
        $controller = new AdminDlnaServerController();

        $response = $controller->start(new Request(), []);

        self::assertSame(503, $response->statusCode);
        self::assertFalse($this->decode($response)['success']);
    }

    // ------------------------------------------------------------------
    // Persist failure surfaces as a 500 (no reload claimed).
    // ------------------------------------------------------------------

    public function testStartReturns500WhenPersistThrows(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->method('set')->willThrowException(new \RuntimeException('db down'));

        $restart = $this->createMock(AdminRestartController::class);
        $restart->expects(self::never())->method('scheduleGracefulReload');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setRestartController($restart);

        $response = $controller->start(new Request(), []);

        self::assertSame(500, $response->statusCode);
        $body = $this->decode($response);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Failed to persist', $body['message']);
    }

    // ------------------------------------------------------------------
    // Best-effort reload: still 200 (with an explanatory message) when the
    // reload scheduler is unwired — the persisted change stands.
    // ------------------------------------------------------------------

    public function testStartSucceedsButFlagsManualRestartWhenReloadUnavailable(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->expects(self::once())->method('set')->with('dlna.cds_enabled', true, 'bool');

        // No restart controller wired → reload cannot be scheduled.
        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);

        $response = $controller->start(new Request(), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertTrue($body['enabled']);
        self::assertFalse($body['reloadScheduled']);
        self::assertStringContainsString('restart the server', $body['message']);
    }

    // ------------------------------------------------------------------
    // status() is truthful about enabled vs running vs reloadPending.
    // ------------------------------------------------------------------

    public function testStatusFlagsReloadPendingWhenIntentAheadOfRunningState(): void
    {
        // Persisted intent = enabled, but THIS worker booted with the route off.
        $this->bootRunningState(false);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(true);

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);

        $body = $this->decode($controller->status(new Request(), []));

        self::assertTrue($body['enabled']);
        self::assertFalse($body['running']);
        self::assertTrue($body['reloadPending']);
    }

    public function testStatusReportsSteadyStateWhenIntentMatchesRunning(): void
    {
        // Persisted intent = enabled AND this worker booted with the route on.
        $this->bootRunningState(true);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(true);

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);

        $body = $this->decode($controller->status(new Request(), []));

        self::assertTrue($body['enabled']);
        self::assertTrue($body['running']);
        self::assertFalse($body['reloadPending']);
    }

    public function testStatusFallsBackToWorkerStateWhenNoSettingsStore(): void
    {
        // Degraded DI (no settings store): `enabled` must fall back to this
        // worker's real route state rather than lying.
        $this->bootRunningState(false);

        $controller = new AdminDlnaServerController();

        $body = $this->decode($controller->status(new Request(), []));

        self::assertFalse($body['enabled']);
        self::assertFalse($body['running']);
        self::assertFalse($body['reloadPending']);
    }

    /**
     * status() enriches the payload with the CdsServer's identity fields when a
     * server is wired.
     */
    public function testStatusIncludesCdsServerIdentityFields(): void
    {
        $this->bootRunningState(true);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(true);

        $dlnaServer = $this->createMock(DlnaServer::class);
        $dlnaServer->method('getFriendlyName')->willReturn('Phlix Library');

        $cds = $this->createMock(CdsServer::class);
        $cds->method('getDlnaServer')->willReturn($dlnaServer);
        $cds->method('getServerUdn')->willReturn('uuid:phlix-cds');
        $cds->method('getPort')->willReturn(1901);
        $cds->method('getBaseUrl')->willReturn('http://10.0.0.5:1901/');

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setCdsServer($cds);

        $body = $this->decode($controller->status(new Request(), []));

        self::assertSame('uuid:phlix-cds', $body['serverId']);
        self::assertSame('Phlix Library', $body['friendlyName']);
        self::assertSame(1901, $body['port']);
        self::assertSame('http://10.0.0.5:1901/', $body['baseUrl']);
    }

    /**
     * The best-effort immediate CdsServer announce/teardown is non-fatal: if it
     * throws, the persisted change and the scheduled reload still win (the reload
     * re-establishes the correct state).
     */
    public function testBestEffortCdsFailureDoesNotFailTheRequest(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->expects(self::once())->method('set')->with('dlna.cds_enabled', true, 'bool');

        $restart = $this->createMock(AdminRestartController::class);
        $restart->method('scheduleGracefulReload')->willReturn(true);

        $cds = $this->createMock(CdsServer::class);
        $cds->method('start')->willThrowException(new \RuntimeException('ssdp socket busy'));

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);
        $controller->setRestartController($restart);
        $controller->setCdsServer($cds);

        $response = $controller->start(new Request(), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response);
        self::assertTrue($body['success']);
        self::assertTrue($body['enabled']);
    }

    // ------------------------------------------------------------------
    // INFO-SERVER-51 (E2 cluster): the persist-failure 500 answers a FIXED
    // copy in the unchanged envelope. A settings-store failure typically
    // surfaces as a rethrown PDOException whose message embeds the full SQL
    // statement (the exact defect class M-SERVER-05 pinned for the admin
    // settings controller) — so the cause is log-only, never on the wire.
    // Red at the parent arm: 'Failed to persist DLNA setting: <SQL text>'.
    // ------------------------------------------------------------------

    public function testPersistFailureEnvelopeCarriesNoExceptionText(): void
    {
        // Estate captured-logger idiom (temp logger.php + init/reset).
        LoggerFactory::reset();
        $dir = sys_get_temp_dir() . '/phlix_e2w_dlnalog_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $this->e2wLoggerDirs[] = $dir;
        $logPath = $dir . '/app.log';
        file_put_contents(
            $dir . '/logger.php',
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
        LoggerFactory::init($dir . '/logger.php');

        $marker = 'SQL:UPDATE server_settings SET v = 0xCAFEB0BA WHERE k = [E2W-DLNA-MARKER] SQLSTATE[HY000]';

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with('dlna.cds_enabled')->willReturn(false);
        $settings->method('set')->willThrowException(new \PDOException($marker));

        $controller = new AdminDlnaServerController();
        $controller->setSettingsRepository($settings);

        $response = $controller->start(new Request(), []);

        self::assertSame(500, $response->statusCode);
        self::assertSame(
            ['success' => false, 'message' => 'Failed to persist DLNA setting.'],
            $this->decode($response)
        );
        self::assertStringNotContainsString('E2W-DLNA-MARKER', (string) $response->body);
        self::assertStringNotContainsString('SQLSTATE', (string) $response->body);

        $log = (string) file_get_contents($logPath);
        self::assertStringContainsString('DLNA content-directory toggle failed', $log);
        self::assertStringContainsString('PDOException', $log);
        self::assertStringContainsString('E2W-DLNA-MARKER', $log);
    }
}
