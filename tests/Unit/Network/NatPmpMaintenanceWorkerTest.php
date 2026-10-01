<?php

/**
 * Phlix media server test: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\NatPmpClient;
use Phlix\Network\NatPmpMaintenance;
use Phlix\Network\NatPmpMaintenanceWorker;
use Phlix\Network\PortForwardService;
use Phlix\Network\StunClient;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Wiring pins for the resident NAT-PMP maintenance worker: the timer-arming
 * guard (the process-level double-execution defence), the tick state machine
 * driven through a REAL PortForwardService over a REAL temp state file with
 * mocked network clients, the full announcement decision matrix including the
 * source-pin drop rules and the retransmission-storm dedup, and every
 * degradation rung (bind failure -> renewal-only; join failure -> loud/quiet
 * split).
 *
 * Sockets: none are opened here at all — tick()/handleAnnouncement() are
 * public and clock-injectable, and the datagram path with a live socket is
 * proven separately in NatPmpAnnouncementWireTest. PHPUnit-level no-real-UDP
 * is the brief.
 *
 * @see \Phlix\Network\NatPmpMaintenanceWorker
 */
final class NatPmpMaintenanceWorkerTest extends TestCase
{
    private string $tmpDir;

    /** @var array<string,Worker> */
    private array $savedWorkers = [];

    /** @var array<string, array<int, int>> */
    private array $savedPidMap = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-natpmp-maint-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);

        // Worker's registry is what makes Timer::add legal outside runAll();
        // constructing a worker below registers it. Snapshot so a leaked
        // instance can never pollute a sibling test.
        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<string,Worker> $current */
        $current = $workers->getValue();
        $this->savedWorkers = $current;

        $pidMap = new ReflectionProperty(Worker::class, 'pidMap');
        $pidMap->setAccessible(true);
        /** @var array<string, array<int, int>> $pids */
        $pids = $pidMap->getValue();
        $this->savedPidMap = $pids;

        Timer::delAll();
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        $pidMap = new ReflectionProperty(Worker::class, 'pidMap');
        $pidMap->setAccessible(true);
        $pidMap->setValue(null, $this->savedPidMap);

        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    // ==================================================================
    // Harness
    // ==================================================================

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function grantState(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'method' => 'natpmp',
            'external_ip' => '203.0.113.7',
            'port' => 32400,
            'gateway_ip' => '192.168.1.1',
            'mapping_granted_lifetime' => 7200,
            // Comfortably in the future until a test moves it.
            'mapping_renew_at' => time() + 3000,
        ], $overrides);
    }

    private function service(
        NatPmpClient $natpmp,
        ?UpnpIgdClient $upnp = null,
        ?StunClient $stun = null,
        bool $autoEnabled = true
    ): PortForwardService {
        return new PortForwardService(
            $upnp ?? $this->createMock(UpnpIgdClient::class),
            $stun ?? $this->createMock(StunClient::class),
            $natpmp,
            new NullLogger(),
            32400,
            $autoEnabled,
            $this->tmpDir
        );
    }

    /**
     * Ephemeral loopback bind address: the constructor NEVER binds (that is
     * listen()'s job, which these tests mostly skip), so the port value only
     * has to be syntactically real.
     */
    private function worker(PortForwardService $service, ?LoggerInterface $logger = null): NatPmpMaintenanceWorker
    {
        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0', $logger);
        $this->withoutWarning(static function () use ($worker, $service, $logger): void {
            $worker->serve($service, $logger ?? new NullLogger());
        });
        return $worker;
    }

    /**
     * Run a callable with PHP warnings collected instead of escalated.
     *
     * serve()->arm()->joinMulticastGroup() legitimately warns (no socket is
     * bound in most of these tests); we want the warning text ASSERTABLE,
     * not converted into a test error by PHPUnit 10's handler.
     *
     * @return list<string>
     */
    private function withoutWarning(callable $fn): array
    {
        $seen = [];
        set_error_handler(static function (int $errno, string $message) use (&$seen): bool {
            if ($errno === E_USER_WARNING || $errno === E_WARNING) {
                $seen[] = $message;
                return true;
            }
            return false;
        });
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $seen;
    }

    /**
     * Flatten Workerman's Timer::$tasks buckets into [taskId => [interval, persistent]].
     *
     * Tuple shape measured in vendor Timer::add(): [callback, args, persistent, interval].
     *
     * @return array<int, array{0: float, 1: bool}>
     */
    private function timerTasks(): array
    {
        $tasks = new ReflectionProperty(Timer::class, 'tasks');
        $tasks->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $buckets */
        $buckets = $tasks->getValue();

        $flat = [];
        foreach ($buckets as $byId) {
            foreach ($byId as $id => $tuple) {
                $flat[(int) $id] = [$tuple[3], $tuple[2]];
            }
        }
        return $flat;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readStateFile(): ?array
    {
        $file = $this->tmpDir . '/config/port-forward.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeStateFile(array $state): void
    {
        @mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/port-forward.json', json_encode($state, JSON_PRETTY_PRINT));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ==================================================================
    // The double-execution guard (process-level single-owner proof)
    // ==================================================================

    /**
     * PROOF OF SINGLE OWNERSHIP (one half of it): within a process, arming is
     * idempotent — a doubled serve()/arm() cannot stack a second (renewal
     * catch-up + periodic) pair, which is the in-process defence against
     * double-renewing the same gateway grant. The other half (exactly ONE
     * process) is start.php §4f-bis count=1, documented on the class.
     */
    public function test_serve_arms_exactly_one_catchup_plus_one_periodic_timer(): void
    {
        $service = $this->service($this->createMock(NatPmpClient::class));
        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0');

        $this->withoutWarning(static function () use ($worker, $service): void {
            $worker->serve($service);
        });
        $first = $this->timerTasks();

        self::assertCount(2, $first, 'One boot catch-up + one periodic tick, nothing else.');

        $intervals = [];
        foreach ($first as [$interval, $persistent]) {
            $intervals[(int) $interval] = $persistent;
        }
        self::assertSame(
            [
                NatPmpMaintenanceWorker::BOOT_CATCHUP_SECONDS => false, // one-shot
                NatPmpMaintenanceWorker::TICK_SECONDS => true,          // persistent
            ],
            $intervals,
            'The persistent arm must be the 60s tick; the catch-up must be one-shot.'
        );

        $worker->serve($this->service($this->createMock(NatPmpClient::class)));
        $worker->arm();
        self::assertCount(
            2,
            $this->timerTasks(),
            'serve()/arm() are idempotency-guarded: re-arming must not add timers.'
        );
    }

    public function test_worker_without_serve_is_inert(): void
    {
        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0');

        self::assertFalse($worker->isArmed());
        self::assertSame(['action' => NatPmpMaintenanceWorker::TICK_NO_SERVICE], $worker->tick());
        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_DROP_NO_SERVICE,
            $worker->handleAnnouncement('192.168.1.1', chr(0) . chr(128) . pack('n', 0) . pack('N', 1) . inet_pton('10.0.0.1'))
        );
    }

    // ==================================================================
    // tick(): the renewal state machine through the real service+file
    // ==================================================================

    public function test_tick_no_ops_without_state_file(): void
    {
        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');
        $worker = $this->worker($this->service($natpmp));

        self::assertSame(
            ['action' => NatPmpMaintenance::ACTION_SKIP_NO_STATE],
            $worker->tick(),
            'Never-configured installs must cost zero network and zero state writes.'
        );
        self::assertNull($this->readStateFile());
    }

    public function test_tick_waits_inside_the_halfway_window(): void
    {
        $this->writeStateFile(self::grantState());
        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');
        $worker = $this->worker($this->service($natpmp));

        self::assertSame(
            ['action' => NatPmpMaintenance::ACTION_WAIT_NOT_DUE],
            $worker->tick(),
            '§3.3: nothing before halfway-to-expiry.'
        );
    }

    public function test_disabled_state_is_never_renewed(): void
    {
        // The operator ran disable(); plan() must stop the tick even though a
        // stale-looking deadline would otherwise read as due.
        $this->writeStateFile(self::grantState([
            'enabled' => false,
            'mapping_renew_at' => time() - 60,
        ]));
        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');
        $natpmp->expects(self::never())->method('addPortMapping');
        $worker = $this->worker($this->service($natpmp));

        self::assertSame(['action' => NatPmpMaintenance::ACTION_SKIP_DISABLED], $worker->tick());
    }

    public function test_upnp_state_is_never_renewed_over_natpmp(): void
    {
        $this->writeStateFile(self::grantState([
            'method' => 'upnp',
            'mapping_renew_at' => time() - 60,
        ]));
        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');
        $worker = $this->worker($this->service($natpmp));

        self::assertSame(['action' => NatPmpMaintenance::ACTION_SKIP_NOT_NATPMP], $worker->tick());
    }

    public function test_tick_renews_at_deadline_rolling_deadline_to_half_the_new_grant(): void
    {
        $this->writeStateFile(self::grantState([
            'port' => 32401, // the gateway previously REASSIGNED the port
            'mapping_renew_at' => time() - 1,
        ]));

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->method('discoverGateway')->willReturn('203.0.113.7');
        // RFC 6886 §3.3 lines 679-681: Suggested External Port = the
        // previously-MAPPED port (32401), Suggested Internal Port = our own
        // (32400). This is THE renewal-packet-shape pin.
        $natpmp->expects(self::once())
            ->method('addPortMapping')
            ->with(self::anything(), 32401, 32400)
            ->willReturn(['external_port' => 32401, 'granted_lifetime' => 600]);

        $upnp = $this->createMock(UpnpIgdClient::class);
        $upnp->expects(self::never())->method('discoverGateway');

        $worker = $this->worker($this->service($natpmp, $upnp));
        $result = $worker->tick();

        self::assertSame(NatPmpMaintenanceWorker::TICK_RENEWED, $result['action']);
        self::assertTrue($result['renewed']);

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertSame('natpmp', $state['method']);
        self::assertTrue($state['enabled']);
        self::assertSame(600, $state['mapping_granted_lifetime']);
        // Half of the GRANTED (600) lifetime, not the requested 7200.
        self::assertIsInt($state['mapping_renew_at']);
        self::assertGreaterThanOrEqual(time() + 295, $state['mapping_renew_at']);
        self::assertLessThanOrEqual(time() + 305, $state['mapping_renew_at']);
        // Success clears the failure ledger so the next outage starts at 60s.
        self::assertNull($state['mapping_retry_at']);
        self::assertSame(0, $state['mapping_failed_attempts']);
        // Announcement source-pin written on the renewal path too.
        self::assertIsString($state['gateway_ip']);
        self::assertNotSame('', $state['gateway_ip']);
    }

    public function test_failed_renewal_arms_backoff_and_tick_stops_hot_looping(): void
    {
        $this->writeStateFile(self::grantState(['mapping_renew_at' => time() - 1]));

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::exactly(2))
            ->method('discoverGateway')
            ->willReturn(null); // gateway silent (no reply within its bound)

        $worker = $this->worker($this->service($natpmp));

        $first = $worker->tick();
        self::assertSame(NatPmpMaintenanceWorker::TICK_RENEWAL_FAILED, $first['action']);
        self::assertSame('gateway-silent', $first['reason']);

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertSame(1, $state['mapping_failed_attempts']);
        self::assertIsInt($state['mapping_retry_at']);

        // A second tick inside the window: ZERO extra exchanges. This is the
        // anti-hot-loop proof — without the backoff precedence every 60s tick
        // would re-enter a ~3-6s blocking exchange against a dead gateway.
        $inside = $worker->tick(time() + 30);
        self::assertSame(['action' => NatPmpMaintenance::ACTION_WAIT_BACKOFF], $inside);

        // Past the window: the second (and last) exchange fires.
        $after = $worker->tick(time() + 61);
        self::assertSame('gateway-silent', $after['reason'] ?? $after['action']);

        $grown = $this->readStateFile();
        self::assertIsArray($grown);
        self::assertSame(2, $grown['mapping_failed_attempts']);
        // nextRetryDelay(2) = 120 — the doubling curve is observable in state.
        self::assertGreaterThanOrEqual(time() + 115, (int) $grown['mapping_retry_at']);
    }

    // ==================================================================
    // handleAnnouncement(): the full decision matrix
    // ==================================================================

    private function announcementFrom(string $sourceIp, string $externalIp, int $sssoe, NatPmpClient $natpmp, bool $serve = true): string
    {
        $worker = $serve ? $this->worker($this->service($natpmp)) : new NatPmpMaintenanceWorker('udp://127.0.0.1:0');
        $datagram = chr(0) . chr(NatPmpMaintenance::ANNOUNCEMENT_OPCODE)
            . pack('n', 0) . pack('N', $sssoe) . inet_pton($externalIp);
        return $worker->handleAnnouncement($sourceIp, $datagram, time());
    }

    public function test_announcement_without_state_is_dropped(): void
    {
        $outcome = $this->announcementFrom('192.168.1.1', '203.0.113.7', 100, $this->createMock(NatPmpClient::class));
        self::assertSame(NatPmpMaintenanceWorker::ANNOUNCE_DROP_NO_STATE, $outcome);
    }

    public function test_announcement_is_dropped_for_disabled_and_foreign_method_state(): void
    {
        $this->writeStateFile(self::grantState(['enabled' => false]));
        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_DROP_DISABLED,
            $this->announcementFrom('192.168.1.1', '203.0.113.7', 100, $this->createMock(NatPmpClient::class))
        );

        $this->writeStateFile(self::grantState(['method' => 'upnp']));
        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_DROP_NOT_NATPMP,
            $this->announcementFrom('192.168.1.1', '203.0.113.7', 100, $this->createMock(NatPmpClient::class))
        );
    }

    public function test_announcement_without_a_gateway_pin_is_dropped_not_trusted(): void
    {
        // The pin IS the trust anchor. No pin => nothing can be authenticated
        // => drop. Falling back to "trust whoever announced" is exactly the
        // unauthenticated-LAN vector this gate exists to close.
        $state = self::grantState();
        unset($state['gateway_ip']);
        $this->writeStateFile($state);

        $outcome = $this->announcementFrom('192.168.1.1', '203.0.113.7', 100, $this->createMock(NatPmpClient::class));
        self::assertSame(NatPmpMaintenanceWorker::ANNOUNCE_DROP_NO_GATEWAY_PIN, $outcome);
        self::assertArrayNotHasKey('mapping_last_sssoe', $this->readStateFile() ?? []);
    }

    public function test_announcement_from_a_non_gateway_source_is_dropped_and_state_untouched(): void
    {
        $this->writeStateFile(self::grantState(['mapping_renew_at' => time() - 10_000]));

        // Source 198.51.100.66 (TEST-NET-2) impersonating the gateway with a
        // REGRESSION sssoe: the exact bait that would trigger a full
        // reconfigure cascade if the source pin were absent.
        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');

        $outcome = $this->announcementFrom('198.51.100.66', '203.0.113.99', 1, $natpmp);
        self::assertSame(NatPmpMaintenanceWorker::ANNOUNCE_DROP_SOURCE_MISMATCH, $outcome);

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertArrayNotHasKey('mapping_last_sssoe', $state, 'A dropped packet must not touch state.');
        self::assertSame('203.0.113.7', $state['external_ip']);
        self::assertSame(time() - 10_000, $state['mapping_renew_at']);
    }

    public function test_malformed_datagram_from_the_gateway_is_dropped(): void
    {
        $this->writeStateFile(self::grantState());
        $worker = $this->worker($this->service($this->createMock(NatPmpClient::class)));

        $outcome = $worker->handleAnnouncement('192.168.1.1', 'not-a-natpmp-announcement-at-all', time());
        self::assertSame(NatPmpMaintenanceWorker::ANNOUNCE_DROP_MALFORMED, $outcome);
        self::assertArrayNotHasKey('mapping_last_sssoe', $this->readStateFile() ?? []);
    }

    public function test_consistent_announcement_just_records_the_observation(): void
    {
        $this->writeStateFile(self::grantState(['mapping_last_sssoe' => 50]));

        $outcome = $this->announcementFrom('192.168.1.1', '203.0.113.7', 900, $this->createMock(NatPmpClient::class));
        self::assertSame(NatPmpMaintenanceWorker::ANNOUNCE_OBSERVED_UNCHANGED, $outcome);

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertSame(900, $state['mapping_last_sssoe'], 'Baseline advances so future regressions are visible.');
        self::assertIsInt($state['mapping_last_announcement_at']);
        self::assertSame('203.0.113.7', $state['external_ip'], 'Unchanged observation must not rewrite the address.');
    }

    public function test_sssoe_regression_triggers_reconfigure_and_dedups_the_retransmit_storm(): void
    {
        // §3.2.1: gateways repeat an announcement many times in the first
        // minute. The FIRST must re-cascade; every repeat must fall to the
        // cheap path because the observation was committed BEFORE acting.
        $this->writeStateFile(self::grantState([
            'mapping_last_sssoe' => 86_400,
            'mapping_renew_at' => time() + 3000,
        ]));

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::once())
            ->method('discoverGateway')
            ->willReturn('203.0.113.7');
        $natpmp->expects(self::once())
            ->method('addPortMapping')
            ->willReturn(['external_port' => 32400, 'granted_lifetime' => 3600]);

        $upnp = $this->createMock(UpnpIgdClient::class);
        $upnp->method('discoverGateway')->willReturn(null); // IGD absent → natpmp leg

        $service = $this->service($natpmp, $upnp);
        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0');
        $this->withoutWarning(static function () use ($worker, $service): void {
            $worker->serve($service);
        });

        $datagram = chr(0) . chr(128) . pack('n', 0) . pack('N', 5) . inet_pton('203.0.113.7');

        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_RECONFIGURED,
            $worker->handleAnnouncement('192.168.1.1', $datagram, time()),
            'Same IP + lower SSSoE = gateway rebooted = mapping gone = full re-cascade.'
        );

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertSame(5, $state['mapping_last_sssoe']);
        self::assertSame(3600, $state['mapping_granted_lifetime']);
        self::assertGreaterThanOrEqual(time() + 1795, (int) $state['mapping_renew_at']);
        $afterCascade = $state;

        // Replay the burst: nine more copies arrive (RFC-mandated retransmits).
        for ($i = 0; $i < 9; $i++) {
            self::assertSame(
                NatPmpMaintenanceWorker::ANNOUNCE_OBSERVED_UNCHANGED,
                $worker->handleAnnouncement('192.168.1.1', $datagram, time() + $i + 1)
            );
        }

        // No second cascade ran: the once()/once() mock expectations above
        // prove it in the mock layer, and byte-stable renewal bookkeeping
        // proves it on disk (a second autoConfigure would have re-rolled
        // mapping_renew_at forward).
        self::assertSame(
            [
                $afterCascade['mapping_renew_at'],
                $afterCascade['mapping_last_sssoe'],
                $afterCascade['mapping_granted_lifetime'],
            ],
            [
                $this->readStateFile()['mapping_renew_at'] ?? null,
                $this->readStateFile()['mapping_last_sssoe'] ?? null,
                $this->readStateFile()['mapping_granted_lifetime'] ?? null,
            ],
            'Retransmits must be pure no-ops for the persisted grant.'
        );
    }

    public function test_ip_change_adopts_the_announcement_even_when_reconfigure_fails(): void
    {
        // Commit-first discipline: when autoConfigure() cannot re-establish
        // anything, the observation must STILL be recorded, or every
        // retransmit re-triggers a full cascade against a dead router.
        $this->writeStateFile(self::grantState(['mapping_last_sssoe' => 86_400]));

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::once())->method('discoverGateway')->willReturn(null);
        $natpmp->expects(self::never())->method('addPortMapping');

        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0');
        $service = $this->service($natpmp, $this->createMock(UpnpIgdClient::class));
        $this->withoutWarning(static function () use ($worker, $service): void {
            $worker->serve($service);
        });

        $datagram = chr(0) . chr(128) . pack('n', 0) . pack('N', 9) . inet_pton('198.51.100.99');

        $beforeCascade = $this->readStateFile();
        self::assertIsArray($beforeCascade);

        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_RECONFIGURE_FAILED,
            $worker->handleAnnouncement('192.168.1.1', $datagram, time())
        );

        $state = $this->readStateFile();
        self::assertIsArray($state);
        self::assertSame('198.51.100.99', $state['external_ip'], 'Announced truth adopted before the failed cascade.');
        self::assertSame(9, $state['mapping_last_sssoe']);
        // Old deadline intact: the tick's own renew/backoff ladder keeps
        // retrying from the persisted state, bounded. autoConfigure()'s
        // failure path persists NOTHING renewal-related, so the pre-cascade
        // deadline survives.
        self::assertSame($beforeCascade['mapping_renew_at'], $state['mapping_renew_at']);

        // Retransmit dedup survives the failure path (this is WHY commit-first).
        self::assertSame(
            NatPmpMaintenanceWorker::ANNOUNCE_OBSERVED_UNCHANGED,
            $worker->handleAnnouncement('192.168.1.1', $datagram, time() + 2)
        );
    }

    // ==================================================================
    // Degradation rungs
    // ==================================================================

    public function test_bind_failure_degrades_to_renewal_only_never_fatal(): void
    {
        // Port 53 as an unprivileged user: bind fails with EACCES, which is
        // ordinary for this listener and must only degrade it.
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('privileged-port bind cannot fail as root; CI runs unprivileged.');
        }

        $logger = $this->createMock(LoggerInterface::class);

        $worker = new NatPmpMaintenanceWorker('udp://0.0.0.0:53', $logger);

        $warnings = $this->withoutWarning(static function () use ($worker): void {
            $worker->listen(false);
        });

        // Exactly ONE warning from OUR contract (Workerman's raw
        // stream_socket_server E_WARNING may also ride along — that is
        // vendor noise, not this class's alarm, so filter by contract text).
        $ours = array_values(array_filter(
            $warnings,
            static fn (string $w): bool => str_contains($w, 'NAT-PMP maintenance could not bind')
        ));
        self::assertCount(1, $ours, 'The bind failure must surface as exactly one loud warning.');
        self::assertStringContainsString('could not bind udp://0.0.0.0:53', $ours[0]);
        self::assertStringContainsString('renewals continue', $ours[0]);
        self::assertTrue($worker->isListenerDegraded());

        // Renewal-only mode: serve still arms and the tick still works (state
        // file absent → clean idle), proving the degradation rung costs
        // announcements and nothing more.
        $service = $this->service($this->createMock(NatPmpClient::class));
        $lateWarnings = $this->withoutWarning(static function () use ($worker, $service): void {
            $worker->serve($service);
        });
        self::assertSame([], $lateWarnings, 'join-on-missing-socket is the already-announced quiet rung.');
        self::assertTrue($worker->isArmed());
        self::assertSame(['action' => NatPmpMaintenance::ACTION_SKIP_NO_STATE], $worker->tick(time()));
    }

    public function test_join_without_socket_logs_quietly_because_listen_already_shouted(): void
    {
        // arm() runs after a FAILED listen() in production; join then finds no
        // socket. The bind failure was already announced once, loudly, by
        // listen() — join must not double-ring the same alarm.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('multicast join failed'),
                self::callback(static fn (array $context): bool =>
                    ($context['reason'] ?? null) === 'listener socket not bound (renewal-only mode)')
            );

        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0', $logger);

        $warnings = $this->withoutWarning(static function () use ($worker): void {
            $worker->arm();
        });

        self::assertSame([], $warnings, 'No PHP warning on the already-announced degradation.');
        self::assertFalse($worker->hasJoinedMulticastGroup());
    }

    public function test_join_failure_with_a_real_socket_is_loud(): void
    {
        // A BOUND socket whose join is REJECTED (bogus out-of-range interface
        // index) is a NEW degradation nothing else announced — that one must
        // ring. (The only environment where MCAST_JOIN_GROUP can reject this
        // way is one that has it at all, hence the guard.)
        if (!function_exists('socket_import_stream') || !defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets unavailable.');
        }

        $worker = new NatPmpMaintenanceWorker('udp://127.0.0.1:0');
        $this->withoutWarning(static function () use ($worker): void {
            $worker->listen(false);
        });

        try {
            if (!$worker->isListenerDegraded()) {
                $warnings = $this->withoutWarning(
                    static fn () => $worker->joinMulticastGroup(1_000_000)
                );
                self::assertFalse($worker->hasJoinedMulticastGroup());
                $ours = array_values(array_filter(
                    $warnings,
                    static fn (string $w): bool => str_contains($w, 'could not join multicast group')
                ));
                self::assertCount(1, $ours, 'A rejected join on a live socket must be loud.');
                self::assertStringContainsString('224.0.0.1', $ours[0]);
            }
        } finally {
            $worker->unlisten();
        }
    }

    public function test_stop_timers_clears_both_arms(): void
    {
        $service = $this->service($this->createMock(NatPmpClient::class));
        $worker = $this->worker($service);
        self::assertCount(2, $this->timerTasks());

        $worker->stopTimers();
        self::assertSame([], $this->timerTasks(), 'onWorkerStop must leave no armed renewal behind.');
        $worker->stopTimers(); // idempotent, no "invalid timer id" errors
    }
}
