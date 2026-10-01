<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\Connection\UdpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Resident NAT-PMP maintenance: §3.3 renewals + §3.2.1 announcement listener.
 *
 * ## The process that owns the deadline
 *
 * RFC 6886 §3.3 tells the client to begin renewing a mapping halfway to
 * expiry; §3.2.1 tells gateways to broadcast a gratuitous address response to
 * 224.0.0.1:5350 whenever their WAN address changes (boot, re-assign). Both
 * are the obligations of a LONG-LIVED process — and until this worker existed
 * they were the one documented gap in the port-forward stack: the persisted
 * `mapping_renew_at` deadline had no actor, so a gateway reboot silently
 * orphaned every mapping until an operator hit "enable" again.
 *
 * This worker is that actor. It is spawned as exactly ONE process
 * (`count = 1`, start.php §4f-bis) and it is the ONLY call site of
 * {@see PortForwardService::renewOnce()} inside the daemon (the other is the
 * operator CLI `php scripts/port-forward.php renew`), so double-renewal is
 * structurally impossible: Workerman only forks what the master declared, the
 * master declares exactly one of these, and the timers are armed in THIS
 * class's own arm() which is guarded to fire at most once per process.
 *
 * ## Two arms, one clock
 *
 *  - **Renewal tick** ({@see self::TICK_SECONDS}): every 60s read the
 *    persisted state and ask {@see NatPmpMaintenance::plan()} whether the
 *    halfway deadline passed. On `renew`, call {@see PortForwardService::renewOnce()}
 *    (re-sends the §3.3 packet with Suggested External Port = the previously
 *    mapped port). Failures park a `mapping_retry_at` backoff the plan honors
 *    — a down gateway costs one bounded exchange per backoff slot, never a
 *    hot loop. A boot catch-up one-shot fires the same tick shortly after
 *    start because announcements missed while offline do not replay and the
 *    deadline may already have passed.
 *  - **Announcement listener**: bound `udp://0.0.0.0:5350`, joined to the
 *    224.0.0.1 all-nat-pmp-relays group (§3.2.1). Every datagram is
 *    SOURCE-PINNED: accepted only from the `gateway_ip` recorded at grant
 *    time — a LAN broadcast is unauthenticated and never gets to act on
 *    anything else (same discipline as NatPmpClient's L4 reply pinning). An
 *    announcement contradicting the cached external IP (re-assign) or carrying
 *    a LOWER Seconds-Since-Start-of-Epoch than last seen (reboot) means the
 *    mapping is gone: the observation is committed FIRST (gateways retransmit
 *    §3.2.1 announcements repeatedly, so the commit makes the whole burst
 *    idempotent), then a FULL {@see PortForwardService::autoConfigure()}
 *    re-cascade runs — not just the NAT-PMP leg, because after a reboot the
 *    honest answer to "what does this router speak now" is unknown.
 *
 * ## Blocking IO
 *
 * renewOnce()/autoConfigure() are bounded synchronous UDP exchanges (see the
 * per-leg timeouts in NatPmpClient/UpnpIgdClient/StunClient). That stall runs
 * HERE — in a dedicated count=1 process whose only other work is this
 * subsystem — never in the HTTP/WebSocket/heartbeat/relay workers. Registered
 * as Exception 8 in docs/dev/BLOCKING_IO_EXCEPTIONS.md with measured bounds.
 *
 * ## Degradation ladder (each rung loud, none fatal)
 *
 *  1. bind :5350 fails (another NAT-PMP listener owns it — SO_REUSEPORT makes
 *     this rare) → renewal-only mode; renewals keep working.
 *  2. multicast join fails / ext-sockets absent → unicast-only announcements;
 *     most gateways multicast, so effectively renewal-only, logged loudly.
 *  3. renewal exchange fails → backoff ledger in state, retried per
 *     NatPmpMaintenance::nextRetryDelay().
 *  4. no state file / port-forwarding disabled → both arms idle. A deleted
 *     mapping is never resurrected.
 *
 * @package Phlix\Network
 * @since 1.8.0
 */
class NatPmpMaintenanceWorker extends Worker
{
    /**
     * RFC 6886 §2.5: the UDP port gateways send gratuitous announcements to.
     */
    public const ANNOUNCE_PORT = 5350;

    /**
     * RFC 6886 §3.2.1: the all-NAT-PMP-relays multicast group.
     */
    public const ANNOUNCE_MULTICAST_GROUP = '224.0.0.1';

    /**
     * Production bind address — 0.0.0.0 so the kernel delivers both unicast
     * from the gateway and multicast from 224.0.0.1 to the joined socket.
     */
    public const LISTEN_ADDRESS = 'udp://0.0.0.0:5350';

    /**
     * Renewal-tick cadence. Independent of §3.3 timing: the DEADLINE is the
     * persisted `mapping_renew_at`; 60s only bounds how late past the
     * halfway point a renewal may fire (≈1.4% of the default 7200s lease).
     */
    public const TICK_SECONDS = 60;

    /**
     * One-shot catch-up tick after start: deadlines that passed while the
     * server was down are honoured immediately instead of up to TICK_SECONDS
     * late, and gateways only announce ONCE per change — a reboot during our
     * downtime is otherwise invisible until the missed-deadline tick.
     */
    public const BOOT_CATCHUP_SECONDS = 15;

    /** How many recent announcement outcomes to retain for introspection. */
    private const OUTCOME_LEDGER_LIMIT = 64;

    // tick() return["action"] / outcome tokens -------------------------------

    /** serve() never delivered a PortForwardService — tick is inert. */
    public const TICK_NO_SERVICE = 'no-service';

    /** A tick ran renewOnce() and it succeeded. */
    public const TICK_RENEWED = 'renewed';

    /** A tick ran renewOnce() and it failed (backoff now armed). */
    public const TICK_RENEWAL_FAILED = 'renewal-failed';

    // handleAnnouncement() outcome tokens ------------------------------------

    public const ANNOUNCE_DROP_NO_SERVICE = 'drop-no-service';
    public const ANNOUNCE_DROP_NO_STATE = 'drop-no-state';
    public const ANNOUNCE_DROP_DISABLED = 'drop-disabled';
    public const ANNOUNCE_DROP_NOT_NATPMP = 'drop-not-natpmp';
    public const ANNOUNCE_DROP_NO_GATEWAY_PIN = 'drop-no-gateway-pin';
    public const ANNOUNCE_DROP_SOURCE_MISMATCH = 'drop-source-mismatch';
    public const ANNOUNCE_DROP_MALFORMED = 'drop-malformed';
    public const ANNOUNCE_OBSERVED_UNCHANGED = 'observed-unchanged';
    public const ANNOUNCE_RECONFIGURED = 'reconfigured';
    public const ANNOUNCE_RECONFIGURE_FAILED = 'reconfigure-failed';

    private ?PortForwardService $service = null;

    /**
     * The bind address this instance was constructed with (for log lines —
     * kept locally rather than reaching into Workerman internals).
     */
    private string $boundAddress;

    private LoggerInterface $logger;

    /** arm() fired exactly once per process (the double-arming guard). */
    private bool $armed = false;

    /** The :5350 bind failed; renewal-only mode. */
    private bool $listenerDegraded = false;

    /** joinMulticastGroup() result, see SsdpAdvertiser for the trap. */
    private bool $multicastJoined = false;

    private int $bootCatchupTimerId = 0;

    private int $tickTimerId = 0;

    /**
     * Ring of recent handleAnnouncement() outcomes, newest last, capped at
     * OUTCOME_LEDGER_LIMIT. Purely observational (tests + status debugging);
     * no decision reads it, so the cap can never change behavior.
     *
     * @var list<string>
     */
    private array $announceOutcomes = [];

    /**
     * @param string|null $listenAddress Bind override; tests use an ephemeral
     *        loopback port to drive REAL datagrams through the REAL socket
     *        without squatting :5350 on the host (same seam as SsdpAdvertiser).
     * @param LoggerInterface|null $logger Optional; start.php passes the
     *        `logger.network` channel resolved inside the fork.
     */
    public function __construct(?string $listenAddress = null, ?LoggerInterface $logger = null)
    {
        // SO_REUSEPORT for the same two reasons SsdpAdvertiser documents for
        // :1900: coexistence with any other NAT-PMP listener on the box, and —
        // the load-bearing one — moving the bind into the child where
        // self::listen() contains it, instead of the master where a busy
        // :5350 would take the whole server down pre-fork.
        parent::__construct($listenAddress ?? self::LISTEN_ADDRESS, ['socket' => ['so_reuseport' => 1]]);
        $this->reusePort = true;
        $this->boundAddress = $listenAddress ?? self::LISTEN_ADDRESS;

        $this->logger = $logger ?? new NullLogger();

        // Set here, not in onWorkerStart: Worker::run() wires the read
        // callback from listen() regardless of what onWorkerStart does, so
        // datagrams CAN arrive before serve(). The service check inside
        // handleAnnouncement() is the real gate (S297 lesson).
        $this->onMessage = function (UdpConnection $connection, mixed $data): void {
            $sourceIp = $connection->getRemoteIp();
            $outcome = $this->handleAnnouncement($sourceIp, is_string($data) ? $data : '');
            $this->recordOutcome($outcome);
        };

        $this->onWorkerStop = function (NatPmpMaintenanceWorker $worker): void {
            $this->stopTimers();
        };
    }

    /**
     * Take ownership of the persisted mapping state and start both arms.
     *
     * Called from this worker's onWorkerStart INSIDE the fork, after the
     * container was built there — never from the master (no inherited DB
     * state). Idempotent by design: the armed flag is raised before anything
     * else, so a doubled invocation cannot re-join the group or stack a second
     * pair of timers.
     *
     * @since 1.8.0
     */
    public function serve(PortForwardService $service, ?LoggerInterface $logger = null): void
    {
        if ($this->armed) {
            return;
        }

        $this->service = $service;
        if ($logger !== null) {
            $this->logger = $logger;
        }
        $this->arm();
    }

    /**
     * Arm the multicast join and both timers. Kept separate from serve() so
     * the guard logic is directly testable.
     *
     * @since 1.8.0
     */
    public function arm(): void
    {
        if ($this->armed) {
            return;
        }
        $this->armed = true;

        // Receiving §3.2.1 announcements needs explicit group membership;
        // binding the port is not enough.
        $this->joinMulticastGroup();

        // Two-arm doctrine (mirrors the core-update check): a one-shot
        // catch-up for deadlines/announcements missed while offline, plus the
        // steady periodic tick. First tick from the persistent timer lands at
        // +TICK_SECONDS, after the catch-up at +BOOT_CATCHUP_SECONDS.
        $this->bootCatchupTimerId = (int) Timer::add(
            self::BOOT_CATCHUP_SECONDS,
            function (): void {
                $this->tick();
            },
            [],
            false
        );
        $this->tickTimerId = (int) Timer::add(
            self::TICK_SECONDS,
            function (): void {
                $this->tick();
            }
        );
    }

    /**
     * One maintenance tick: plan from persisted state, renew if due.
     *
     * Public and clock-injectable so the state machine is testable without
     * waiting on (or forking) anything.
     *
     * @return array{action: string, renewed?: bool, reason?: string}
     *
     * @since 1.8.0
     */
    public function tick(?int $now = null): array
    {
        $service = $this->service;
        if ($service === null) {
            return ['action' => self::TICK_NO_SERVICE];
        }

        $clock = $now ?? time();
        $state = $service->getState();
        $action = NatPmpMaintenance::plan($state, $clock);

        if ($action !== NatPmpMaintenance::ACTION_RENEW) {
            if ($action === NatPmpMaintenance::ACTION_WAIT_BACKOFF) {
                $this->logger->debug('NAT-PMP renewal waiting out failure backoff');
            }
            return ['action' => $action];
        }

        $result = $service->renewOnce();
        if ($result['renewed']) {
            return [
                'action' => self::TICK_RENEWED,
                'renewed' => true,
                'reason' => $result['reason'],
            ];
        }

        // renewOnce() already logged the warning with reason/backoff.
        return [
            'action' => self::TICK_RENEWAL_FAILED,
            'renewed' => false,
            'reason' => $result['reason'],
        ];
    }

    /**
     * Process one datagram received on :5350. Pure with respect to the socket
     * (the caller hands us source + bytes); public so both the onMessage
     * closure and tests drive the exact production path.
     *
     * @param string $sourceIp Remote IP as the kernel saw it.
     * @param string $datagram Raw UDP payload.
     *
     * @return self::ANNOUNCE_DROP_*|self::ANNOUNCE_OBSERVED_UNCHANGED|self::ANNOUNCE_RECONFIGURED|self::ANNOUNCE_RECONFIGURE_FAILED
     *     outcome token.
     *
     * @since 1.8.0
     */
    public function handleAnnouncement(string $sourceIp, string $datagram, ?int $now = null): string
    {
        $service = $this->service;
        if ($service === null) {
            return self::ANNOUNCE_DROP_NO_SERVICE;
        }

        $state = $service->getState();
        if ($state === null) {
            return self::ANNOUNCE_DROP_NO_STATE;
        }

        if (($state['enabled'] ?? false) !== true) {
            return self::ANNOUNCE_DROP_DISABLED;
        }

        if (($state['method'] ?? null) !== 'natpmp') {
            return self::ANNOUNCE_DROP_NOT_NATPMP;
        }

        // Source pin: announcements are honored ONLY from the gateway the
        // mapping was negotiated with. No pin yet (pre-upgrade state file) →
        // nothing can be authenticated → drop; the next renewOnce()/
        // autoConfigure() writes the pin. Never fall back to "trust whoever
        // announced" — that is precisely the unauthenticated-LAN vector.
        $gatewayIp = $state['gateway_ip'] ?? null;
        if (!is_string($gatewayIp) || $gatewayIp === '') {
            return self::ANNOUNCE_DROP_NO_GATEWAY_PIN;
        }

        if ($sourceIp !== $gatewayIp) {
            $this->logger->warning('Ignoring NAT-PMP announcement from non-gateway source', [
                'source' => $sourceIp,
                'pinned_gateway' => $gatewayIp,
            ]);
            return self::ANNOUNCE_DROP_SOURCE_MISMATCH;
        }

        $announcement = NatPmpMaintenance::parseAnnouncement($datagram);
        if ($announcement === null) {
            $this->logger->debug('Dropping malformed NAT-PMP announcement', [
                'length' => strlen($datagram),
            ]);
            return self::ANNOUNCE_DROP_MALFORMED;
        }

        $clock = $now ?? time();
        $cachedIp = is_string($state['external_ip'] ?? null) ? (string) $state['external_ip'] : null;
        $lastSssoe = is_int($state['mapping_last_sssoe'] ?? null) ? (int) $state['mapping_last_sssoe'] : null;

        $changed = NatPmpMaintenance::detectChange(
            $cachedIp,
            $lastSssoe,
            $announcement['external_ip'],
            $announcement['seconds_since_epoch']
        );

        // Commit the observation BEFORE acting. §3.2.1 requires gateways to
        // retransmit the announcement (RFC: 10+ times in the first minute);
        // once the new (ip, sssoe) pair is recorded, every retransmit in the
        // burst compares equal and takes the cheap path — the storm cannot
        // fan out into a storm of reconfigures even when autoConfigure() fails.
        $patch = [
            'mapping_last_sssoe' => $announcement['seconds_since_epoch'],
            'mapping_last_announcement_at' => $clock,
        ];
        if ($changed) {
            $patch['external_ip'] = $announcement['external_ip'];
        }
        $service->mergeState($patch);

        if (!$changed) {
            return self::ANNOUNCE_OBSERVED_UNCHANGED;
        }

        $this->logger->warning('Gateway address change detected from NAT-PMP announcement, reconfiguring', [
            'source' => $sourceIp,
            'announced_external_ip' => $announcement['external_ip'],
            'previous_external_ip' => $cachedIp,
            'sssoe' => $announcement['seconds_since_epoch'],
            'previous_sssoe' => $lastSssoe,
        ]);

        $result = $service->autoConfigure();
        if ($result['success'] === true) {
            $this->logger->info('NAT-PMP reconfiguration after gateway change succeeded', [
                'endpoint' => $result['public_endpoint'],
                'method' => $result['method'],
            ]);
            return self::ANNOUNCE_RECONFIGURED;
        }

        $this->logger->warning('NAT-PMP reconfiguration after gateway change failed; renewal backoff continues', [
            'method' => $result['method'],
        ]);
        return self::ANNOUNCE_RECONFIGURE_FAILED;
    }

    /**
     * Bind the announcement listener, degrading to renewal-only rather than
     * dying — a :5350 conflict must never cost the worker, let alone the
     * server. (SsdpAdvertiser::listen() is the precedent this mirrors.)
     *
     * @since 1.8.0
     */
    public function listen(bool $autoAccept = true): void
    {
        try {
            parent::listen($autoAccept);
        } catch (\Throwable $e) {
            $this->listenerDegraded = true;
            $this->logger->warning(
                'NAT-PMP maintenance: announcement listener bind failed, renewal-only mode',
                ['error' => $e->getMessage(), 'address' => $this->getListenAddress()]
            );
            trigger_error(
                'NAT-PMP maintenance could not bind ' . $this->getListenAddress()
                . ' (' . $e->getMessage() . '); §3.3 renewals continue, §3.2.1 announcements will not be heard.',
                E_USER_WARNING
            );
        }
    }

    /**
     * Join 224.0.0.1 so §3.2.1 announcements are delivered to this socket.
     *
     * The ONLY correct PHP spelling (proven three-arm against a real socket in
     * SsdpMSearchListenerTest for the SSDP twin): MCAST_JOIN_GROUP with an
     * ARRAY optval. PHP does not define IP_ADD_MEMBERSHIP; the C-style raw
     * option 12 + packed inet_pton strings RETURNS TRUE AND JOINS NOTHING.
     *
     * @internal Visible for tests; production calls it from arm() only.
     *
     * @since 1.8.0
     */
    public function joinMulticastGroup(int $interfaceIndex = 0): bool
    {
        // Guard every precondition before touching the socket API; nothing
        // here may depend on an exception for control flow.
        if (!function_exists('socket_import_stream') || !defined('MCAST_JOIN_GROUP')) {
            $this->announceJoinFailure('ext-sockets unavailable', loud: true);
            return false;
        }

        /** @var mixed $mainSocket */
        $mainSocket = $this->getMainSocket();
        if (!is_resource($mainSocket)) {
            // NO listener socket. The only production route here is a failed
            // bind, and listen() already shouted about THAT once — repeating
            // the alarm for the same degradation is noise. Log, don't ring.
            $this->announceJoinFailure('listener socket not bound (renewal-only mode)', loud: false);
            return false;
        }

        // socket_import_stream() returns Socket|false — never null.
        $socket = @socket_import_stream($mainSocket);
        if ($socket === false) {
            $this->announceJoinFailure('socket_import_stream failed', loud: true);
            return false;
        }

        $joined = @socket_set_option(
            $socket,
            IPPROTO_IP,
            MCAST_JOIN_GROUP,
            ['group' => self::ANNOUNCE_MULTICAST_GROUP, 'interface' => $interfaceIndex]
        );

        $this->multicastJoined = $joined === true;

        if (!$this->multicastJoined) {
            $this->announceJoinFailure('MCAST_JOIN_GROUP rejected', loud: true);
        }

        return $this->multicastJoined;
    }

    /**
     * Did arm()/serve() run in this process?
     *
     * @since 1.8.0
     */
    public function isArmed(): bool
    {
        return $this->armed;
    }

    /**
     * Is the announcement listener in renewal-only mode (bind failed)?
     *
     * @since 1.8.0
     */
    public function isListenerDegraded(): bool
    {
        return $this->listenerDegraded;
    }

    /**
     * Did the 224.0.0.1 group join succeed?
     *
     * @since 1.8.0
     */
    public function hasJoinedMulticastGroup(): bool
    {
        return $this->multicastJoined;
    }

    /**
     * Recent announcement outcomes, newest last (capped ledger).
     *
     * @return list<string>
     *
     * @since 1.8.0
     */
    public function announceOutcomes(): array
    {
        return $this->announceOutcomes;
    }

    /**
     * Drop both timers. Workerman also clears all timers on process stop;
     * this exists so onWorkerStop (and tests) make the intent explicit.
     *
     * @since 1.8.0
     */
    public function stopTimers(): void
    {
        if ($this->bootCatchupTimerId !== 0) {
            Timer::del($this->bootCatchupTimerId);
            $this->bootCatchupTimerId = 0;
        }
        if ($this->tickTimerId !== 0) {
            Timer::del($this->tickTimerId);
            $this->tickTimerId = 0;
        }
    }

    private function recordOutcome(string $outcome): void
    {
        $this->announceOutcomes[] = $outcome;
        if (count($this->announceOutcomes) > self::OUTCOME_LEDGER_LIMIT) {
            array_shift($this->announceOutcomes);
        }
    }

    /**
     * Note that announcements are deaf (multicast join failed). A silent
     * "we never heard the gateway" is indistinguishable from "the gateway
     * never changed" — same reasoning SsdpAdvertiser documents. LOUD (a PHP
     * warning) for every state the operator could still fix; quiet (log
     * line) for the no-socket path, whose root cause listen() has already
     * shouted about exactly once.
     */
    private function announceJoinFailure(string $why, bool $loud): void
    {
        $this->logger->warning('NAT-PMP maintenance: multicast join failed, announcements limited to unicast', [
            'reason' => $why,
            'group' => self::ANNOUNCE_MULTICAST_GROUP,
        ]);

        if (!$loud) {
            return;
        }

        trigger_error(
            'NAT-PMP maintenance could not join multicast group ' . self::ANNOUNCE_MULTICAST_GROUP
            . ' (' . $why . '); only unicast announcements from the gateway will be heard.',
            E_USER_WARNING
        );
    }

    /**
     * The bind address log lines name (constructor truth, not Workerman internals).
     *
     * @since 1.8.0
     */
    public function getListenAddress(): string
    {
        return $this->boundAddress;
    }
}
