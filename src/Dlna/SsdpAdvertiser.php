<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Dlna;

use Exception;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware;
use Workerman\Connection\UdpConnection;
use Workerman\Timer;
use Workerman\Worker;

/**
 * SsdpAdvertiser announces this server over SSDP and answers device searches.
 *
 * Extends Worker to integrate with Workerman's process lifecycle.
 *
 * ## Two halves of discovery, and why one of them was missing
 *
 * SSDP has a passive half and an active half. Every 30 seconds this worker
 * multicasts an alive `NOTIFY` (and a `byebye` on shutdown) — that is the
 * passive half, and it is all this class did until S51. It only reaches control
 * points that happen to be listening at the moment we announce.
 *
 * The active half is `M-SEARCH`: a control point that has just been switched on
 * multicasts "who is out there?" and expects a **unicast** reply. Most TVs and
 * most control-point apps discover this way, because waiting up to 30 s for the
 * next NOTIFY is not an acceptable UI. Without a responder, Phlix was invisible
 * to all of them.
 *
 * Answering requires an actual bound, group-joined socket — which this worker
 * did not have. Its constructor called `parent::__construct()` with no socket
 * name, so Workerman never created a listening socket and no `onMessage` could
 * ever have fired. It now listens on {@see self::LISTEN_ADDRESS} and joins the
 * SSDP multicast group in {@see self::joinMulticastGroup()}.
 *
 * @since 0.12.0
 * @see UPnP Device Architecture 2.0 specification
 */
class SsdpAdvertiser extends Worker
{
    /** SSDP multicast address */
    public const SSDP_MULTICAST_ADDRESS = '239.255.255.250';

    /** SSDP multicast port */
    public const SSDP_PORT = 1900;

    /**
     * Where the inbound `M-SEARCH` listener binds.
     *
     * `0.0.0.0` rather than the multicast address itself, so the socket receives
     * BOTH the multicast searches (via the group join) and the unicast searches
     * UPnP 1.1 added — binding to the group address would silently drop the
     * latter.
     */
    public const LISTEN_ADDRESS = 'udp://0.0.0.0:1900';

    /**
     * Ceiling on search replies awaiting their `MX` delay at any one moment.
     *
     * This is a resident process and `M-SEARCH` is unauthenticated UDP: without
     * a cap, a peer that floods searches with `MX: 5` accumulates one timer and
     * one closure per packet for five seconds, which is a memory-exhaustion
     * primitive with no login required. Over the cap, searches are dropped —
     * SSDP is lossy by design and a control point re-searches.
     */
    public const MAX_PENDING_REPLIES = 64;

    /** Broadcast interval in seconds */
    public const BROADCAST_INTERVAL_SECONDS = 30;

    /** Unique Service Name for this server */
    public const USN = 'uuid:PHLIXSERVER';

    /** Notification subtype: alive */
    public const NTS_ALIVE = 'ssdp:alive';

    /** Notification subtype: byebye */
    public const NTS_BYEBYE = 'ssdp:byebye';

    /** @var string|null IP address to advertise in LOCATION header */
    private ?string $ipAddress;

    /** @var int Port to advertise in LOCATION header */
    private int $port;

    /** @var resource|null UDP socket for SSDP broadcasts */
    private $socket = null;

    /** @var int Timer ID for Workerman timer */
    private int $timerId = 0;

    /**
     * Whether inbound `M-SEARCH` datagrams should be answered.
     *
     * Starts FALSE and is only raised by `onWorkerStart` once the
     * {@see self::isEnabled()} gate has passed. This flag is load-bearing, not
     * belt-and-braces: `Worker::run()` calls `listen()` BEFORE `onWorkerStart`
     * and calls `resumeAccept()` from a `finally` AFTER it, so the socket is
     * bound and reading no matter how `onWorkerStart` returns. A disabled
     * advertiser therefore still receives searches — it just must not answer
     * them, or `dlna.enabled = false` would stop the announcements while leaving
     * the server discoverable by every control point that asks directly.
     */
    private bool $respondToSearches = false;

    /**
     * Timer ids for search replies waiting out their `MX` delay.
     *
     * Bounded by {@see self::MAX_PENDING_REPLIES} and drained by each reply, so
     * it cannot grow without limit in this long-lived process. Instance state,
     * never static.
     *
     * @var array<int, true>
     */
    private array $pendingReplyTimers = [];

    /**
     * Whether {@see self::joinMulticastGroup()} actually joined.
     *
     * Recorded rather than merely logged because a failed multicast join is
     * indistinguishable from "nobody searched" — the socket stays open, the
     * process stays healthy, and discovery just never happens. Having it as
     * state is what lets a test assert the join SUCCEEDED instead of asserting
     * that the call did not throw.
     */
    private bool $multicastJoined = false;

    /**
     * Decides which peers get an answer. Null until `onWorkerStart` builds one,
     * because it reads settings and must not do so in the pre-fork master.
     */
    private ?DlnaAllowlistMiddleware $allowlist;

    /**
     * Path to `config/database.php`, used to bootstrap the settings overlay
     * inside this worker's own process. Null in tests and in any caller that
     * has already bootstrapped {@see EffectiveConfig}.
     */
    private ?string $dbConfigPath;

    /**
     * Create a new SsdpAdvertiser.
     *
     * @param string|null $ipAddress IP address for LOCATION header (auto-detected if null)
     * @param int $port Port for LOCATION header
     * @param string|null $dbConfigPath Path to `config/database.php`, so this
     *        worker can read the effective `dlna.enabled` in its own fork
     * @param string|null $listenAddress Where the `M-SEARCH` listener binds.
     *        Defaults to {@see self::LISTEN_ADDRESS}; overridden only by tests,
     *        which bind an ephemeral loopback port so a real datagram can be put
     *        through the real socket without touching :1900 on the host.
     * @param DlnaAllowlistMiddleware|null $allowlist Injected by tests; production
     *        builds one inside the fork (see {@see self::$allowlist}).
     *
     * @since 0.12.0
     */
    public function __construct(
        ?string $ipAddress = null,
        int $port = 8080,
        ?string $dbConfigPath = null,
        ?string $listenAddress = null,
        ?DlnaAllowlistMiddleware $allowlist = null
    ) {
        // SO_REUSEPORT, for two distinct reasons.
        //
        // (1) Coexistence. `Discovery\Ssdp\SsdpSocket::createSocket()` binds
        //     0.0.0.0:1900 too, for OUTBOUND renderer discovery, and
        //     `SsdpSocket::search()` does not close it on the success path — so
        //     the overlap is a live window, not a blink.
        // (2) Failure containment. With `reusePort = false`, `Worker::initWorkers()`
        //     binds in the MASTER, pre-fork, where a RuntimeException from a
        //     busy :1900 (minidlna, gssdp, another Phlix) escapes `runAll()` and
        //     takes the whole server down. With it true the bind moves into the
        //     child's `run()`, where `self::listen()` contains it.
        //
        // Measured, not assumed: PHP already sets SO_REUSEADDR on every
        // `stream_socket_server()` socket, so the two binds coexist even without
        // this. It is kept because it is what makes (2) true.
        parent::__construct($listenAddress ?? self::LISTEN_ADDRESS, ['socket' => ['so_reuseport' => 1]]);
        $this->reusePort = true;

        $this->ipAddress = $ipAddress;
        $this->port = $port;
        $this->dbConfigPath = $dbConfigPath;
        $this->allowlist = $allowlist;

        // `parent::__construct()` installs an empty onMessage; replace it. Set
        // here rather than in onWorkerStart because `Worker::run()` wires the
        // read callback from a `finally` that runs whatever onWorkerStart did.
        $this->onMessage = function (UdpConnection $connection, mixed $data): void {
            $this->handleSearch($connection, is_string($data) ? $data : '');
        };

        $this->onWorkerStart = function (SsdpAdvertiser $worker): void {
            // Load the persisted `server_settings` overrides into THIS fork.
            // The master deliberately does not do this (its DB connection would
            // be inherited by every child), so the effective value is resolved
            // here — which also means it is re-read on every graceful reload.
            // Never throws: an unreachable settings store yields the file
            // defaults. {@see \Phlix\Config\EffectiveConfig}
            //
            // Conditional on being TOLD where the database config lives. The
            // rule is "if I was given a path, loading the overlay is my job;
            // otherwise the surrounding process has already bootstrapped it and
            // re-running it here would CLOBBER that state" — which is exactly
            // what an unconditional call did to this class's own tests, and
            // would equally clobber any caller that had already bootstrapped.
            // start.php always passes the path, so the production fork always
            // loads its own overrides.
            if ($this->dbConfigPath !== null) {
                EffectiveConfig::bootstrap(null, $this->dbConfigPath);
            }

            // Honour an admin `dlna.enabled = false` override. Idle rather than
            // exit, so a graceful reload can pick the value up again without
            // the master having to re-fork a worker it no longer knows about.
            if (!self::isEnabled()) {
                return;
            }

            $this->socket = $this->createUdpSocket();
            if ($this->socket === null) {
                return;
            }

            // Answer searches from here on. Deliberately AFTER the isEnabled()
            // gate above: see self::$respondToSearches for why the gate cannot
            // rely on the early return alone.
            $this->allowlist ??= new DlnaAllowlistMiddleware();
            $this->respondToSearches = true;

            // Receiving multicast needs an explicit group membership; binding to
            // the port is not enough. Best-effort — a failure costs the active
            // half of discovery (multicast M-SEARCH), not the passive half.
            $this->joinMulticastGroup();

            // Send initial alive message
            $this->sendAlive();

            // Schedule periodic broadcasts
            $this->timerId = \Workerman\Timer::add(
                self::BROADCAST_INTERVAL_SECONDS,
                function (): void {
                    $this->sendAlive();
                }
            );
        };

        $this->onWorkerStop = function (SsdpAdvertiser $worker): void {
            // Stop answering before anything else: the socket outlives this
            // callback and a reply naming a LOCATION we are about to stop
            // serving is worse than no reply.
            $this->respondToSearches = false;

            // Send byebye message
            $this->sendByebye();

            // Cancel timer
            if ($this->timerId > 0) {
                \Workerman\Timer::del($this->timerId);
                $this->timerId = 0;
            }

            // Drop any reply still waiting out its MX delay.
            foreach (array_keys($this->pendingReplyTimers) as $pendingTimerId) {
                Timer::del($pendingTimerId);
            }
            $this->pendingReplyTimers = [];

            // Close socket. Detach first, then fclose the local handle: closing
            // through the property leaves it holding a closed resource, which
            // contradicts its declared `resource|null` type.
            if ($this->socket !== null) {
                $socket = $this->socket;
                $this->socket = null;
                fclose($socket);
            }
        };
    }

    /**
     * Bind the search listener, degrading to send-only rather than dying.
     *
     * `Worker::listen()` throws `RuntimeException` when the bind fails, and it
     * is called from `Worker::run()` in the child with nothing between it and
     * the process boundary. Port 1900 is a well-known port that minidlna, gssdp,
     * Windows SSDPSRV and a second Phlix all want, so "in use" is an ordinary
     * operating condition, not an exceptional one — and losing the ability to
     * ANSWER searches is no reason to lose the 30-second announcements as well.
     *
     * On failure `mainSocket` stays null, which makes `resumeAccept()` a no-op
     * and {@see self::joinMulticastGroup()} return early; nothing downstream has
     * to know.
     *
     * @param bool $autoAccept Passed straight through to Workerman.
     *
     * @since 1.7.0
     */
    public function listen(bool $autoAccept = true): void
    {
        try {
            parent::listen($autoAccept);
        } catch (\Throwable $e) {
            trigger_error(
                'DLNA SSDP search listener could not bind ' . self::LISTEN_ADDRESS
                . ' (' . $e->getMessage() . '); announcements continue, M-SEARCH replies disabled.',
                E_USER_WARNING
            );
        }
    }

    /**
     * Join the SSDP multicast group so multicast `M-SEARCH` is delivered here.
     *
     * ## The only correct spelling, and the wrong one that looks correct
     *
     * PHP exposes this as `MCAST_JOIN_GROUP` with an **array** optval. The
     * BSD-style `IP_ADD_MEMBERSHIP` + packed `struct ip_mreq` spelling that
     * every C example uses is not available: PHP does not define
     * `IP_ADD_MEMBERSHIP` at all (verified — `defined()` is false on 8.3), so
     * code that falls back to the raw option number `12` and passes
     * `inet_pton($group) . inet_pton($iface)` hands a binary string where an int
     * is expected. That call **returns TRUE** and joins nothing.
     *
     * That is not a hypothetical: it is what `Discovery\Mdns\MdnsSocket` does at
     * its own join site, and a three-arm experiment on a real socket confirmed
     * it — no-join received nothing, the raw-12 spelling returned TRUE and
     * received nothing, and only the array form below received the datagram.
     * A silently failed join is indistinguishable from "nobody searched", which
     * is exactly why this is spelled out here rather than copied from there.
     *
     * `interface => 0` means "let the kernel pick, by route" — correct for the
     * single-homed common case and for a Docker bridge, and the same default the
     * outbound half already relies on.
     *
     * @param int $interfaceIndex Interface index to join on; 0 = let the kernel
     *        route. Production never passes anything else. It is a parameter
     *        only so a test can run THIS method — rather than a copy of it — on
     *        a host whose LAN interface does not loop multicast back to itself,
     *        which is the usual state of a VM and of CI. The interface choice is
     *        not what is under test; the option spelling is.
     *
     * @since 1.7.0
     */
    private function joinMulticastGroup(int $interfaceIndex = 0): void
    {
        // Guard every precondition BEFORE touching the socket API. Nothing here
        // may be allowed to depend on an exception for control flow.
        if (!function_exists('socket_import_stream') || !defined('MCAST_JOIN_GROUP')) {
            return;
        }

        /** @var mixed $mainSocket */
        $mainSocket = $this->getMainSocket();
        if (!is_resource($mainSocket)) {
            return;
        }

        // `socket_import_stream()` returns `Socket|false` — never null.
        $socket = @socket_import_stream($mainSocket);
        if ($socket === false) {
            return;
        }

        $joined = @socket_set_option(
            $socket,
            IPPROTO_IP,
            MCAST_JOIN_GROUP,
            ['group' => self::SSDP_MULTICAST_ADDRESS, 'interface' => $interfaceIndex]
        );

        $this->multicastJoined = $joined === true;

        if (!$this->multicastJoined) {
            // Deliberately LOUD. The whole hazard of this call is that its
            // wrong spellings fail silently, so the one thing that must not
            // happen is a quiet degradation to "nobody ever searched".
            trigger_error(
                'DLNA SSDP could not join multicast group ' . self::SSDP_MULTICAST_ADDRESS
                . '; only unicast M-SEARCH will be answered.',
                E_USER_WARNING
            );
        }
    }

    /**
     * Did the multicast group join succeed?
     *
     * Exposed for the same reason it is recorded: see {@see self::$multicastJoined}.
     *
     * @since 1.7.0
     */
    public function hasJoinedMulticastGroup(): bool
    {
        return $this->multicastJoined;
    }

    /**
     * Handle one inbound datagram on the SSDP socket.
     *
     * Everything that is not a search we match is dropped in silence. This
     * socket sees every NOTIFY on the segment, so "say nothing" is the normal
     * outcome and must stay the cheapest one.
     *
     * @param UdpConnection $connection The Workerman connection; `send()` on it
     *        replies UNICAST to the datagram's source, which is what SSDP
     *        requires — a search response must never be multicast.
     * @param string $datagram Raw inbound payload.
     *
     * @since 1.7.0
     */
    private function handleSearch(UdpConnection $connection, string $datagram): void
    {
        if (!$this->respondToSearches) {
            return;
        }

        // Answer only peers that could actually USE the answer. The LOCATION we
        // hand out is served behind DlnaAllowlistMiddleware, so replying to a
        // peer that middleware would 403 advertises a device it cannot open —
        // the precise failure the two DLNA switches exist to prevent. Matching
        // the reply surface to the browse surface also keeps this unauthenticated
        // UDP responder off the open internet.
        if ($this->allowlist !== null && !$this->allowlist->isAllowed($connection->getRemoteIp())) {
            return;
        }

        $targets = SsdpSearchResponder::matchedTargets($datagram, self::USN);
        if ($targets === []) {
            return;
        }

        $delayCap = SsdpSearchResponder::delayCapSeconds($datagram);

        if ($delayCap <= 0) {
            // Unicast search, or no usable MX: answer now. No timer is created,
            // so the common path allocates nothing that has to be reclaimed.
            $this->sendSearchResponses($connection, $targets);
            return;
        }

        if (count($this->pendingReplyTimers) >= self::MAX_PENDING_REPLIES) {
            return;
        }

        // UPnP DA 1.0 §1.3.3: spread the reply randomly over [0, MX] so a
        // segment full of devices does not answer a multicast search in unison.
        // One-shot timer — `Timer::add(..., [], false)`; a persistent one would
        // re-answer the same search every MX seconds forever.
        $delay = mt_rand(0, $delayCap * 1000) / 1000.0;

        $timerId = 0;
        $timerId = Timer::add(
            $delay,
            function () use ($connection, $targets, &$timerId): void {
                unset($this->pendingReplyTimers[$timerId]);
                $this->sendSearchResponses($connection, $targets);
            },
            [],
            false
        );

        $this->pendingReplyTimers[$timerId] = true;
    }

    /**
     * Write one unicast response per matched target.
     *
     * The `LOCATION` is {@see self::getLocationUrl()} — the same string the
     * periodic NOTIFY carries, by construction rather than by coincidence. A
     * control point that sees both a NOTIFY and a search response with different
     * LOCATIONs concludes it has found two devices.
     *
     * @param UdpConnection $connection Reply target.
     * @param list<string>  $targets    Matched search targets.
     *
     * @since 1.7.0
     */
    private function sendSearchResponses(UdpConnection $connection, array $targets): void
    {
        // Re-check: an MX-delayed reply lands after `onWorkerStop` may have run.
        if (!$this->respondToSearches) {
            return;
        }

        $location = $this->getLocationUrl();

        foreach ($targets as $target) {
            $connection->send(
                SsdpSearchResponder::buildResponse($target, self::USN, $location),
                true
            );
        }
    }

    /**
     * Is this server advertising itself to DLNA/UPnP devices?
     *
     * Backs the `dlna.enabled` setting. Read through
     * {@see EffectiveConfig::file()} (memoised per bootstrap generation), so an
     * admin override applies on the next graceful reload without needing the
     * master process to re-evaluate anything.
     *
     * ## What this does and does NOT gate
     *
     * It gates the SSDP advertiser — the broadcast that makes this server
     * appear in a smart TV's source list — and nothing else. It deliberately
     * does not claim to gate DLNA *browsing*, because the ContentDirectory
     * service is not currently registered at all: `Application::loadCdsRoutes()`
     * resolves {@see CdsServer} inside a bare `catch (\Throwable)`, and that
     * resolution always throws because {@see DlnaServer} has no DI registration
     * and un-autowirable `string` constructor parameters. See `config/dlna.php`
     * for the production evidence. If that is ever fixed, extend the gate to
     * the CDS routes and widen the schema `helpText` in the same change.
     *
     * Defaults to TRUE when the key is absent, so existing installs keep
     * advertising exactly as they did before `config/dlna.php` existed.
     *
     * @return bool
     *
     * @since 1.3.0
     */
    public static function isEnabled(): bool
    {
        $dlna = EffectiveConfig::file('dlna');

        // Requires BOTH switches. Announcing is meaningless — actively harmful,
        // in fact — when the ContentDirectory is not being served: a control
        // point that sees the advertisement fetches the LOCATION URL and then
        // fails, so the server shows up in every TV's source list as a device
        // that cannot be opened. That was the real production state for months,
        // and it is exactly what this gate now prevents.
        //
        // `cds_enabled` therefore behaves as the master "run a DLNA server"
        // switch (default FALSE — DLNA has no authentication), while `enabled`
        // chooses whether a running server also announces itself. Turning the
        // server on with announcement off is a legitimate combination: clients
        // that are given the address directly still work.
        if (($dlna['cds_enabled'] ?? false) !== true) {
            return false;
        }

        return ($dlna['enabled'] ?? true) !== false;
    }

    /**
     * Get the IP address used for advertisements.
     *
     * ## S53: `dlna.advertise_host` used to be IGNORED here
     *
     * `start.php` constructs `new SsdpAdvertiser(null, …)`, so this method took
     * the null branch in production and went straight to
     * {@see self::detectLocalIp()}. Meanwhile the device description
     * ({@see \Phlix\Common\Container\Providers\DlnaServicesProvider}) DID honour
     * `dlna.advertise_host`. They agreed only by coincidence, under the shipped
     * default of `''`; the moment an operator set the key — which is exactly
     * what `config/dlna.php` tells them to do on a multi-homed or Docker host —
     * the `LOCATION` named the auto-detected interface while every URL inside
     * the document it pointed at named the configured one.
     *
     * The explicit constructor argument still wins (tests, and any caller that
     * knows better); only the fallback changed, from "detect" to "the ONE
     * resolver", which itself ends in the same detection.
     *
     * @return string IP address or host name
     *
     * @since 0.12.0
     */
    public function getIpAddress(): string
    {
        if ($this->ipAddress !== null) {
            return $this->ipAddress;
        }

        return DlnaAdvertisedHost::host();
    }

    /**
     * Send SSDP alive NOTIFY message.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendAlive(): void
    {
        $this->sendNotify(self::NTS_ALIVE);
    }

    /**
     * Send SSDP byebye NOTIFY message.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendByebye(): void
    {
        $this->sendNotify(self::NTS_BYEBYE);
    }

    /**
     * Send an SSDP NOTIFY message.
     *
     * ## `CACHE-CONTROL` and `SERVER` are not decoration
     *
     * Both are REQUIRED on an `ssdp:alive` NOTIFY (UPnP DA 1.0 §1.2.2) and both
     * were missing here. `CACHE-CONTROL: max-age` is the advertisement's
     * lifetime: with no `max-age` a control point has nothing to expire us
     * against, and the conformant reading is to discard the advertisement
     * outright. `SERVER` identifies the implementation and is what several
     * renderers key their compatibility quirks off.
     *
     * They are emitted with exactly the values
     * {@see SsdpSearchResponder::buildResponse()} uses, from the same constants,
     * so a control point cannot be told two different lifetimes for one device
     * depending on how it found us.
     *
     * `byebye` carries them too. The spec says `CACHE-CONTROL` and `LOCATION`
     * are "not used" on a byebye rather than forbidden, and every field being
     * identical bar `NTS` keeps the two messages from drifting apart.
     *
     * @param string $nts Notification subtype (ssdp:alive or ssdp:byebye)
     * @return void
     *
     * @since 0.12.0
     */
    private function sendNotify(string $nts): void
    {
        if ($this->socket === null) {
            return;
        }

        $ipAddress = $this->getIpAddress();
        $location = sprintf('http://%s:%d/dlna/description.xml', $ipAddress, $this->port);

        $message = sprintf(
            "NOTIFY * HTTP/1.1\r\n" .
            "HOST: %s:%d\r\n" .
            "CACHE-CONTROL: max-age=%d\r\n" .
            "NT: %s\r\n" .
            "NTS: %s\r\n" .
            "LOCATION: %s\r\n" .
            "SERVER: %s\r\n" .
            "USN: %s\r\n" .
            "\r\n",
            self::SSDP_MULTICAST_ADDRESS,
            self::SSDP_PORT,
            SsdpSearchResponder::MAX_AGE_SECONDS,
            self::USN,
            $nts,
            $location,
            SsdpSearchResponder::serverHeader(),
            self::USN
        );

        $this->sendUdpMessage($message);
    }

    /**
     * Send a UDP message to the SSDP multicast address.
     *
     * @param string $message The UDP message to send
     * @return void
     *
     * @since 0.12.0
     */
    private function sendUdpMessage(string $message): void
    {
        if ($this->socket === null) {
            return;
        }

        $address = self::SSDP_MULTICAST_ADDRESS;
        $port = self::SSDP_PORT;

        try {
            $result = @fwrite($this->socket, $message);
            if ($result === false) {
                // Silently ignore write failures in case network is unavailable
            }
        } catch (Exception $e) {
            // Silently ignore socket errors
        }
    }

    /**
     * Create a UDP socket for SSDP broadcasts.
     *
     * @return resource|null Socket resource or null on failure
     *
     * @since 0.12.0
     */
    private function createUdpSocket()
    {
        $socket = @fsockopen(
            'udp://' . self::SSDP_MULTICAST_ADDRESS,
            self::SSDP_PORT,
            $errno,
            $errstr,
            1
        );

        if ($socket === false) {
            return null;
        }

        // Set socket to non-blocking for timeout behavior
        stream_set_blocking($socket, false);

        return $socket;
    }

    /**
     * Detect the LAN-facing local IP address.
     *
     * Shared deliberately. The SSDP `LOCATION` header this class broadcasts and
     * the `URLBase`/control URLs inside {@see DlnaServer}'s device description
     * MUST resolve to the same address — a control point fetches the
     * description from LOCATION and then follows the URLs inside it, so if the
     * two disagree every browse request goes to the wrong host. Keeping one
     * implementation is what guarantees they agree.
     *
     * Opens a UDP socket toward a public address purely to ask the kernel which
     * local interface it would route through; no packet is sent.
     *
     * @return string Local IP address, or `127.0.0.1` when detection fails.
     *
     * @since 1.3.0
     */
    public static function detectLocalIp(): string
    {
        // Connect to an external address to determine the local IP.
        // NOTE: deliberately NOT `(new self())->…` — this class extends
        // Workerman\Worker, whose constructor registers the instance in
        // Worker::$workers, so instantiating one just to read an IP would
        // inject a phantom worker into the runtime.
        $socket = @fsockopen('8.8.8.8', 53, $errno, $errstr, 1);
        if ($socket !== false) {
            $localAddress = stream_socket_get_name($socket, false);
            fclose($socket);

            if ($localAddress !== false) {
                $parts = explode(':', $localAddress);
                if (count($parts) >= 2) {
                    return $parts[0];
                }
            }
        }

        // Fallback to localhost
        return '127.0.0.1';
    }

    /**
     * Get the LOCATION URL for this advertiser.
     *
     * @return string LOCATION URL
     *
     * @since 0.12.0
     */
    public function getLocationUrl(): string
    {
        $ipAddress = $this->getIpAddress();
        return sprintf('http://%s:%d/dlna/description.xml', $ipAddress, $this->port);
    }

    /**
     * Get the USN (Unique Service Name) for this advertiser.
     *
     * @return string USN
     *
     * @since 0.12.0
     */
    public function getUsn(): string
    {
        return self::USN;
    }
}
