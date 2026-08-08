<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\SsdpAdvertiser;
use Phlix\Dlna\SsdpSearchResponder;
use Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\UdpConnection;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Does the M-SEARCH LISTENER actually receive, and actually reply?
 *
 * ## Why this file exists separately from {@see SsdpSearchResponderTest}
 *
 * That file proves the FORMATTER. Calling a handler directly and inspecting the
 * string it produced proves nothing about the part of this feature that can
 * fail invisibly — the socket. Before S51 this worker was constructed with no
 * socket name at all, so no `onMessage` could ever have fired however perfect
 * the formatting was; and a multicast group join has a wrong spelling that
 * returns TRUE and joins nothing. Both failure modes look exactly like "no
 * control point searched".
 *
 * So every test here puts a REAL datagram through a REAL bound UDP socket and
 * reads the reply back off the wire:
 *
 *   - the worker is constructed with a real `udp://0.0.0.0:{ephemeral}` socket
 *     name and `Worker::listen()` binds it for real;
 *   - a real `Workerman\Events\Select` event loop is installed as
 *     `Worker::$globalEvent`, and `Worker::resumeAccept()` registers Workerman's
 *     own `acceptUdpConnection()` read callback on it — i.e. the production
 *     dispatch path, not a hand-rolled one;
 *   - the request is written by an ordinary `stream_socket_client()`, and the
 *     reply is read back from that same client socket.
 *
 * The order below — `listen()`, then `onWorkerStart`, then `resumeAccept()` —
 * mirrors `Worker::run()` exactly, because that ordering is itself load-bearing
 * (the socket is bound and accepted from BEFORE and AFTER `onWorkerStart`
 * respectively, which is why the `dlna.enabled` gate cannot rely on an early
 * return alone).
 *
 * ## Assertion-escape discipline
 *
 * Nothing is asserted inside an event-loop callback — a callback runs under
 * Workerman's `try`/`catch` and a failed assertion there would be swallowed.
 * Callbacks only RECORD; every assertion runs after `run()` has returned.
 */
final class SsdpMSearchListenerTest extends TestCase
{
    /** How long a loop may run before it is stopped and the test decides. */
    private const LOOP_BUDGET_SECONDS = 3.0;

    /**
     * Budget for the cases that expect SILENCE.
     *
     * Shorter, because a reply on loopback arrives in single-digit milliseconds
     * — a ~750x margin — and the alternative is paying the full budget for every
     * negative case. Still an order of magnitude above any plausible scheduling
     * hiccup.
     */
    private const SILENCE_BUDGET_SECONDS = 0.75;

    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    private ?EventInterface $savedGlobalEvent = null;

    private ?EventInterface $savedTimerEvent = null;

    /**
     * Whether the worker driven by the last {@see self::exchange()} really had a
     * bound main socket at the moment the request was sent. Captured there
     * because `exchange()` tears the socket down before it returns, and "no
     * reply" is only meaningful next to proof that something was listening.
     */
    private bool $lastExchangeWasBound = false;

    /**
     * The worker's pending-MX-reply set as it stood the instant the event loop
     * returned, BEFORE `onWorkerStop` ran.
     *
     * Read here rather than after `exchange()` returns because `onWorkerStop`
     * clears that set unconditionally — which silently masked a leak: a
     * mutation that never removed a FIRED timer's id survived, because the stop
     * handler tidied up after it. The invariant under test is that a reply
     * drains its own id, not that shutdown eventually does.
     *
     * @var array<int, true>
     */
    private array $lastPendingAfterLoop = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
        $this->savedWorkers = Worker::getAllWorkers();
        $this->savedGlobalEvent = Worker::$globalEvent;
        $this->savedTimerEvent = $this->timerEvent();
    }

    protected function tearDown(): void
    {
        Worker::$globalEvent = $this->savedGlobalEvent;
        $this->setTimerEvent($this->savedTimerEvent);

        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        EffectiveConfig::reset();
        parent::tearDown();
    }

    // ==================================================================
    // The headline proof
    // ==================================================================

    /**
     * PROOF: a real M-SEARCH datagram, sent at a real bound socket, comes back
     * as a conformant unicast search response.
     *
     * This is the assertion the whole step rests on. It fails if the worker
     * binds nothing, if it never joins the read callback, if `onMessage` is
     * absent (its pre-S51 state), if the reply is multicast instead of unicast
     * (the client socket would never see it), or if any REQUIRED header is
     * missing.
     */
    public function test_a_real_msearch_at_a_real_socket_gets_a_conformant_unicast_reply(): void
    {
        $this->enableDlna();

        $replies = $this->exchange(
            $this->search(SsdpSearchResponder::DEVICE_TYPE, null),
            expectedReplies: 1
        );

        self::assertCount(
            1,
            $replies,
            'The listener received nothing or answered nothing: a real M-SEARCH went into a real bound '
            . 'socket and no unicast reply came back.'
        );

        $headers = $this->parseHeaders($replies[0]);

        self::assertStringStartsWith("HTTP/1.1 200 OK\r\n", $replies[0]);
        self::assertSame('max-age=1800', $headers['CACHE-CONTROL'] ?? null);
        self::assertSame('', $headers['EXT'] ?? null, 'EXT is REQUIRED (UPnP DA 1.0 §1.3.3).');
        self::assertSame(SsdpSearchResponder::DEVICE_TYPE, $headers['ST'] ?? null, 'ST must echo the MATCHED target.');
        self::assertSame(
            SsdpAdvertiser::USN . '::' . SsdpSearchResponder::DEVICE_TYPE,
            $headers['USN'] ?? null
        );
        self::assertMatchesRegularExpression('#^\S+/\S+ UPnP/1\.0 Phlix/\S+$#', $headers['SERVER'] ?? '');
        self::assertNotEmpty($headers['DATE'] ?? '', 'DATE is REQUIRED.');

        // LOCATION must be the advertiser's own, byte for byte — a control point
        // that sees a different LOCATION here and in the NOTIFY finds two devices.
        self::assertSame(
            'http://10.0.0.1:8096/dlna/description.xml',
            $headers['LOCATION'] ?? null
        );
    }

    /**
     * PROOF: `ssdp:all` really does produce FIVE separate datagrams on the wire.
     *
     * The formatter test proves five targets are matched; this proves five
     * `send()` calls reach the peer as five distinct datagrams. A single
     * concatenated datagram — which is what a naive implementation writes — is
     * parsed by a control point as one malformed response.
     */
    public function test_ssdp_all_produces_one_datagram_per_target_on_the_wire(): void
    {
        $this->enableDlna();

        $replies = $this->exchange($this->search(SsdpSearchResponder::ST_ALL, null), expectedReplies: 5);

        self::assertCount(5, $replies, 'ssdp:all must be answered with one datagram per advertised target.');

        $sts = [];
        foreach ($replies as $reply) {
            $headers = $this->parseHeaders($reply);
            $sts[] = $headers['ST'] ?? '';
        }
        sort($sts);

        $expected = SsdpSearchResponder::advertisedTargets(SsdpAdvertiser::USN);
        sort($expected);

        self::assertSame($expected, $sts);
    }

    /**
     * CONTROL, beside the proof above: the same live socket stays SILENT for a
     * NOTIFY.
     *
     * Without this, the headline test is satisfied by a listener that replies to
     * every datagram it receives. This socket sees a NOTIFY from every other
     * device on the segment (and its own, reflected by the multicast loop), so
     * "answers everything" is a packet storm, not a feature.
     */
    public function test_the_same_live_socket_stays_silent_for_a_notify(): void
    {
        $this->enableDlna();

        $notify = "NOTIFY * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nNT: upnp:rootdevice\r\n"
            . "NTS: ssdp:alive\r\nLOCATION: http://192.168.1.9:80/desc.xml\r\nUSN: uuid:OTHER\r\n\r\n";

        $replies = $this->exchange($notify, expectedReplies: 1, budget: self::SILENCE_BUDGET_SECONDS);

        self::assertSame([], $replies, 'A NOTIFY must draw no reply.');
    }

    /**
     * PROOF: an `MX` search is answered through a real one-shot timer on the
     * real event loop.
     *
     * The reply arrives only if `Timer::add(..., [], false)` fired, so this
     * covers the delayed path end to end — and the pending-timer bookkeeping is
     * asserted drained afterwards, because a leaked timer in a resident worker
     * is a memory leak, not a cosmetic issue.
     */
    public function test_an_mx_search_is_answered_via_a_one_shot_timer_and_leaves_nothing_pending(): void
    {
        $this->enableDlna();

        $worker = null;
        $replies = $this->exchange(
            $this->search(SsdpSearchResponder::ST_ROOT_DEVICE, '1'),
            expectedReplies: 1,
            worker: $worker
        );

        self::assertCount(1, $replies, 'A search carrying MX must still be answered, after its delay.');
        self::assertSame(
            SsdpSearchResponder::ST_ROOT_DEVICE,
            $this->parseHeaders($replies[0])['ST'] ?? null
        );

        self::assertInstanceOf(SsdpAdvertiser::class, $worker);
        self::assertSame(
            [],
            $this->lastPendingAfterLoop,
            'A fired reply must remove its OWN timer id, measured before onWorkerStop clears the set; '
            . 'otherwise this array grows for the life of the worker.'
        );
    }

    /**
     * REGRESSION GUARD: the MX reply timer is armed ONE-SHOT, never persistent.
     *
     * ## Why this one test calls the handler directly
     *
     * Everything else in this file works at the socket, deliberately. This
     * cannot: the wire-level MX test stops its event loop as soon as the first
     * reply arrives, so a PERSISTENT timer — which would re-answer the same
     * search every MX seconds for the life of the worker, forever, from an
     * unauthenticated UDP packet — is invisible to it. That gap was found by
     * mutation (flipping `Timer::add`'s `$persistent` argument to `true` left
     * the whole DLNA suite green), and this is the assertion that closes it.
     *
     * The event loop is replaced with a recorder, so what is read is the real
     * argument production passed: `Timer::add(..., persistent: false)` routes to
     * `delay()`, `persistent: true` routes to `repeat()`.
     */
    public function test_an_mx_reply_is_armed_as_a_one_shot_timer_not_a_repeating_one(): void
    {
        $this->enableDlna();

        /** @var list<array{kind: string, interval: float, func: callable}> $recorded */
        $recorded = [];

        $event = $this->createMock(EventInterface::class);
        $event->method('delay')->willReturnCallback(
            static function (float $interval, callable $func, array $args = []) use (&$recorded): int {
                $recorded[] = ['kind' => 'one-shot', 'interval' => $interval, 'func' => $func];
                return count($recorded);
            }
        );
        $event->method('repeat')->willReturnCallback(
            static function (float $interval, callable $func, array $args = []) use (&$recorded): int {
                $recorded[] = ['kind' => 'repeating', 'interval' => $interval, 'func' => $func];
                return count($recorded);
            }
        );
        $this->setTimerEvent($event);

        // Put the worker in the state onWorkerStart leaves it in, without
        // arming the 30-second NOTIFY timer that would pollute the recording.
        $worker = new SsdpAdvertiser('10.0.0.1', 8096);
        $this->setPrivate($worker, 'respondToSearches', true);
        $this->setPrivate($worker, 'allowlist', new DlnaAllowlistMiddleware());

        $connection = $this->createMock(UdpConnection::class);
        $connection->method('getRemoteIp')->willReturn('127.0.0.1');

        $handle = new ReflectionMethod(SsdpAdvertiser::class, 'handleSearch');
        $handle->setAccessible(true);
        $handle->invoke($worker, $connection, $this->search(SsdpSearchResponder::ST_ROOT_DEVICE, '3'));

        self::assertCount(1, $recorded, 'An MX search must arm exactly one timer.');
        self::assertSame(
            'one-shot',
            $recorded[0]['kind'],
            'A repeating timer would re-answer the same search every MX seconds for the life of the worker.'
        );
        self::assertGreaterThanOrEqual(0.0, $recorded[0]['interval']);
        self::assertLessThanOrEqual(3.0, $recorded[0]['interval'], 'The delay must fall inside the requested MX.');
    }

    /**
     * CONSEQUENCE: a flood of MX searches cannot grow the pending-timer set
     * without bound.
     *
     * `M-SEARCH` is unauthenticated UDP arriving at a resident process, so one
     * timer plus one closure per packet is a memory-exhaustion primitive with no
     * login required. Over the cap the search is dropped; SSDP is lossy and a
     * control point re-searches.
     */
    public function test_a_flood_of_delayed_searches_is_capped(): void
    {
        $this->enableDlna();

        $armed = 0;
        $event = $this->createMock(EventInterface::class);
        $event->method('delay')->willReturnCallback(
            static function () use (&$armed): int {
                return ++$armed;
            }
        );
        $this->setTimerEvent($event);

        $worker = new SsdpAdvertiser('10.0.0.1', 8096);
        $this->setPrivate($worker, 'respondToSearches', true);
        $this->setPrivate($worker, 'allowlist', new DlnaAllowlistMiddleware());

        $connection = $this->createMock(UdpConnection::class);
        $connection->method('getRemoteIp')->willReturn('127.0.0.1');

        $handle = new ReflectionMethod(SsdpAdvertiser::class, 'handleSearch');
        $handle->setAccessible(true);

        $flood = SsdpAdvertiser::MAX_PENDING_REPLIES * 3;
        for ($i = 0; $i < $flood; $i++) {
            $handle->invoke($worker, $connection, $this->search(SsdpSearchResponder::ST_ROOT_DEVICE, '5'));
        }

        $pending = new ReflectionProperty(SsdpAdvertiser::class, 'pendingReplyTimers');
        $pending->setAccessible(true);
        /** @var array<int, true> $set */
        $set = $pending->getValue($worker);

        self::assertLessThanOrEqual(SsdpAdvertiser::MAX_PENDING_REPLIES, count($set));
        self::assertSame(SsdpAdvertiser::MAX_PENDING_REPLIES, $armed, 'Timers past the cap must not be armed.');
        // Control: the cap is not simply "never arm anything".
        self::assertGreaterThan(0, $armed);
    }

    // ==================================================================
    // The gates, asserted at the socket
    // ==================================================================

    /**
     * CONSEQUENCE: `dlna.enabled = false` means the socket is bound and reading
     * but answers nothing.
     *
     * This is not a duplicate of the existing gate test. That one asserts the
     * advertiser opens no multicast SEND socket. It cannot see this failure,
     * because `Worker::run()` calls `listen()` BEFORE `onWorkerStart` and
     * `resumeAccept()` from a `finally` AFTER it — so the LISTEN socket exists
     * and is delivering datagrams no matter how `onWorkerStart` returned. Turn
     * DLNA off without the `$respondToSearches` flag and the server stops
     * announcing while remaining fully discoverable to anyone who asks.
     */
    public function test_a_disabled_advertiser_still_binds_but_answers_nothing(): void
    {
        $this->bootstrapDlna(['enabled' => false, 'cds_enabled' => true]);

        $replies = $this->exchange(
            $this->search(SsdpSearchResponder::ST_ALL, null),
            expectedReplies: 1,
            budget: self::SILENCE_BUDGET_SECONDS
        );

        self::assertTrue(
            $this->lastExchangeWasBound,
            'The control: the socket really was bound and accepting, so the silence below is a decision '
            . 'and not an absent listener.'
        );
        self::assertSame([], $replies, 'A disabled advertiser must not answer M-SEARCH.');
    }

    /**
     * CONSEQUENCE: a peer the DLNA allowlist would 403 gets no reply either.
     *
     * The LOCATION handed out by a search response is served behind
     * `DlnaAllowlistMiddleware`. Answering a peer that middleware rejects
     * advertises a device that peer cannot open — the exact failure the DLNA
     * switches exist to prevent — and turns this into an open-internet
     * unauthenticated UDP responder.
     *
     * `restrict_to_lan = false` with an empty `allowed_cidrs` is the middleware's
     * fully-locked-down state, so the loopback peer here is denied by the REAL
     * middleware reading the REAL setting.
     */
    public function test_a_peer_the_allowlist_rejects_gets_no_reply(): void
    {
        $this->bootstrapDlna([
            'enabled'         => true,
            'cds_enabled'     => true,
            'restrict_to_lan' => false,
            'allowed_cidrs'   => [],
        ]);

        $replies = $this->exchange(
            $this->search(SsdpSearchResponder::ST_ALL, null),
            expectedReplies: 1,
            budget: self::SILENCE_BUDGET_SECONDS
        );

        self::assertSame([], $replies, 'A peer outside the DLNA allowlist must not be told where to find us.');
    }

    /**
     * CONTROL for the test above: the SAME configuration shape, with the peer
     * allowed, DOES answer.
     *
     * Without this the denial test is satisfied by a responder that is broken
     * for everyone. `127.0.0.0/8` is in the middleware's LAN set, so restoring
     * `restrict_to_lan` is the single variable that changes.
     */
    public function test_the_allowlist_control_a_permitted_peer_is_answered(): void
    {
        $this->bootstrapDlna([
            'enabled'         => true,
            'cds_enabled'     => true,
            'restrict_to_lan' => true,
            'allowed_cidrs'   => [],
        ]);

        $replies = $this->exchange(
            $this->search(SsdpSearchResponder::ST_ROOT_DEVICE, null),
            expectedReplies: 1
        );

        self::assertCount(1, $replies, 'A loopback peer is LAN and must be answered.');
    }

    // ==================================================================
    // The multicast group join
    // ==================================================================

    /**
     * PROOF: the production join is the ONLY spelling that actually receives.
     *
     * Three arms on real sockets, and the middle one is the whole point:
     *
     *   A. no join                → receives nothing
     *   B. the `MdnsSocket` spelling (raw option `12` + a packed `inet_pton()`
     *      string, because PHP does not define `IP_ADD_MEMBERSHIP` at all)
     *                             → `socket_set_option()` returns TRUE and
     *                                receives NOTHING
     *   C. `SsdpAdvertiser::joinMulticastGroup()`, the production method, called
     *      on a real worker → receives
     *
     * Arm B is what a reasonable implementer copies from the only other
     * membership-join site in `src/`, and it would have shipped a responder that
     * never hears a multicast search while every other test in this repository
     * stayed green.
     *
     * Arm A doubles as the non-vacuity control: if it received the datagram, the
     * harness would be proving nothing. And if no interface on this host loops
     * multicast back at all, the test SKIPS on a raw-socket probe taken BEFORE
     * the production worker is built — so a container without multicast routes
     * never masquerades as a broken join.
     *
     * ## STATED LIMIT
     *
     * All three arms run on whichever interface this host actually loops
     * multicast back on, which on a VM (and on this dev box, measured) is `lo`
     * and NOT the LAN interface production's `interface => 0` resolves to.
     * What is therefore proved is that the production METHOD, with the
     * production OPTION SPELLING, really does cause multicast delivery — and
     * that the alternative spelling really does not. The kernel's
     * interface-0 route selection is NOT exercised; that needs a second host on
     * a real segment.
     */
    public function test_the_production_multicast_join_is_the_only_spelling_that_receives(): void
    {
        if (!function_exists('socket_import_stream') || !defined('MCAST_JOIN_GROUP')) {
            self::markTestSkipped('ext-sockets multicast support is not available.');
        }

        $group = SsdpAdvertiser::SSDP_MULTICAST_ADDRESS;

        // Environment probe FIRST, with raw sockets and no production code. If a
        // correct join cannot receive on ANY interface here, the host does not
        // loop multicast back and this test has nothing to say.
        $ifIndex = $this->findMulticastLoopbackInterface($group);
        if ($ifIndex === null) {
            self::markTestSkipped('This host does not deliver IPv4 multicast back to a joined socket.');
        }

        // ---- Arm A: no join at all.
        [$noJoin, $portA] = $this->bindEphemeral();
        $armA = $this->multicastRoundTrip($noJoin, $group, $portA, $ifIndex);
        fclose($noJoin);

        // ---- Arm B: the MdnsSocket spelling.
        [$mdnsStyle, $portB] = $this->bindEphemeral();
        $mdnsOption = defined('IP_ADD_MEMBERSHIP') ? IP_ADD_MEMBERSHIP : 12;
        $armBReturn = @socket_set_option(
            socket_import_stream($mdnsStyle),
            IPPROTO_IP,
            $mdnsOption,
            inet_pton($group) . inet_pton('0.0.0.0')
        );
        $armB = $this->multicastRoundTrip($mdnsStyle, $group, $portB, $ifIndex);
        fclose($mdnsStyle);

        // ---- Arm C: the PRODUCTION method on a real worker.
        [, $portC] = $this->bindEphemeral(release: true);
        $worker = new SsdpAdvertiser('10.0.0.1', 8096, null, "udp://0.0.0.0:{$portC}");
        $worker->listen(false);

        $join = new ReflectionMethod(SsdpAdvertiser::class, 'joinMulticastGroup');
        $join->setAccessible(true);
        $join->invoke($worker, $ifIndex);

        $mainSocket = $worker->getMainSocket();
        $armC = is_resource($mainSocket) && $this->multicastRoundTrip($mainSocket, $group, $portC, $ifIndex);
        $joinedFlag = $worker->hasJoinedMulticastGroup();
        $worker->unlisten();

        self::assertFalse($armA, 'CONTROL: without a group join, multicast must NOT be delivered.');
        self::assertTrue(
            $armBReturn,
            'The MdnsSocket spelling returns TRUE — that is precisely why it is dangerous.'
        );
        self::assertFalse(
            $armB,
            'The MdnsSocket spelling must be shown to receive NOTHING despite returning TRUE. '
            . 'If this ever passes, that class became correct and this warning can be retired.'
        );
        self::assertTrue($joinedFlag, 'The production join must report success.');
        self::assertTrue(
            $armC,
            'The production join must actually deliver multicast to the socket — this is the assertion '
            . 'that separates a real join from a silent no-op.'
        );
    }

    // ==================================================================
    // Harness
    // ==================================================================

    /**
     * Run one request/response exchange over real sockets and a real event loop.
     *
     * @param string               $request         Raw datagram to send.
     * @param int                  $expectedReplies Stop as soon as this many replies arrive.
     * @param SsdpAdvertiser|null  $worker          Out-param: the worker that was driven.
     *
     * @return list<string> Reply datagrams, in arrival order.
     */
    private function exchange(
        string $request,
        int $expectedReplies,
        ?SsdpAdvertiser &$worker = null,
        float $budget = self::LOOP_BUDGET_SECONDS
    ): array {
        [, $port] = $this->bindEphemeral(release: true);

        $select = new Select();
        Worker::$globalEvent = $select;
        Timer::init($select);

        $worker = new SsdpAdvertiser('10.0.0.1', 8096, null, "udp://0.0.0.0:{$port}");

        // Mirror Worker::run() exactly: bind, then onWorkerStart, then accept.
        $worker->listen(false);

        $onWorkerStart = $worker->onWorkerStart;
        self::assertIsCallable($onWorkerStart, 'SsdpAdvertiser must install an onWorkerStart callback.');
        $onWorkerStart($worker);

        $worker->resumeAccept();

        $this->lastExchangeWasBound = is_resource($worker->getMainSocket());

        $client = stream_socket_client("udp://127.0.0.1:{$port}", $errno, $errstr, 1);
        self::assertIsResource($client, "Could not open a client socket: {$errstr}");
        stream_set_blocking($client, false);

        /** @var list<string> $replies */
        $replies = [];

        // Callbacks RECORD ONLY. An assertion here would run inside Workerman's
        // try/catch and could not decide this test's outcome.
        $select->onReadable($client, static function ($stream) use (&$replies, $select, $expectedReplies): void {
            $datagram = stream_socket_recvfrom($stream, 65535);
            if (is_string($datagram) && $datagram !== '') {
                $replies[] = $datagram;
            }
            if (count($replies) >= $expectedReplies) {
                $select->stop();
            }
        });

        fwrite($client, $request);

        // Safety net: a listener that never answers must end the loop and fail
        // an assertion, not hang the suite.
        $select->delay($budget, static function () use ($select): void {
            $select->stop();
        }, []);

        $select->run();

        $pending = new ReflectionProperty(SsdpAdvertiser::class, 'pendingReplyTimers');
        $pending->setAccessible(true);
        /** @var array<int, true> $pendingNow */
        $pendingNow = $pending->getValue($worker);
        $this->lastPendingAfterLoop = $pendingNow;

        // Drain anything that arrived after the stop, so a duplicate-reply bug
        // is visible to the count assertions rather than swallowed.
        while (($extra = @stream_socket_recvfrom($client, 65535)) !== false && $extra !== '') {
            $replies[] = $extra;
        }

        $onWorkerStop = $worker->onWorkerStop;
        if (is_callable($onWorkerStop)) {
            $onWorkerStop($worker);
        }
        $worker->unlisten();
        fclose($client);

        return $replies;
    }

    /**
     * Bind a UDP socket on a free ephemeral port.
     *
     * `0.0.0.0` rather than `127.0.0.1`: a loopback-only socket cannot join an
     * IPv4 multicast group on the default interface, and production binds
     * `0.0.0.0` too.
     *
     * @param bool $release Close the socket and return only the port, for a
     *                      caller that is about to bind it itself.
     *
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

        stream_set_blocking($socket, false);

        if ($release) {
            fclose($socket);
            return [null, $port];
        }

        return [$socket, $port];
    }

    /**
     * The lowest interface index on which this host loops multicast back to a
     * correctly joined socket, or null if there is none.
     *
     * Raw sockets only — no production code — so that a "no multicast here"
     * environment is detected BEFORE anything under test is built and can never
     * be mistaken for a broken join.
     */
    private function findMulticastLoopbackInterface(string $group): ?int
    {
        // 0 = kernel routes it; 1..4 covers lo plus the first few NICs.
        foreach ([0, 1, 2, 3, 4] as $ifIndex) {
            [$socket, $port] = $this->bindEphemeral();
            $joined = @socket_set_option(
                socket_import_stream($socket),
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
     * Send one datagram to a multicast group and report whether $socket got it.
     *
     * @param resource $socket A bound, non-blocking UDP stream.
     */
    private function multicastRoundTrip(mixed $socket, string $group, int $port, int $ifIndex): bool
    {
        $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sender === false) {
            return false;
        }

        // TTL 1 — never leaves the local segment.
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_LOOP, 1);
        @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_TTL, 1);
        if ($ifIndex > 0) {
            @socket_set_option($sender, IPPROTO_IP, IP_MULTICAST_IF, $ifIndex);
        }
        $payload = 'PHLIX-S51-MCAST-PROBE';
        @socket_sendto($sender, $payload, strlen($payload), 0, $group, $port);
        socket_close($sender);

        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false || $ready < 1) {
            return false;
        }

        return stream_socket_recvfrom($socket, 1500) === $payload;
    }

    private function search(string $st, ?string $mx): string
    {
        $lines = [
            'M-SEARCH * HTTP/1.1',
            'HOST: 239.255.255.250:1900',
            'MAN: "ssdp:discover"',
            'ST: ' . $st,
        ];
        if ($mx !== null) {
            $lines[] = 'MX: ' . $mx;
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    private function enableDlna(): void
    {
        $this->bootstrapDlna(['enabled' => true, 'cds_enabled' => true]);
    }

    /**
     * @param array<string, mixed> $dlna
     */
    private function bootstrapDlna(array $dlna): void
    {
        $dir = sys_get_temp_dir() . '/phlix_s51_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlna, true) . ";\n");
        EffectiveConfig::bootstrap(null, null, $dir);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $response): array
    {
        $out = [];
        $lines = explode("\r\n", trim($response));
        array_shift($lines);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $out[strtoupper(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }

        return $out;
    }

    private function setPrivate(SsdpAdvertiser $worker, string $property, mixed $value): void
    {
        $prop = new ReflectionProperty(SsdpAdvertiser::class, $property);
        $prop->setAccessible(true);
        $prop->setValue($worker, $value);
    }

    private function timerEvent(): ?EventInterface
    {
        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        /** @var EventInterface|null $value */
        $value = $prop->getValue();

        return $value;
    }

    private function setTimerEvent(?EventInterface $event): void
    {
        $prop = new ReflectionProperty(Timer::class, 'event');
        $prop->setAccessible(true);
        $prop->setValue(null, $event);
    }
}
