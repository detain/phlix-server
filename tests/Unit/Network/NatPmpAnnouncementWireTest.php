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
use Psr\Log\NullLogger;
use ReflectionProperty;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Wire proof for the §3.2.1 announcement listener: REAL datagrams, a REAL
 * bound UDP socket, and Workerman's OWN dispatch path
 * (`listen()` → `serve()` → `resumeAccept()` on a `Select` loop, the exact
 * ordering `Worker::run()` uses), because — per the S51 lesson recorded in
 * SsdpMSearchListenerTest — a listener that never actually receives looks
 * exactly like a gateway that never announces. Every claim in
 * NatPmpMaintenanceWorkerTest is made here again through the socket layer:
 *
 *   - a packet FROM the pinned gateway reaches onMessage and advances the
 *     persisted observation (which also proves `getRemoteIp()` really carries
 *     the kernel-seen source — a stub source would fail the pin instead);
 *   - the SAME packet from a DIFFERENT source is dropped by the source pin
 *     with the state file untouched (the LAN-impersonation defence, measured
 *     on the wire, not in a unit call);
 *   - malformed bytes from the gateway are dropped;
 *   - an address-change announcement is ADOPTED (commit-first) even though the
 *     re-cascade runs entirely on mocked clients, and its retransmit is then
 *     deduped — on the wire;
 *   - multicast 224.0.0.1 delivery works through the production
 *     `MCAST_JOIN_GROUP` array-form join (environment-gated with a raw-socket
 *     probe BEFORE anything under test is built, so "no multicast here"
 *     is never mistaken for a broken join).
 *
 * No real gateway is ever contacted: the PortForwardService under test carries
 * mocked IGD/NAT-PMP/STUN clients, so any cascade the detection fires is
 * answered instantly from memory. Only loopback UDP leaves the process.
 *
 * Assertion-escape discipline (mirrors SsdpMSearchListenerTest): the loop
 * callbacks only poll; every assertion runs after `run()` returns.
 */
final class NatPmpAnnouncementWireTest extends TestCase
{
    /** Pinned gateway for unicast legs (every 127/8 is local on Linux). */
    private const GATEWAY_IP = '127.0.0.99';

    /** Impostor source for the drop leg. */
    private const ROGUE_IP = '127.0.0.1';

    private const LOOP_BUDGET_SECONDS = 3.0;

    private string $tmpDir;

    private ?array $savedWorkers = null;

    private ?EventInterface $savedGlobalEvent = null;

    private ?EventInterface $savedTimerEvent = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phlix-natpmp-wire-' . uniqid('', true);
        mkdir($this->tmpDir . '/config', 0700, true);

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<string,Worker> $current */
        $current = $workers->getValue();
        $this->savedWorkers = $current;

        $this->savedGlobalEvent = Worker::$globalEvent;
        $this->savedTimerEvent = $this->timerEvent();
    }

    protected function tearDown(): void
    {
        Timer::delAll();
        Worker::$globalEvent = $this->savedGlobalEvent;
        $this->setTimerEvent($this->savedTimerEvent);

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    // ==================================================================
    // Proofs
    // ==================================================================

    public function test_a_real_datagram_from_the_pinned_gateway_is_observed_on_the_wire(): void
    {
        $this->seedState([
            'gateway_ip' => self::GATEWAY_IP,
            'external_ip' => '203.0.113.7',
            'mapping_last_sssoe' => 100,
        ]);

        $outcome = $this->exchange(
            self::announcement('203.0.113.7', 500),
            fromIp: self::GATEWAY_IP,
            toMulticastGroup: null
        );

        self::assertSame(['observed-unchanged'], $outcome['outcomes'], implode(' | ', $outcome['warnings']));
        $state = $this->readStateFile();
        self::assertSame(500, $state['mapping_last_sssoe'], 'The live loop wrote the observation through the file.');
        self::assertIsInt($state['mapping_last_announcement_at']);
    }

    public function test_the_same_datagram_from_a_rogue_source_is_dropped_and_touches_nothing(): void
    {
        $this->seedState([
            'gateway_ip' => self::GATEWAY_IP,
            'external_ip' => '203.0.113.7',
            'mapping_last_sssoe' => 100,
        ]);

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->expects(self::never())->method('discoverGateway');

        $outcome = $this->exchange(
            self::announcement('203.0.113.7', 500),
            fromIp: self::ROGUE_IP,
            toMulticastGroup: null,
            natpmp: $natpmp
        );

        self::assertSame(['drop-source-mismatch'], $outcome['outcomes']);
        $state = $this->readStateFile();
        self::assertSame(100, $state['mapping_last_sssoe'], 'A dropped packet must not advance the baseline.');
        self::assertArrayNotHasKey('mapping_last_announcement_at', $state);
    }

    public function test_malformed_bytes_from_the_gateway_are_dropped_on_the_wire(): void
    {
        $this->seedState([
            'gateway_ip' => self::GATEWAY_IP,
            'external_ip' => '203.0.113.7',
        ]);

        $outcome = $this->exchange(
            "\x00\x81junk",
            fromIp: self::GATEWAY_IP,
            toMulticastGroup: null
        );

        self::assertSame(['drop-malformed'], $outcome['outcomes']);
        self::assertArrayNotHasKey('mapping_last_sssoe', $this->readStateFile());
    }

    public function test_ip_change_is_adopted_and_deduped_on_the_wire_through_the_real_loop(): void
    {
        // The re-cascade autoConfigure() fires runs entirely on mocked
        // clients (discoverGateway→null), so the wire assertions stay honest
        // about what we're proving: the ANNOUNCEMENT path — parse, source
        // pin, change detection, commit-before-acting, retransmit dedup.
        $this->seedState([
            'gateway_ip' => self::GATEWAY_IP,
            'external_ip' => '203.0.113.7',
            'mapping_last_sssoe' => 86_400,
            'mapping_renew_at' => time() + 3000,
        ]);

        $natpmp = $this->createMock(NatPmpClient::class);
        $natpmp->method('discoverGateway')->willReturn(null); // cascade dies cheap

        $before = $this->readStateFile();

        $datagram = self::announcement('198.51.100.99', 7);
        $outcome = $this->exchange(
            $datagram,
            fromIp: self::GATEWAY_IP,
            toMulticastGroup: null,
            natpmp: $natpmp,
            sendCopies: 3
        );

        // 3 retransmits of the same change announcement: FIRST must commit +
        // cascade (outcome regardless of cascade success — the mocked client
        // yields reconfigure-failed on every venue), the other two must fall
        // to the cheap path because the observation was already committed.
        self::assertSame(
            ['reconfigure-failed', 'observed-unchanged', 'observed-unchanged'],
            $outcome['outcomes'],
            'Commit-first dedup, measured through the real dispatch path: ' . implode(' | ', $outcome['warnings'])
        );

        $state = $this->readStateFile();
        self::assertSame('198.51.100.99', $state['external_ip'], 'Announced address adopted before the cascade.');
        self::assertSame(7, $state['mapping_last_sssoe']);
        // The mocked cascade persisted no grant, so the pre-announcement
        // deadline survives untouched (renewal is the bounded backstop).
        self::assertSame($before['mapping_renew_at'], $state['mapping_renew_at']);
    }

    /**
     * Headline multicast proof: production's ONLY-correct array-form
     * MCAST_JOIN_GROUP join really delivers 224.0.0.1:5350 traffic to
     * onMessage. Gated on a raw-socket probe first (the SsdpMSearchListener
     * discipline): environments without loopback multicast delivery SKIP
     * before any production object exists.
     */
    public function test_multicast_announcement_reaches_the_joined_listener(): void
    {
        if (!function_exists('socket_import_stream') || !defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets unavailable.');
        }

        $group = NatPmpMaintenanceWorker::ANNOUNCE_MULTICAST_GROUP;
        $ifIndex = $this->findMulticastLoopbackInterface($group);
        if ($ifIndex === null) {
            self::markTestSkipped(
                'This environment does not loop multicast back to a joined socket '
                . '(probed with raw sockets before building anything under test).'
            );
        }

        $sourceIp = $this->interfaceAddress($ifIndex);
        if ($sourceIp === null) {
            self::markTestSkipped("No IPv4 unicast on interface index {$ifIndex}.");
        }

        $this->seedState([
            'gateway_ip' => $sourceIp,
            'external_ip' => '203.0.113.7',
            'mapping_last_sssoe' => 100,
        ]);

        $outcome = $this->exchange(
            self::announcement('203.0.113.7', 600),
            fromIp: $sourceIp,
            toMulticastGroup: $group,
            multicastIfIndex: $ifIndex
        );

        self::assertSame(
            ['observed-unchanged'],
            $outcome['outcomes'],
            'A production-joined 224.0.0.1 socket must receive; outcomes: ['
            . implode(',', $outcome['outcomes']) . '] warnings: [' . implode(' | ', $outcome['warnings']) . ']'
        );
        self::assertTrue($outcome['joined'], 'The array-form MCAST_JOIN_GROUP join must report success.');
        $state = $this->readStateFile();
        self::assertSame(600, $state['mapping_last_sssoe']);
    }

    // ==================================================================
    // Harness
    // ==================================================================

    private static function announcement(string $ip, int $sssoe): string
    {
        return chr(0) . chr(NatPmpMaintenance::ANNOUNCEMENT_OPCODE)
            . pack('n', 0) . pack('N', $sssoe) . inet_pton($ip);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seedState(array $overrides): void
    {
        $state = array_merge([
            'enabled' => true,
            'method' => 'natpmp',
            'external_ip' => '203.0.113.7',
            'port' => 32400,
            'gateway_ip' => self::GATEWAY_IP,
            'mapping_granted_lifetime' => 7200,
            'mapping_renew_at' => time() + 3000,
        ], $overrides);

        file_put_contents(
            $this->tmpDir . '/config/port-forward.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function readStateFile(): array
    {
        $decoded = json_decode((string) file_get_contents($this->tmpDir . '/config/port-forward.json'), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * Drive ONE exchange through the real socket + real Workerman loop.
     *
     * Mirrors Worker::run() ordering (listen → start-of-service → resumeAccept)
     * exactly, installs the Select loop as $globalEvent, sends the datagram(s)
     * from a raw socket bound to $fromIp, then polls the outcome ledger from a
     * delayed callback until N outcomes land or the budget expires.
     *
     * @return array{outcomes: list<string>, warnings: list<string>, joined: bool}
     */
    private function exchange(
        string $datagram,
        string $fromIp,
        ?string $toMulticastGroup,
        ?NatPmpClient $natpmp = null,
        int $sendCopies = 1,
        ?int $multicastIfIndex = null
    ): array {
        [, $port] = $this->bindEphemeral(release: true);

        $select = new Select();
        Worker::$globalEvent = $select;
        Timer::init($select);

        // Collect PHP warnings raised inside production callbacks (Workerman's
        // event loop runs them; a cascade of loud degradation must not
        // silently decide the test outcome — we assert on it instead).
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        $worker = null;
        try {
            $worker = new NatPmpMaintenanceWorker("udp://0.0.0.0:{$port}", new NullLogger());

            $service = new PortForwardService(
                $this->createMock(UpnpIgdClient::class),
                $this->createMock(StunClient::class),
                $natpmp ?? $this->createMock(NatPmpClient::class),
                new NullLogger(),
                32400,
                true,
                $this->tmpDir
            );

            // Worker::run() order: listen() BEFORE onWorkerStart, resumeAccept()
            // from the finally AFTER. The join happens inside serve()->arm()
            // (production index default 0 = kernel routes it); the multicast
            // test re-joins explicitly on the probed interface through the
            // same public seam SsdpAdvertiser exposes.
            $worker->listen(false);

            $this->quietBind(fn () => $worker->serve($service));
            if ($toMulticastGroup !== null && $multicastIfIndex !== null) {
                $this->quietBind(static fn () => $worker->joinMulticastGroup($multicastIfIndex));
            }

            $worker->resumeAccept();

            for ($i = 0; $i < $sendCopies; $i++) {
                $this->send($datagram, $fromIp, $port, $toMulticastGroup, $multicastIfIndex);
                // Space retransmits a hair apart so arrival order matches send
                // order deterministically for the outcome-sequence assertion.
                if ($i + 1 < $sendCopies) {
                    usleep(30_000);
                }
            }

            $this->runLoopUntil(
                $select,
                static fn (): bool => count($worker->announceOutcomes()) >= $sendCopies,
                self::LOOP_BUDGET_SECONDS
            );

            return [
                'outcomes' => $worker->announceOutcomes(),
                'warnings' => $warnings,
                'joined' => $worker->hasJoinedMulticastGroup(),
            ];
        } finally {
            if ($worker !== null) {
                $worker->stopTimers();
                $worker->unlisten();
            }
            restore_error_handler();
            Worker::$globalEvent = $this->savedGlobalEvent;
            $this->setTimerEvent($this->savedTimerEvent);
        }
    }

    /**
     * Run a callable while PHP warnings are being collected by the exchange
     * handler (arm()'s join warning on a host without multicast is EXPECTED
     * noise here — the outcome assertions, not the silence, are the contract).
     */
    private function quietBind(callable $fn): void
    {
        $fn();
    }

    /**
     * @param float $budgetSeconds
     */
    private function runLoopUntil(Select $select, callable $done, float $budgetSeconds): void
    {
        $deadline = hrtime(true) + (int) ($budgetSeconds * 1_000_000_000);

        $poll = static function () use (&$poll, $select, $done, $deadline): void {
            if ($done()) {
                $select->stop();
                return;
            }
            if (hrtime(true) >= $deadline) {
                $select->stop();
                return;
            }
            $select->delay(0.02, $poll);
        };
        $select->delay(0.02, $poll);

        $select->run();
    }

    private function send(string $payload, string $fromIp, int $port, ?string $group, ?int $mcastIfIndex): void
    {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertNotFalse($sock, 'socket_create failed.');
        self::assertTrue(@socket_bind($sock, $fromIp, 0), "socket_bind to {$fromIp} failed.");

        if ($group !== null) {
            self::assertNotFalse(@socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_TTL, 1));
            self::assertNotFalse(@socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_LOOP, 1));
            // 0 means "kernel routes it" — do NOT push an explicit IF of 0.
            if ($mcastIfIndex !== null && $mcastIfIndex > 0) {
                self::assertNotFalse(@socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_IF, $mcastIfIndex));
            }
            self::assertNotFalse(@socket_sendto($sock, $payload, strlen($payload), 0, $group, $port));
        } else {
            self::assertNotFalse(@socket_sendto($sock, $payload, strlen($payload), 0, '127.0.0.1', $port));
        }

        socket_close($sock);
    }

    /**
     * @return array{0: resource|null, 1: int}
     */
    private function bindEphemeral(bool $release = false): array
    {
        $context = stream_context_create(['socket' => ['so_reuseport' => 1]]);
        $socket = stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $context);
        self::assertIsResource($socket, "Could not bind an ephemeral UDP port: {$errstr}");

        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        if ($release) {
            fclose($socket);
            return [null, $port];
        }

        return [$socket, $port];
    }

    /**
     * Lowest interface index on which THIS host loops multicast back to a
     * correctly joined socket, determined with raw sockets only — no
     * production code — before anything under test is built.
     */
    private function findMulticastLoopbackInterface(string $group): ?int
    {
        // REAL interface indices only (1..4). Index 0 means "kernel chooses",
        // under which the arriving source address cannot be predicted from
        // net_get_interfaces() indexing — this test must pin gateway_ip to
        // the exact source the kernel will stamp, so it needs a named NIC.
        foreach ([1, 2, 3, 4] as $ifIndex) {
            [$socket, $port] = $this->bindEphemeral();
            $imported = @socket_import_stream($socket);
            $joined = $imported !== false && @socket_set_option(
                $imported,
                IPPROTO_IP,
                MCAST_JOIN_GROUP,
                ['group' => $group, 'interface' => $ifIndex]
            );
            $received = $joined === true && $this->multicastRoundTrip($socket, $group, $port, $ifIndex);
            fclose($socket);

            if ($received) {
                return $ifIndex;
            }
        }

        return null;
    }

    /**
     * @param resource $socket
     */
    private function multicastRoundTrip($socket, string $group, int $port, int $ifIndex): bool
    {
        $sender = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sender === false) {
            return false;
        }
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_TTL, 1);
        if ($ifIndex > 0) {
            @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_IF, $ifIndex);
        }
        $payload = 'PHLIX-NATPMP-MCAST-PROBE';
        @socket_sendto($sender, $payload, strlen($payload), 0, $group, $port);
        socket_close($sender);

        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false || $ready < 1) {
            return false;
        }
        $got = @stream_socket_recvfrom($socket, 65535);

        return is_string($got) && str_contains($got, 'PROBE');
    }

    /**
     * Predict the source address the kernel stamps on a multicast datagram
     * sent with IP_MULTICAST_IF set to {@see $ifIndex}.
     *
     * net_get_interfaces() keys by NAME ('lo', 'eth0'), while the multicast
     * join/If options take the kernel interface INDEX — glibc's getifaddrs()
     * enumerates ascending by index, so position (index-1) in the list is the
     * named NIC. If that ordering assumption ever broke on some host, the
     * predicted pin would contradict the real arrival source and the test
     * would fail on drop-source-mismatch — it cannot pass by accident.
     */
    private function interfaceAddress(int $ifIndex): ?string
    {
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return null;
        }
        $ordered = array_values($interfaces);
        if ($ifIndex < 1 || !array_key_exists($ifIndex - 1, $ordered)) {
            return null;
        }
        $unicast = $ordered[$ifIndex - 1]['unicast'] ?? null;
        if (!is_array($unicast)) {
            return null;
        }
        foreach ($unicast as $addr) {
            if (
                is_array($addr) && isset($addr['address']) && is_string($addr['address'])
                && filter_var($addr['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ) {
                return $addr['address'];
            }
        }

        return null;
    }

    private function timerEvent(): ?EventInterface
    {
        $property = new ReflectionProperty(Timer::class, 'event');
        $property->setAccessible(true);
        $value = $property->getValue();
        return $value instanceof EventInterface ? $value : null;
    }

    private function setTimerEvent(?EventInterface $event): void
    {
        $property = new ReflectionProperty(Timer::class, 'event');
        $property->setAccessible(true);
        $property->setValue(null, $event);
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
}
