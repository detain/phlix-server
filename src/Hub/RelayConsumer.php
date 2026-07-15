<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Request as ServerRequest;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response as ServerResponse;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Phlix\Shared\Relay\RelayHttpRequest;
use Phlix\Shared\Relay\RelayHttpRequestChunk;
use Phlix\Shared\Relay\RelayHttpRequestCodec;
use Phlix\Shared\Relay\RelayHttpRequestHead;
use Phlix\Shared\Relay\RelayHttpResponseCodec;
use Phlix\Shared\Relay\RelayHttpResponseHead;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function strcasecmp;
use function stripos;
use function strlen;
use function substr;

/**
 * Server-side relay client implementing the multiplexed WebSocket tunnel.
 *
 * Architecture (the SERVER half of the hub<->server relay):
 *
 *   1. Connect outbound to the hub's server-tunnel WS worker
 *      ({@see \Phlix\Hub\Relay\RelayWorker}, default ws://<hub>:8802).
 *   2. On connect, send a JSON HELLO
 *      ({"type":"hello","enrollment_jwt":"…","server_id":"…"}) as the first
 *      WS message via {@see RelayMessageFramer::encodeHello()}.
 *   3. Parse the hub's JSON HELLO_ACK ({"type":"hello_ack",…}); only then
 *      enter binary frame mode. A missing/garbage ack closes + reconnects.
 *   4. In binary mode, decode {@see RelayFrameType} frames and:
 *        - CLIENT_CONNECT: open an AsyncTcpConnection to this server's own
 *          local HTTP listener (default 127.0.0.1:8096) for the frame's
 *          per-client CHANNEL id, remembering the channel -> local connection
 *          mapping.
 *        - DATA: write the raw bytes verbatim to the local connection for the
 *          frame's channel id. Local response bytes are wrapped back into DATA
 *          frames (chunked to <= 65535), each TAGGED with that channel id, and
 *          sent to the hub.
 *        - CLIENT_DISCONNECT: close + forget the channel's local connection.
 *        - HEARTBEAT: reply with a HEARTBEAT frame and track liveness.
 *        - DISCONNECTED / ERROR: log; tunnel-level errors trigger reconnect.
 *
 * Raw-byte piping (rather than re-parsing HTTP) is the protocol-correct match:
 * the hub forwards opaque client bytes, so the server forwards them opaque too.
 *
 * MULTI-CLIENT CHANNEL DEMULTIPLEXING
 * -----------------------------------
 * Each remote client is assigned a stable uint32 CHANNEL id by the hub at
 * CLIENT_CONNECT time, carried in the frame's `seq` field
 * ({@see RelayFrame::channelId()}). Every client-scoped frame (CLIENT_CONNECT,
 * CLIENT_DISCONNECT, DATA) carries the channel id, so multiple concurrent
 * clients are fully isolated: inbound DATA is routed to the local connection
 * for its channel, and that connection's response bytes are tagged with the
 * same channel on the way back. A DATA frame for an unknown/closed channel is
 * dropped and logged. HEARTBEAT frames are tunnel-scoped (channel 0).
 *
 * @package Phlix\Hub
 * @since 0.5.0
 */
final class RelayConsumer
{
    /** Tunnel handshake/data state: not yet connected. */
    private const STATE_DISCONNECTED = 'disconnected';

    /** Tunnel handshake/data state: WS open, HELLO sent, awaiting HELLO_ACK. */
    private const STATE_HANDSHAKING = 'handshaking';

    /** Tunnel handshake/data state: HELLO_ACK received, binary mode active. */
    private const STATE_ACTIVE = 'active';

    /**
     * Truthful client IP for every relayed request. All relay traffic egresses
     * from this server's own loopback HTTP listener, so loopback is the real
     * origin. We never derive the IP from the producer-suppliable
     * x-forwarded-for header (stripped via RelayHttpRequest::withoutForbiddenHeaders())
     * because an untrusted relay producer could spoof it.
     */
    private const RELAY_REMOTE_IP = '127.0.0.1';

    /**
     * Per-request deadline for HTTP dispatch over the relay tunnel.
     *
     * Prevents one slow relayed request (e.g. a blocking metadata call) from
     * stalling the single relay worker indefinitely. If the deadline expires
     * a 504 Gateway Timeout is sent and the request is abandoned.
     */
    private const DISPATCH_DEADLINE_SECONDS = 30;

    /**
     * Maximum total size (bytes) of a reassembled chunked relayed request body.
     *
     * A chunked HTTP_REQUEST (HB-2.1) whose accumulated body exceeds this cap is
     * dropped with a 413 and its accumulator discarded, so a malicious or broken
     * producer cannot grow a resident worker's memory without bound. 25 MiB
     * comfortably covers artwork/poster uploads while staying bounded.
     */
    private const MAX_REASSEMBLED_REQUEST_BODY = 26214400;

    /**
     * Maximum number of concurrent in-flight chunked-request assemblies.
     *
     * A HEAD chunk that would exceed this is refused with 503 so an attacker
     * cannot open unbounded accumulators by sending many HEADs that never END.
     */
    private const MAX_CONCURRENT_REQUEST_ASSEMBLIES = 128;

    /** @var RelayConfig */
    private RelayConfig $config;

    /** @var HubClient */
    private HubClient $hubClient;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var string */
    private string $serverId;

    /** @var RelayMessageFramer Wire codec for the multiplexed protocol. */
    private RelayMessageFramer $codec;

    /** @var AsyncTcpConnection|null Outbound WS connection to the hub. */
    private ?AsyncTcpConnection $connection = null;

    /** @var bool */
    private bool $running = false;

    /** @var string Current tunnel state (STATE_*). */
    private string $state = self::STATE_DISCONNECTED;

    /** @var int|null */
    private ?int $reconnectTimer = null;

    /** @var int|null */
    private ?int $heartbeatTimer = null;

    /** @var int Number of consecutive reconnection attempts (exponential backoff). */
    private int $reconnectAttempts = 0;

    /** @var \DateTimeImmutable|null Timestamp of the last tunnel disconnect. */
    private ?\DateTimeImmutable $lastDisconnectTime = null;

    /** @var int Nanoseconds from hrtime(true) when the session started. */
    private int $sessionStartTime = 0;

    /** @var string|null Hub-assigned relay session id (from HELLO_ACK). */
    private ?string $relaySessionId = null;

    /** @var bool Whether the session-end log has already been emitted. */
    private bool $sessionEndLogged = false;

    /** @var string Buffered incoming binary data awaiting frame boundaries. */
    private string $recvBuffer = '';

    /**
     * Local HTTP connections keyed by per-client CHANNEL id.
     *
     * The channel id is the uint32 the hub assigns at CLIENT_CONNECT and carries
     * in every client-scoped frame's `seq` field. This map is the inverse of the
     * hub's channel→client map and is how concurrent clients stay isolated.
     *
     * @var array<int, AsyncTcpConnection>
     */
    private array $localConnections = [];

    /**
     * Channel ids whose local connection has been {@see AsyncTcpConnection::pauseRecv()}'d
     * because the hub tunnel's send buffer was full when {@see sendDataFrame()}
     * tried to relay their bytes (SV-2.3, [S-F36], the local->hub direction).
     *
     * The tunnel connection is a SINGLE shared object multiplexing every
     * channel, so it exposes only one `onBufferDrain` slot — a naive
     * per-channel callback registration would clobber earlier channels'
     * resume callbacks. Instead every paused channel id is recorded here and
     * {@see armTunnelDrainResume()} arms (idempotently) ONE handler that
     * resumes every pending channel when the tunnel drains. Entries are
     * removed as soon as their channel closes (see {@see onLocalClose()},
     * {@see closeLocalConnection()}, {@see closeAllLocalConnections()}) so
     * this cannot grow unbounded across the life of a resident worker.
     *
     * @var array<int, true>
     */
    private array $pausedForTunnelDrain = [];

    /**
     * In-flight chunked-request assemblies keyed by relay request id (HB-2.1).
     *
     * A relayed request whose body exceeds a single 65535-byte frame arrives as
     * an HTTP_REQUEST HEAD chunk, then zero or more BODY chunks, then an END
     * chunk, all sharing one request id (see {@see RelayHttpRequestCodec}). This
     * map accumulates the head + raw body bytes between those frames and is
     * finalized (and cleared) on END. It is also cleared on cancel and tunnel
     * teardown so a producer that never sends END cannot leak memory in the
     * resident worker.
     *
     * @var array<int, array{head: RelayHttpRequestHead, body: string, size: int}>
     */
    private array $requestAccumulators = [];

    /**
     * Factory that opens the outbound hub WS connection.
     *
     * @var (callable(string): AsyncTcpConnection)|null
     */
    private $hubConnectionFactory;

    /**
     * Factory that opens a local HTTP connection for a client.
     *
     * @var (callable(string): AsyncTcpConnection)|null
     */
    private $localConnectionFactory;

    /**
     * Dispatcher for HTTP_REQUEST frames: routes a synthetic request through
     * the server's local app routers and returns the response. When null, the
     * server cannot service proxied HTTP requests and replies 503.
     *
     * @var (callable(ServerRequest): ServerResponse)|null
     */
    private $httpDispatcher;

    /**
     * Optional per-worker segment-process registry used to kill any tracked
     * on-demand ffmpeg encode when the browser abandons a streaming request
     * (SV-4.2 [S-F23], the server half of the X1 HTTP_CANCEL chain). Null unless
     * wired via {@see setSegmentProcessRegistry()}.
     *
     * The relay fork dispatches proxied HTTP_REQUEST frames IN THIS PROCESS (via
     * its own {@see \Phlix\Hub\RelayRequestDispatcher}), so an on-demand encode a
     * relayed segment request launches registers into this same per-worker
     * registry singleton. The encode is grouped under the hub channel/request id
     * (published into {@see RequestContext} during dispatch), so
     * {@see onHttpCancel()} kills it directly by channel id via
     * {@see \Phlix\Media\Transcoding\SegmentProcessRegistry::killGroup()}.
     * {@see closeLocalConnection()} remains the belt-and-braces teardown of the
     * forwarded request.
     *
     * @var \Phlix\Media\Transcoding\SegmentProcessRegistry|null
     */
    private $segmentRegistry = null;

    /**
     * @param RelayConfig      $config                  Relay configuration.
     * @param HubClient        $hubClient               Hub client (for enrollment info).
     * @param StructuredLogger $logger                  Logger instance.
     * @param string           $serverId                Hub-assigned server UUID.
     * @param (callable(string): AsyncTcpConnection)|null $hubConnectionFactory
     *        Optional hub-connection factory override (for testing). Receives the
     *        Workerman WS address and returns a connection.
     * @param (callable(string): AsyncTcpConnection)|null $localConnectionFactory
     *        Optional local-connection factory override (for testing). Receives the
     *        Workerman tcp:// address and returns a connection.
     * @param (callable(ServerRequest): ServerResponse)|null $httpDispatcher
     *        Dispatcher for proxied HTTP_REQUEST frames (routes a synthetic request
     *        through the local app and returns the response). Null disables HTTP
     *        proxying (the server replies 503 to HTTP_REQUEST frames).
     */
    public function __construct(
        RelayConfig $config,
        HubClient $hubClient,
        StructuredLogger $logger,
        string $serverId,
        ?callable $hubConnectionFactory = null,
        ?callable $localConnectionFactory = null,
        ?callable $httpDispatcher = null,
    ) {
        $this->config = $config;
        $this->hubClient = $hubClient;
        $this->logger = $logger;
        $this->serverId = $serverId;
        $this->codec = new RelayMessageFramer();
        $this->hubConnectionFactory = $hubConnectionFactory;
        $this->localConnectionFactory = $localConnectionFactory;
        $this->httpDispatcher = $httpDispatcher;
    }

    /**
     * Wire the segment-process registry so {@see onHttpCancel()} can kill any
     * tracked on-demand ffmpeg encode for a cancelled request (SV-4.2).
     *
     * @param \Phlix\Media\Transcoding\SegmentProcessRegistry $registry Registry.
     *
     * @return void
     *
     * @since SV-4.2
     */
    public function setSegmentProcessRegistry(\Phlix\Media\Transcoding\SegmentProcessRegistry $registry): void
    {
        $this->segmentRegistry = $registry;
    }

    /**
     * Start the relay consumer (initiates the outbound tunnel to the hub).
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        if (!$this->config->enabled) {
            $this->logger->info('RelayConsumer: relay is disabled');
            return;
        }

        $this->running = true;
        $this->connect();
    }

    /**
     * Stop the relay consumer gracefully.
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->reconnectTimer !== null) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = null;
        }

        if ($this->heartbeatTimer !== null) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        $this->closeAllLocalConnections();

        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }

        $this->recvBuffer = '';
        $this->requestAccumulators = [];
        $this->state = self::STATE_DISCONNECTED;

        $this->logger->info('RelayConsumer stopped');
    }

    /**
     * Returns whether the consumer holds an open tunnel to the hub.
     *
     * @return bool True if connected.
     *
     * @since 0.5.0
     */
    public function isConnected(): bool
    {
        return $this->connection !== null
            && $this->connection->getStatus() === \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED;
    }

    /**
     * Returns whether the tunnel has completed the HELLO handshake and is in
     * binary frame mode.
     *
     * @return bool True if active.
     *
     * @since 0.5.0
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /**
     * Returns the number of consecutive reconnection attempts.
     *
     * @return int Reconnect attempts count.
     *
     * @since 0.13.0
     */
    public function getReconnectAttempts(): int
    {
        return $this->reconnectAttempts;
    }

    /**
     * Returns the count of currently active relay sessions (connected clients).
     *
     * @return int Active session count.
     *
     * @since 0.13.0
     */
    public function getActiveSessionCount(): int
    {
        return count($this->localConnections);
    }

    /**
     * Returns the timestamp of the last tunnel disconnect.
     *
     * @return string|null ISO 8601 timestamp or null if never disconnected.
     *
     * @since 0.13.0
     */
    public function getLastDisconnectTime(): ?string
    {
        return $this->lastDisconnectTime?->format('c');
    }

    /**
     * Connect to the hub's server-tunnel WS endpoint.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function connect(): void
    {
        $this->logger->debug('RelayConsumer::connect() START', [
            'current_state' => $this->state,
            'connection_exists' => $this->connection !== null,
            'connection_status' => $this->connection?->getStatus(),
            'running' => $this->running,
            'reconnect_attempts' => $this->reconnectAttempts,
            'last_disconnect_time' => $this->lastDisconnectTime?->format('c'),
            'server_id' => $this->serverId,
        ]);

        // Prevent concurrent connect attempts while already active.
        if ($this->state === self::STATE_ACTIVE) {
            $this->logger->debug('RelayConsumer::connect() early return - already STATE_ACTIVE');
            return;
        }

        // During HANDSHAKING with an existing connection, close it first so a
        // racing reconnect can replace the stale socket cleanly.
        if ($this->state === self::STATE_HANDSHAKING && $this->connection === null) {
            $this->logger->debug('RelayConsumer::connect() early return - HANDSHAKING and connection is null');
            return;
        }

        // Defensive: never orphan a prior connection. If connect() races a
        // still-open socket (e.g. a reconnect fires before the previous close
        // hook nulled it), detach its callbacks first — so its close hook does
        // NOT re-enter handleDisconnect() and schedule a competing reconnect —
        // then close it, releasing the socket instead of leaking it.
        if ($this->connection !== null) {
            $this->logger->debug('RelayConsumer::connect() closing stale connection', [
                'stale_connection_status' => $this->connection->getStatus(),
            ]);
            $stale = $this->connection;
            $this->connection = null;
            $stale->onConnect = null;
            $stale->onMessage = null;
            $stale->onError = null;
            $stale->onClose = null;
            $stale->close();
        }

        $wsUrl = $this->config->buildHubRelayWsUrl();
        $this->logger->debug('RelayConsumer::connect() built hub relay WS URL', [
            'ws_url' => $wsUrl,
            'ws_url_empty' => $wsUrl === '',
        ]);
        if ($wsUrl === '') {
            $this->logger->error('RelayConsumer: no hub relay WS endpoint configured');
            $this->scheduleReconnect();
            return;
        }

        $enrollment = $this->hubClient->loadEnrollment();
        $this->logger->debug('RelayConsumer::connect() loaded enrollment', [
            'enrollment_exists' => $enrollment !== null,
        ]);
        if ($enrollment === null) {
            $this->logger->error('RelayConsumer: cannot connect without enrollment');
            $this->scheduleReconnect();
            return;
        }

        $this->logger->info('RelayConsumer connecting', [
            'url' => $wsUrl,
            'server_id' => $this->serverId,
        ]);

        $this->recvBuffer = '';
        $this->state = self::STATE_HANDSHAKING;
        $this->logger->debug('RelayConsumer::connect() calling openHubConnection()', [
            'ws_url' => $wsUrl,
            'url_scheme' => parse_url($wsUrl, PHP_URL_SCHEME),
        ]);

        try {
            $this->connection = $this->openHubConnection($wsUrl);
            $this->logger->debug('RelayConsumer::connect() openHubConnection() returned', [
                'connection_class' => get_class($this->connection),
                'connection_id' => spl_object_id($this->connection),
                'connection_status' => $this->connection->getStatus(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('RelayConsumer::connect() openHubConnection() threw exception', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->scheduleReconnect();
            return;
        }

        $enrollmentJwt = $enrollment->enrollmentJwt;

        $this->logger->debug('RelayConsumer::connect() setting onConnect callback');
        $this->connection->onConnect = function (AsyncTcpConnection $conn) use ($enrollmentJwt): void {
            $this->logger->info('RelayConsumer connected; sending HELLO');
            $this->sendHello($enrollmentJwt);
        };

        $this->logger->debug('RelayConsumer::connect() setting onMessage callback');
        $this->connection->onMessage = function (ConnectionInterface $conn, string $data): void {
            $this->onHubMessage($data);
        };

        $this->logger->debug('RelayConsumer::connect() setting onError callback');
        $this->connection->onError = function (ConnectionInterface $conn, int $code, string $msg): void {
            $this->logger->error('RelayConsumer connection error', [
                'code' => $code,
                'message' => $msg,
            ]);
        };

        $this->logger->debug('RelayConsumer::connect() setting onClose callback');
        $this->connection->onClose = function (ConnectionInterface $conn): void {
            $this->logger->warning('RelayConsumer connection closed');
            $this->handleDisconnect();
        };

        $this->logger->debug('RelayConsumer::connect() calling $connection->connect()', [
            'connection_id' => spl_object_id($this->connection),
            'connection_status_before_connect' => $this->connection->getStatus(),
        ]);
        try {
            $this->connection->connect();
            $this->logger->debug('RelayConsumer::connect() $connection->connect() returned', [
                'connection_id' => spl_object_id($this->connection),
                'connection_status_after_connect' => $this->connection->getStatus(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('RelayConsumer::connect() $connection->connect() threw exception', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->scheduleReconnect();
        }
    }

    /**
     * Open the outbound hub WS connection (overridable for tests).
     *
     * @param string $wsUrl Workerman WS address (ws://… or wss://…).
     *
     * @return AsyncTcpConnection
     *
     * @since 0.5.0
     */
    private function openHubConnection(string $wsUrl): AsyncTcpConnection
    {
        $this->logger->debug('RelayConsumer::openHubConnection() START', [
            'ws_url' => $wsUrl,
            'ws_url_scheme' => parse_url($wsUrl, PHP_URL_SCHEME),
            'ws_url_host' => parse_url($wsUrl, PHP_URL_HOST),
            'ws_url_port' => parse_url($wsUrl, PHP_URL_PORT),
            'has_hub_connection_factory' => $this->hubConnectionFactory !== null,
        ]);

        if ($this->hubConnectionFactory !== null) {
            $this->logger->debug('RelayConsumer::openHubConnection() using hubConnectionFactory');
            $result = ($this->hubConnectionFactory)($wsUrl);
            $this->logger->debug('RelayConsumer::openHubConnection() hubConnectionFactory returned', [
                'connection_class' => get_class($result),
            ]);
            return $result;
        }

        $context = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => '/etc/ssl/certs/ca-certificates.crt',
                'SNI_enabled' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ];

        $hubWsUrl = $wsUrl;
        $this->logger->debug('RelayConsumer: connecting with SSL verification enabled', [
            'hub_url' => $hubWsUrl,
            'verify_peer' => true,
        ]);

        $this->logger->debug('RelayConsumer::openHubConnection() creating new AsyncTcpConnection');
        $wsUrlForConnection = str_replace('wss://', 'ws://', $wsUrl);
        $connection = new AsyncTcpConnection($wsUrlForConnection, $context);
        $connection->protocol = \Workerman\Protocols\Websocket::class;
        $connection->transport = 'ssl';
        $this->logger->debug('RelayConsumer::openHubConnection() AsyncTcpConnection created', [
            'connection_class' => get_class($connection),
            'connection_id' => spl_object_id($connection),
            'connection_status' => $connection->getStatus(),
        ]);

        return $connection;
    }

    /**
     * Send the JSON HELLO handshake as the first WS message.
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function sendHello(string $enrollmentJwt): void
    {
        if ($this->connection === null) {
            return;
        }

        $hello = $this->codec->encodeHello($enrollmentJwt, $this->serverId);
        $this->connection->send($hello);
    }

    /**
     * Handle an incoming message from the hub WS connection.
     *
     * Before the handshake completes the message is the JSON HELLO_ACK; after
     * that all messages are binary multiplexer frames.
     *
     * @param string $data Raw message bytes.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onHubMessage(string $data): void
    {
        if ($this->state === self::STATE_HANDSHAKING) {
            $this->handleHelloAck($data);
            return;
        }

        if ($this->state !== self::STATE_ACTIVE) {
            return;
        }

        $this->recvBuffer .= $data;
        $this->drainFrames();
    }

    /**
     * Parse the JSON HELLO_ACK and transition to binary frame mode.
     *
     * @param string $data JSON text reply from the hub.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function handleHelloAck(string $data): void
    {
        try {
            /** @var array<string, mixed>|null $ack */
            $ack = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->error('RelayConsumer: malformed HELLO_ACK; closing', [
                'error' => $e->getMessage(),
            ]);
            $this->closeTunnel();
            return;
        }

        if (!is_array($ack) || ($ack['type'] ?? null) !== 'hello_ack') {
            $this->logger->error('RelayConsumer: unexpected HELLO_ACK payload; closing');
            $this->closeTunnel();
            return;
        }

        $this->state = self::STATE_ACTIVE;
        $this->reconnectAttempts = 0;
        $this->sessionStartTime = hrtime(true);
        $this->relaySessionId = is_string($ack['relay_session_id'] ?? null) ? $ack['relay_session_id'] : null;
        $this->sessionEndLogged = false;

        $this->logger->info('RelayConsumer: tunnel active', [
            'relay_session_id' => $this->relaySessionId,
            'tunnel_id' => is_string($ack['tunnel_id'] ?? null) ? $ack['tunnel_id'] : null,
        ]);

        $this->startHeartbeatTimer();
    }

    /**
     * Drain all complete binary frames from the receive buffer.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function drainFrames(): void
    {
        while (true) {
            $frame = $this->codec->decode($this->recvBuffer);
            if ($frame === null) {
                break;
            }

            // The shared decode() is stateless and does not consume bytes, so
            // advance the buffer manually by the frame's wire length (7-byte
            // header + payload). If somehow there are not enough bytes, stop.
            $frameLen = 7 + strlen($frame->payload);
            if (strlen($this->recvBuffer) < $frameLen) {
                break;
            }
            $this->recvBuffer = substr($this->recvBuffer, $frameLen);

            $this->dispatchFrame($frame);
        }
    }

    /**
     * Dispatch a decoded binary frame from the hub.
     *
     * @param RelayFrame $frame Decoded frame.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function dispatchFrame(RelayFrame $frame): void
    {
        match ($frame->type) {
            RelayFrameType::CLIENT_CONNECT => $this->onClientConnect($frame),
            RelayFrameType::CLIENT_DISCONNECT => $this->onClientDisconnect($frame),
            RelayFrameType::DATA => $this->onData($frame),
            RelayFrameType::HTTP_REQUEST => $this->onHttpRequest($frame),
            RelayFrameType::HTTP_CANCEL => $this->onHttpCancel($frame),
            RelayFrameType::HEARTBEAT => $this->onHeartbeat(),
            RelayFrameType::DISCONNECTED => $this->onDisconnectedFrame($frame),
            RelayFrameType::ERROR => $this->onErrorFrame($frame),
            default => $this->logger->warning('RelayConsumer: unexpected frame type', [
                'type' => $frame->type->label(),
                'seq' => $frame->seq,
            ]),
        };
    }

    /**
     * Handle a CLIENT_CONNECT frame: open a local HTTP connection for the client.
     *
     * The frame's channel id ({@see RelayFrame::channelId()}) is the routing key
     * for this client's subsequent DATA frames; the JSON {client_id, session_id}
     * payload is observability only.
     *
     * @param RelayFrame $frame CLIENT_CONNECT frame; channel id in seq, payload {client_id, session_id}.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onClientConnect(RelayFrame $frame): void
    {
        $channelId = $frame->channelId();
        $payload = $this->decodeJsonPayload($frame->payload);
        $clientId = is_string($payload['client_id'] ?? null) ? $payload['client_id'] : '';

        if ($channelId <= 0) {
            $this->logger->warning('RelayConsumer: CLIENT_CONNECT with invalid channel', [
                'channel_id' => $channelId,
                'client_id' => $clientId,
            ]);
            return;
        }

        if (isset($this->localConnections[$channelId])) {
            // Already connected — ignore duplicate.
            return;
        }

        $localUrl = $this->config->buildLocalHttpUrl();
        $local = $this->openLocalConnection($localUrl);

        $local->onMessage = function (ConnectionInterface $conn, string $data) use ($channelId): void {
            $this->onLocalData($channelId, $data);
        };

        $local->onClose = function (ConnectionInterface $conn) use ($channelId): void {
            $this->onLocalClose($channelId);
        };

        $errorContext = ['channel_id' => $channelId, 'client_id' => $clientId];
        $local->onError = function (ConnectionInterface $conn, int $code, string $msg) use ($errorContext): void {
            $this->logger->warning('RelayConsumer: local connection error', $errorContext + [
                'code' => $code,
                'message' => $msg,
            ]);
        };

        $this->localConnections[$channelId] = $local;

        $local->connect();

        $this->logger->info('RelayConsumer: client connected', [
            'channel_id' => $channelId,
            'client_id' => $clientId,
            'local_url' => $localUrl,
        ]);
    }

    /**
     * Handle a CLIENT_DISCONNECT frame: close + forget the channel's local conn.
     *
     * @param RelayFrame $frame CLIENT_DISCONNECT frame; channel id in seq, payload {client_id}.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onClientDisconnect(RelayFrame $frame): void
    {
        $channelId = $frame->channelId();
        $payload = $this->decodeJsonPayload($frame->payload);
        $clientId = is_string($payload['client_id'] ?? null) ? $payload['client_id'] : '';

        if ($channelId <= 0) {
            return;
        }

        $this->closeLocalConnection($channelId);

        $this->logger->info('RelayConsumer: client disconnected', [
            'channel_id' => $channelId,
            'client_id' => $clientId,
        ]);
    }

    /**
     * Handle a DATA frame from the hub: pipe raw bytes to the channel's local conn.
     *
     * The frame's channel id ({@see RelayFrame::channelId()}) selects exactly one
     * local connection. A DATA frame for an unknown/closed channel is dropped and
     * logged — this keeps concurrent clients isolated.
     *
     * @param RelayFrame $frame DATA frame; channel id in seq, payload raw client bytes.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onData(RelayFrame $frame): void
    {
        $channelId = $frame->channelId();
        $local = $this->localConnections[$channelId] ?? null;
        if ($local === null) {
            $this->logger->warning('RelayConsumer: DATA for unknown/closed channel, dropping', [
                'channel_id' => $channelId,
                'payload_len' => strlen($frame->payload),
            ]);
            return;
        }

        if ($local->send($frame->payload, true) === false) {
            // Local connection send buffer is full — apply back-pressure to the
            // hub so it stops pipelining DATA frames for this channel until the
            // local connection drains.  This mirrors the ConnectionResponseSink
            // discipline used on the hub side.
            if ($this->connection !== null && $this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                $this->connection->pauseRecv();
                $local->onBufferDrain = function () use ($channelId): void {
                    // Clean up the drain handler first to avoid double-resume.
                    $conn = $this->localConnections[$channelId] ?? null;
                    if ($conn !== null) {
                        $conn->onBufferDrain = null;
                    }
                    if (
                        $this->connection !== null
                        && $this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED
                    ) {
                        $this->connection->resumeRecv();
                    }
                };
            }
        }
    }

    /**
     * Handle an HTTP_REQUEST frame: dispatch it through the local app router
     * and stream the response back as HTTP_RESPONSE frames on the same id.
     *
     * The frame's `seq` field carries the hub-allocated per-request id; every
     * HTTP_RESPONSE frame for this request echoes it so the hub correlates the
     * HEAD/BODY/END chunks back to the originating browser request.
     *
     * Trust model: the request arrived over the authenticated tunnel from the
     * hub, which has already validated the end user and verified they own this
     * server. The synthetic request is therefore run as the forwarded hub user
     * (`X-Phlix-Relay-User`) so the server's auth gates pass; binary media
     * streaming + signed URLs remain out of scope for this phase.
     *
     * @param RelayFrame $frame HTTP_REQUEST frame; request id in seq, payload = RelayHttpRequest JSON.
     *
     * @return void
     *
      * @since 0.10.0
      */
    private function onHttpRequest(RelayFrame $frame): void
    {
        $requestId = $frame->channelId();

        if ($this->httpDispatcher === null) {
            // Drop any partial assembly for this id; we cannot service it.
            $this->discardRequestAccumulator($requestId);
            $this->logger->warning('RelayConsumer: HTTP_REQUEST received but no dispatcher configured', [
                'request_id' => $requestId,
            ]);
            $this->sendHttpError($requestId, 503, 'relay proxy not available on this server');
            return;
        }

        $payload = $frame->payload;

        // Chunked request framing (HB-2.1): a payload whose first byte is a
        // RelayHttpRequestCodec tag (HEAD 0x01 / BODY 0x02 / END 0x03) is one
        // chunk of a multi-frame request sharing this request id. The legacy
        // single-frame JSON envelope always begins with '{' (0x7B), which never
        // collides with the tag bytes, so the first byte unambiguously selects
        // the path. An empty payload falls through to the legacy branch, which
        // rejects it as malformed exactly as before (back-compat preserved).
        if ($payload !== '' && $this->isChunkedRequestFrame($payload)) {
            $this->onHttpRequestChunk($requestId, $payload);
            return;
        }

        // Legacy single-frame path (small/empty body) — behaviour unchanged.
        try {
            $envelope = RelayHttpRequest::fromJson($payload);
        } catch (Throwable $e) {
            $this->logger->warning('RelayConsumer: malformed HTTP_REQUEST envelope', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            $this->sendHttpError($requestId, 400, 'malformed relay request');
            return;
        }

        $this->dispatchEnvelope($requestId, $envelope);
    }

    /**
     * Whether an HTTP_REQUEST frame payload is a chunked-request tag-byte frame
     * ({@see RelayHttpRequestCodec} HEAD/BODY/END) rather than a legacy
     * single-frame {@see RelayHttpRequest} JSON envelope.
     *
     * The caller guarantees `$payload !== ''`.
     *
     * @param string $payload Non-empty HTTP_REQUEST frame payload.
     *
     * @return bool True when the first byte is a codec tag byte.
     *
     * @since 0.19.0
     */
    private function isChunkedRequestFrame(string $payload): bool
    {
        $tag = ord($payload[0]);

        return $tag === RelayHttpRequestCodec::TAG_HEAD
            || $tag === RelayHttpRequestCodec::TAG_BODY
            || $tag === RelayHttpRequestCodec::TAG_END;
    }

    /**
     * Accumulate one chunk of a multi-frame relayed request and, on END,
     * reassemble + dispatch the full {@see RelayHttpRequest} (HB-2.1).
     *
     * Mirrors the hub's response-side reassembly: a per-request-id accumulator
     * carries the HEAD (method/path/headers) and the concatenated raw BODY
     * bytes; END finalizes it. The accumulated body is capped
     * ({@see self::MAX_REASSEMBLED_REQUEST_BODY}) and the accumulator is dropped
     * on overflow, duplicate HEAD, or END/BODY-without-HEAD so the resident
     * worker cannot be driven to grow memory without bound.
     *
     * @param int    $requestId Hub-allocated request id (shared by all chunks).
     * @param string $payload   Non-empty tag-byte chunk payload.
     *
     * @return void
     *
     * @since 0.19.0
     */
    private function onHttpRequestChunk(int $requestId, string $payload): void
    {
        try {
            $chunk = RelayHttpRequestCodec::decode($payload);
        } catch (Throwable $e) {
            $this->logger->warning('RelayConsumer: malformed HTTP_REQUEST chunk', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            $this->discardRequestAccumulator($requestId);
            $this->sendHttpError($requestId, 400, 'malformed relay request chunk');
            return;
        }

        switch ($chunk->kind) {
            case RelayHttpRequestChunk::KIND_HEAD:
                if (isset($this->requestAccumulators[$requestId])) {
                    $this->logger->warning('RelayConsumer: duplicate HTTP_REQUEST HEAD chunk', [
                        'request_id' => $requestId,
                    ]);
                    $this->discardRequestAccumulator($requestId);
                    $this->sendHttpError($requestId, 400, 'duplicate relay request head');
                    return;
                }

                if (count($this->requestAccumulators) >= self::MAX_CONCURRENT_REQUEST_ASSEMBLIES) {
                    $this->logger->warning('RelayConsumer: too many concurrent chunked requests', [
                        'request_id' => $requestId,
                        'in_flight' => count($this->requestAccumulators),
                    ]);
                    $this->sendHttpError($requestId, 503, 'too many concurrent relay requests');
                    return;
                }

                // $chunk->head is guaranteed non-null for a HEAD chunk (codec contract).
                /** @var RelayHttpRequestHead $head */
                $head = $chunk->head;
                $this->requestAccumulators[$requestId] = [
                    'head' => $head,
                    'body' => '',
                    'size' => 0,
                ];
                return;

            case RelayHttpRequestChunk::KIND_BODY:
                if (!isset($this->requestAccumulators[$requestId])) {
                    $this->logger->warning('RelayConsumer: HTTP_REQUEST BODY chunk before HEAD', [
                        'request_id' => $requestId,
                    ]);
                    $this->sendHttpError($requestId, 400, 'relay request body before head');
                    return;
                }

                $newSize = $this->requestAccumulators[$requestId]['size'] + strlen($chunk->body);
                if ($newSize > self::MAX_REASSEMBLED_REQUEST_BODY) {
                    $this->logger->warning('RelayConsumer: reassembled request body exceeds cap', [
                        'request_id' => $requestId,
                        'size' => $newSize,
                        'cap' => self::MAX_REASSEMBLED_REQUEST_BODY,
                    ]);
                    $this->discardRequestAccumulator($requestId);
                    $this->sendHttpError($requestId, 413, 'relay request body too large');
                    return;
                }

                $this->requestAccumulators[$requestId]['body'] .= $chunk->body;
                $this->requestAccumulators[$requestId]['size'] = $newSize;
                return;

            case RelayHttpRequestChunk::KIND_END:
                if (!isset($this->requestAccumulators[$requestId])) {
                    $this->logger->warning('RelayConsumer: HTTP_REQUEST END chunk before HEAD', [
                        'request_id' => $requestId,
                    ]);
                    $this->sendHttpError($requestId, 400, 'relay request end before head');
                    return;
                }

                $acc = $this->requestAccumulators[$requestId];
                // Finalize: remove the accumulator before dispatch so a slow
                // dispatch cannot pin it and a late duplicate END is a no-op.
                unset($this->requestAccumulators[$requestId]);

                try {
                    $head = $acc['head'];
                    $envelope = new RelayHttpRequest(
                        $head->method,
                        $head->path,
                        $head->query,
                        $head->headers,
                        $acc['body'],
                    );
                    // Inherit the same method/path safety gate fromJson applies.
                    $envelope->assertSafe();
                } catch (Throwable $e) {
                    $this->logger->warning('RelayConsumer: invalid reassembled relay request', [
                        'request_id' => $requestId,
                        'error' => $e->getMessage(),
                    ]);
                    $this->sendHttpError($requestId, 400, 'malformed relay request');
                    return;
                }

                $this->dispatchEnvelope($requestId, $envelope);
                return;
        }
    }

    /**
     * Discard any in-flight chunked-request assembly for a request id.
     *
     * @param int $requestId Relay request id.
     *
     * @return void
     *
     * @since 0.19.0
     */
    private function discardRequestAccumulator(int $requestId): void
    {
        unset($this->requestAccumulators[$requestId]);
    }

    /**
     * Dispatch a fully-decoded request envelope through the local app and send
     * the response back over the tunnel.
     *
     * Shared tail of both the legacy single-frame path and the chunked
     * reassembly path.
     *
     * @param int             $requestId Relay request id.
     * @param RelayHttpRequest $envelope  Decoded request envelope.
     *
     * @return void
     *
     * @since 0.19.0
     */
    private function dispatchEnvelope(int $requestId, RelayHttpRequest $envelope): void
    {
        $response = $this->dispatchWithDeadline($requestId, $envelope);

        if ($response === null) {
            // Deadline expired — error was already sent by dispatchWithDeadline.
            return;
        }

        $this->sendHttpResponse($requestId, $response);

        $fileLen = $response->filePath !== null
            ? $this->computeBodyLength($response)
            : strlen($response->body);

        $this->logger->info('RelayConsumer: served proxied HTTP request', [
            'request_id' => $requestId,
            'method' => $envelope->method,
            'path' => $envelope->path,
            'status' => $response->statusCode,
            'body_len' => $fileLen,
        ]);
    }

    /**
     * Dispatch a relayed HTTP request with a per-request deadline.
     *
     * Wraps the dispatcher call in a Swoole coroutine with a deadline timer.
     * If the deadline expires before the dispatch completes, a 504 error is
     * sent and null is returned — preventing one slow request from stalling the
     * relay worker.
     *
     * Falls back to synchronous dispatch when Swoole coroutines are unavailable
     * (e.g. in PHPUnit CLI without the swoole extension), though in that case
     * no deadline enforcement occurs.
     *
     * @param int             $requestId Hub-allocated request id (for error logging).
     * @param RelayHttpRequest $envelope  Decoded request envelope.
     *
     * @return ServerResponse|null The response on success, or null on timeout.
     *
     * @since 0.10.0
     */
    private function dispatchWithDeadline(int $requestId, RelayHttpRequest $envelope): ?ServerResponse
    {
        // SV-4.2 / X1: publish the hub channel/request id as the relay cancel
        // group for the duration of this dispatch, so any on-demand segment
        // encode launched by the dispatcher (in THIS process) is registered under
        // it — letting onHttpCancel($requestId) kill the encode by channel id.
        // Cleared in a finally so it never leaks into the next request handled on
        // the same coroutine.
        RequestContext::setRelayCancelGroup((string) $requestId);
        try {
            return $this->dispatchWithDeadlineInner($requestId, $envelope);
        } finally {
            RequestContext::clearRelayCancelGroup();
        }
    }

    /**
     * The deadline-enforcing dispatch body (SV-4.2 extracted the relay-cancel-group
     * bracketing into {@see dispatchWithDeadline()}).
     *
     * @param int              $requestId Hub-allocated request id (for error logging).
     * @param RelayHttpRequest $envelope  Decoded request envelope.
     *
     * @return ServerResponse|null The response on success, or null on timeout.
     */
    private function dispatchWithDeadlineInner(int $requestId, RelayHttpRequest $envelope): ?ServerResponse
    {
        $deadline = self::DISPATCH_DEADLINE_SECONDS;
        $request = $this->buildRequest($envelope);

        // Fast path: when coroutines are available, enforce the deadline.
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            /** @var ServerResponse|null $result */
            $result = null;
            $dispatched = false;

            // Timer fires if dispatch takes longer than the deadline.
            // The callback sets a sentinel 504 response if the timer fires before
            // the dispatch completes (detected via $dispatched being still false).
            $timer = Timer::add($deadline, static function () use (&$result, &$dispatched): void {
                // @phpstan-ignore-next-line booleanNot.alwaysTrue
                if (!$dispatched) {
                    $result = (new ServerResponse())
                        ->status(504)
                        ->header('Content-Type', 'text/plain; charset=utf-8')
                        ->text('relay request timed out');
                }
            }, [], false);

            try {
                // $this->httpDispatcher is guaranteed non-null here because
                // onHttpRequest returns early when it is null.
                /** @var callable(ServerRequest): ServerResponse $dispatcher */
                $dispatcher = $this->httpDispatcher;
                $result = $dispatcher($request);
                $dispatched = true;
            } catch (Throwable $e) {
                $dispatched = true;
                $this->logger->error('RelayConsumer: HTTP_REQUEST dispatch failed', [
                    'request_id' => $requestId,
                    'path' => $envelope->path,
                    'error' => $e->getMessage(),
                ]);
                $result = (new ServerResponse())
                    ->status(500)
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->text('relay dispatch error');
            } finally {
                Timer::del($timer);
            }

            if ($result !== null && $result->statusCode === 504) {
                $this->sendHttpError($requestId, 504, 'relay request timed out');
                return null;
            }

            return $result;
        }

        // Synchronous fallback (no Swoole coroutines available).
        try {
            /** @var callable(ServerRequest): ServerResponse $dispatcher */
            $dispatcher = $this->httpDispatcher;
            return $dispatcher($request);
        } catch (Throwable $e) {
            $this->logger->error('RelayConsumer: HTTP_REQUEST dispatch failed', [
                'request_id' => $requestId,
                'path' => $envelope->path,
                'error' => $e->getMessage(),
            ]);
            $this->sendHttpError($requestId, 500, 'relay dispatch error');
            return null;
        }
    }

    /**
     * Build a synthetic {@see ServerRequest} from a relayed request envelope.
     *
     * @param RelayHttpRequest $envelope Decoded request envelope.
     *
     * @return ServerRequest
     *
     * @since 0.10.0
     */
    private function buildRequest(RelayHttpRequest $envelope): ServerRequest
    {
        // SECURITY: never forward the relay producer's trust-bearing headers.
        // withoutForbiddenHeaders() drops x-phlix-relay-user, x-forwarded-for,
        // authorization and cookie (the shared DTO's documented denylist) so a
        // relayed request cannot smuggle its own Authorization/Cookie to confuse
        // AuthMiddleware, nor spoof identity/client-IP. We deliberately read
        // identity/IP from the RAW envelope below and INJECT them ourselves,
        // ensuring those values never survive as forwardable headers.
        $safeEnvelope = $envelope->withoutForbiddenHeaders();

        $request = new ServerRequest();
        $request->method = $envelope->method;
        $request->path = $envelope->path;
        $request->queryString = $envelope->query;
        $request->headers = $safeEnvelope->headers;
        $request->rawBody = $envelope->body;

        if ($envelope->query !== '') {
            $parsedQuery = [];
            parse_str($envelope->query, $parsedQuery);
            $request->query = $this->stringKeyed($parsedQuery);
        }

        $contentType = $this->headerValue($safeEnvelope->headers, 'content-type');
        if ($envelope->body !== '' && stripos($contentType, 'application/json') !== false) {
            /** @var mixed $decodedBody */
            $decodedBody = json_decode($envelope->body, true);
            if (is_array($decodedBody)) {
                $request->body = $this->stringKeyed($decodedBody);
            }
        } elseif ($envelope->body !== '' && stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $parsedBody = [];
            parse_str($envelope->body, $parsedBody);
            $request->body = $this->stringKeyed($parsedBody);
        }

        // Identity injection: we trust the tunnel, NOT the producer. The hub
        // authenticates the WS relay session and stamps the validated owner on
        // the inbound x-phlix-relay-user header (and strips any client-supplied
        // copy on the way in), so reading it from the RAW envelope here is the
        // one legitimate trust basis. We apply it as $request->userId so auth
        // gates pass, but because that header is in STRIPPED_HEADERS it was
        // already removed from $request->headers above — it can never be
        // re-forwarded downstream. Absent → 'hub-relay' fallback as before.
        $relayUser = $this->headerValue($envelope->headers, 'x-phlix-relay-user');
        $request->userId = $relayUser !== '' ? $relayUser : 'hub-relay';

        // Client IP comes from the relay session, never from the producer-
        // suppliable x-forwarded-for header (now stripped): an untrusted relay
        // producer could otherwise spoof its source IP to bypass rate-limits or
        // IP allowlists. All relayed traffic egresses from this server's own
        // loopback HTTP listener, so the loopback marker is the truthful origin.
        $request->remoteIp = self::RELAY_REMOTE_IP;

        return $request;
    }

    /**
     * Coerce an array to string keys so it satisfies the Request's
     * array<string, mixed> query/body property types.
     *
     * @param array<array-key, mixed> $input Source array (e.g. from parse_str / json_decode).
     *
     * @return array<string, mixed>
     *
     * @since 0.10.0
     */
    private function stringKeyed(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Look up a header value case-insensitively.
     *
     * @param array<string, string> $headers Header map.
     * @param string                 $name    Lower-case header name to find.
     *
     * @return string The value, or '' when absent.
     *
     * @since 0.10.0
     */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Stream a full HTTP response back to the hub as HTTP_RESPONSE frames.
     *
     * Emits one HEAD chunk (status + headers + total body length), then zero or
     * more BODY chunks (each <= {@see RelayHttpResponseCodec::MAX_BODY_CHUNK}),
     * then a terminating END chunk — all tagged with the request id.
     *
     * For file-backed responses ({@see ServerResponse::$filePath} !== null) the
     * file is streamed directly from disk in {@see ServerResponse::$fileOffset} +
     * {@see ServerResponse::$fileLength} bounds, chunked to respect the tunnel
     * frame cap, without buffering the whole file in memory.
     *
     * @param int            $requestId Hub-allocated request id (frame seq).
     * @param ServerResponse $response  Full response object.
     *
     * @return void
     *
     * @since 0.10.0
     */
    private function sendHttpResponse(int $requestId, ServerResponse $response): void
    {
        $headers = $response->headers;
        $bodyLength = $this->computeBodyLength($response);

        $head = new RelayHttpResponseHead($response->statusCode, $headers, $bodyLength);
        $this->sendHttpResponseFrame($requestId, RelayHttpResponseCodec::encodeHead($head));

        if ($response->filePath !== null && !$response->headOnly) {
            $this->streamFileChunks($requestId, $response);
        } else {
            foreach (RelayHttpResponseCodec::chunkBody($response->body) as $chunkPayload) {
                $this->sendHttpResponseFrame($requestId, $chunkPayload);
            }
        }

        $this->sendHttpResponseFrame($requestId, RelayHttpResponseCodec::encodeEnd());

        // P8: After the complete response (HEAD + BODY + END) is sent to the hub,
        // send an HTTP_CANCEL frame to notify the hub that the response is done and
        // it can clean up its tracking state for this request.
        $this->sendCancel($requestId);
    }

    /**
     * Compute the total body length for a response.
     *
     * For file-backed responses this is the file slice size; for buffered
     * responses it is the byte length of the body string.
     *
     * @param ServerResponse $response The response to compute length for.
     *
     * @return int|null Body length in bytes, or null if unknown (streaming).
     *
     * @since 0.10.0
     */
    private function computeBodyLength(ServerResponse $response): ?int
    {
        if ($response->filePath !== null) {
            $fileSize = (int) @filesize($response->filePath);
            if ($fileSize <= 0) {
                return null;
            }
            return $response->fileLength > 0
                ? $response->fileLength
                : $fileSize - $response->fileOffset;
        }

        return strlen($response->body);
    }

    /**
     * Stream a file-backed response in chunks over the tunnel.
     *
     * Opens the file, seeks to {@see ServerResponse::$fileOffset}, then reads
     * and transmits chunks of at most {@see RelayHttpResponseCodec::MAX_BODY_CHUNK}
     * bytes until {@see ServerResponse::$fileLength} bytes have been sent (or EOF
     * if length is 0 / unbounded).
     *
     * @param int            $requestId Hub-allocated request id (frame seq).
     * @param ServerResponse $response  File-backed response to stream.
     *
     * @return void
     *
     * @since 0.10.0
     */
    private function streamFileChunks(int $requestId, ServerResponse $response): void
    {
        $path = $response->filePath;
        if ($path === null) {
            return;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        if ($response->fileOffset > 0) {
            fseek($handle, $response->fileOffset);
        }

        $remaining = $response->fileLength > 0 ? $response->fileLength : PHP_INT_MAX;
        $maxChunk = RelayHttpResponseCodec::MAX_BODY_CHUNK;
        $local = $this->localConnections[$requestId] ?? null;

        while ($remaining > 0 && !feof($handle)) {
            $readLen = (int) min($maxChunk, $remaining);
            $chunk = fread($handle, $readLen);
            if ($chunk === false || $chunk === '') {
                break;
            }

            if (
                $this->sendHttpResponseFrame(
                    $requestId,
                    RelayHttpResponseCodec::encodeBody($chunk),
                ) === false
            ) {
                // Hub connection send buffer is full — stop reading from the file
                // and apply back-pressure to the local HTTP client until the hub
                // drain event fires so a slow hub does not make us buffer the
                // entire file in memory.
                if ($local !== null) {
                    $local->pauseRecv();
                    $local->onBufferDrain = static function () use ($local): void {
                        $local->onBufferDrain = null;
                        $local->resumeRecv();
                    };
                }
                break;
            }
            $remaining -= strlen($chunk);
        }

        fclose($handle);
    }

    /**
     * Send a minimal plain-text HTTP error response over the tunnel.
     *
     * @param int    $requestId Hub-allocated request id.
     * @param int    $status    HTTP status code.
     * @param string $message   Plain-text body.
     *
     * @return void
     *
     * @since 0.10.0
     */
    private function sendHttpError(int $requestId, int $status, string $message): void
    {
        $errorResponse = (new ServerResponse())
            ->status($status)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->text($message);

        $this->sendHttpResponse($requestId, $errorResponse);
    }

    /**
     * Encode and send one HTTP_RESPONSE frame tagged with the request id.
     *
     * @param int    $requestId Hub-allocated request id (carried in the seq field).
     * @param string $payload   Chunk payload (HEAD/BODY/END) from RelayHttpResponseCodec.
     *
     * @return bool True if the frame was sent, false if the hub connection could
     *              not accept it (e.g. send buffer full).
     *
     * @since 0.10.0
     */
    private function sendHttpResponseFrame(int $requestId, string $payload): bool
    {
        if ($this->connection === null || $this->state !== self::STATE_ACTIVE) {
            return false;
        }

        $encoded = $this->codec->encode(RelayFrameType::HTTP_RESPONSE, $requestId, $payload);
        return $this->connection->send($encoded) !== false;
    }

    /**
     * Send an HTTP_CANCEL frame to the hub for a given relay request id.
     *
     * Issued when the server needs to signal that a request has been cancelled
     * locally (e.g., the resource was deleted mid-request). The request id is
     * carried in the frame's seq field.
     *
     * @param int $requestId The relay request id to cancel.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function sendCancel(int $requestId): void
    {
        if ($this->connection === null || $this->state !== self::STATE_ACTIVE) {
            return;
        }

        $encoded = $this->codec->encode(RelayFrameType::HTTP_CANCEL, $requestId, '');
        $this->connection->send($encoded);
    }

    /**
     * Handle a HEARTBEAT frame from the hub by replying with a HEARTBEAT.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onHeartbeat(): void
    {
        $this->sendFrame(RelayFrameType::HEARTBEAT, '');
    }

    /**
     * Handle an HTTP_CANCEL frame from the hub.
     *
     * The hub sends this when the browser abandons a streaming request so the
     * server can stop transferring bytes for that request early. The request id
     * is carried in the frame's seq field.
     *
     * This closes the local connection for the associated channel, which
     * interrupts any in-progress HTTP response streaming. Safe to call even
     * if the request already completed — closeLocalConnection is a no-op for
     * unknown or already-closed channels.
     *
     * @param RelayFrame $frame HTTP_CANCEL frame; request id in seq.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function onHttpCancel(RelayFrame $frame): void
    {
        $channelId = $frame->channelId();

        $this->logger->debug('RelayConsumer: HTTP_CANCEL received from hub', [
            'request_id' => $channelId,
        ]);

        // Drop any partial chunked-request assembly for this id so a cancelled
        // request in mid-upload cannot leave a dangling accumulator.
        $this->discardRequestAccumulator($channelId);

        // SV-4.2 ([S-F23], X1 server half): kill any on-demand ffmpeg encode this
        // relayed request launched so an abandoned scrub-storm segment stops
        // burning CPU instead of running to completion. The encode is tracked in
        // the registry by its segment path but grouped under this channel/request
        // id (the group is published into RequestContext during dispatch — see
        // dispatchWithDeadline), so a group kill finds it by channel id. A no-op
        // when nothing is tracked for this channel. Since SV-4.2-disconnect the
        // kill is WAITER-AWARE: if a second client is still piggybacked on the same
        // shared encode, killGroup DEFERS that key (leaving it running for the
        // remaining waiter) rather than killing it immediately — so this is not an
        // unconditional immediate reap. On a genuine reap it also invalidates the
        // manager's dedup reservation (F1) so the next requester re-launches.
        $killed = $this->segmentRegistry?->killGroup((string) $channelId) ?? 0;
        if ($killed > 0) {
            $this->logger->info('RelayConsumer: killed abandoned encode(s) on HTTP_CANCEL', [
                'request_id' => $channelId,
                'killed' => $killed,
            ]);
        }

        // Close the local connection to abort any in-progress streaming response.
        // The requestId in the frame IS the channelId (set by the hub at
        // CLIENT_CONNECT time and echoed in every client-scoped frame).
        $this->closeLocalConnection($channelId);
    }

    /**
     * Handle a DISCONNECTED frame from the hub.
     *
     * @param RelayFrame $frame DISCONNECTED frame.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onDisconnectedFrame(RelayFrame $frame): void
    {
        $payload = $this->decodeJsonPayload($frame->payload);
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : 'unknown';
        $this->logger->info('RelayConsumer: hub sent DISCONNECTED', [
            'reason' => $reason,
        ]);
        $this->logSessionEnd($reason);
        $this->closeTunnel();
    }

    /**
     * Handle an ERROR frame from the hub.
     *
     * @param RelayFrame $frame ERROR frame.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onErrorFrame(RelayFrame $frame): void
    {
        $payload = $this->decodeJsonPayload($frame->payload);
        $this->logger->error('RelayConsumer: hub sent ERROR', [
            'code' => $payload['code'] ?? null,
            'message' => is_string($payload['message'] ?? null) ? $payload['message'] : null,
        ]);
    }

    /**
     * Handle response bytes emitted by a client's local connection.
     *
     * Wraps them into DATA frames (chunked to <= 65535), each tagged with the
     * owning channel id so the hub routes them back to the correct client, and
     * sends to the hub.
     *
     * A zero-length read never emits a frame (SV-2.3, [S-F36]): the loop
     * condition is checked BEFORE building the first chunk, so an empty
     * `$data` (e.g. a spurious zero-byte onMessage) is a pure no-op instead of
     * relaying a meaningless empty DATA frame to the hub.
     *
     * @param int    $channelId Owning channel id.
     * @param string $data      Raw response bytes from the local listener.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onLocalData(int $channelId, string $data): void
    {
        if (!isset($this->localConnections[$channelId])) {
            return;
        }

        $offset = 0;
        $length = strlen($data);
        $maxChunk = 65535;

        while ($offset < $length) {
            $chunk = substr($data, $offset, $maxChunk);
            if (!$this->sendDataFrame($channelId, $chunk)) {
                // The hub tunnel's send buffer is full — sendDataFrame() has
                // already paused this channel's local connection and armed
                // the drain-resume handler. Stop feeding it more chunks from
                // this already-read buffer rather than repeatedly dropping
                // payloads into an over-full buffer (mirrors the
                // check-return-then-break discipline streamFileChunks() uses
                // for the HTTP_RESPONSE file-streaming path).
                break;
            }
            $offset += $maxChunk;
        }
    }

    /**
     * Handle a local connection close: forget it.
     *
     * @param int $channelId Owning channel id.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onLocalClose(int $channelId): void
    {
        if (isset($this->localConnections[$channelId])) {
            unset($this->localConnections[$channelId]);
            unset($this->pausedForTunnelDrain[$channelId]);
            $this->logger->info('RelayConsumer: local connection closed', [
                'channel_id' => $channelId,
            ]);
        }
    }

    /**
     * Open a local HTTP connection (overridable for tests).
     *
     * @param string $localUrl Workerman tcp:// address.
     *
     * @return AsyncTcpConnection
     *
     * @since 0.5.0
     */
    private function openLocalConnection(string $localUrl): AsyncTcpConnection
    {
        if ($this->localConnectionFactory !== null) {
            return ($this->localConnectionFactory)($localUrl);
        }

        return new AsyncTcpConnection($localUrl);
    }

    /**
     * Close and forget a single channel's local connection.
     *
     * @param int $channelId Owning channel id.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function closeLocalConnection(int $channelId): void
    {
        $conn = $this->localConnections[$channelId] ?? null;
        if ($conn === null) {
            return;
        }

        unset($this->localConnections[$channelId]);
        unset($this->pausedForTunnelDrain[$channelId]);
        $conn->close();
    }

    /**
     * Close and forget all local connections.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function closeAllLocalConnections(): void
    {
        foreach ($this->localConnections as $conn) {
            $conn->close();
        }
        $this->localConnections = [];
        $this->pausedForTunnelDrain = [];
    }

    /**
     * Encode and send a tunnel-scoped binary frame (channel 0) to the hub.
     *
     * Used for HEARTBEAT and other non-client-scoped frames. Client-scoped DATA
     * uses {@see sendDataFrame()} so the channel id is preserved.
     *
     * Tunnel-scoped frames are not tied to any single channel, so unlike
     * {@see sendDataFrame()} there is no single local connection to pause when
     * the tunnel's send buffer is full — the shared tunnel being backed up is
     * already surfaced (and backpressured) via whichever channel(s) are
     * actively relaying DATA through {@see sendDataFrame()}. This method still
     * checks the return value (SV-2.3, [S-F36]) so a dropped tunnel-scoped
     * frame (e.g. a HEARTBEAT) is observable rather than silently ignored.
     *
     * @param RelayFrameType $type    Frame type.
     * @param string         $payload Raw payload bytes (<= 65535).
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function sendFrame(RelayFrameType $type, string $payload): void
    {
        if ($this->connection === null || $this->state !== self::STATE_ACTIVE) {
            return;
        }

        // Tunnel-scoped frames carry no channel — channel id 0.
        $encoded = $this->codec->encode($type, 0, $payload);
        if ($this->connection->send($encoded) === false) {
            $this->logger->warning('RelayConsumer: tunnel-scoped frame dropped, send buffer full', [
                'type' => $type->label(),
            ]);
        }
    }

    /**
     * Encode and send a DATA frame tagged with the owning channel id.
     *
     * The channel id travels in the frame's `seq` field so the hub routes the
     * response back to the correct client.
     *
     * When the hub tunnel's send buffer is full (SV-2.3, [S-F36]), this
     * applies back-pressure to the LOCAL connection that produced the bytes
     * (the source, on the opposite side of the pipe from the destination
     * that's full) by pausing its recv until the tunnel drains — mirroring
     * the {@see onData()} discipline used for the opposite (hub->local)
     * direction. Because the tunnel connection is shared by every
     * multiplexed channel (unlike each channel's own dedicated local
     * connection), the paused channel is tracked in
     * {@see $pausedForTunnelDrain} and resumed via {@see armTunnelDrainResume()}
     * rather than registering a callback directly on the tunnel per call,
     * which would clobber any other channel's pending resume.
     *
     * @param int    $channelId Owning channel id.
     * @param string $payload   Raw payload bytes (<= 65535).
     *
     * @return bool True if the frame was sent; false if it was dropped
     *              because there is no active tunnel, or its send buffer was
     *              full (in which case the channel's local connection has
     *              been paused and will resume once the tunnel drains).
     *
     * @since 0.5.0
     */
    private function sendDataFrame(int $channelId, string $payload): bool
    {
        if ($this->connection === null || $this->state !== self::STATE_ACTIVE) {
            return false;
        }

        $encoded = $this->codec->encode(RelayFrameType::DATA, $channelId, $payload);

        if ($this->connection->send($encoded) === false) {
            $local = $this->localConnections[$channelId] ?? null;
            if ($local !== null) {
                $local->pauseRecv();
                $this->pausedForTunnelDrain[$channelId] = true;
                $this->armTunnelDrainResume();
            }

            return false;
        }

        return true;
    }

    /**
     * Idempotently arm a single hub-tunnel `onBufferDrain` handler that
     * resumes every channel recorded in {@see $pausedForTunnelDrain} once the
     * tunnel's send buffer empties (SV-2.3, [S-F36]).
     *
     * The tunnel connection exposes exactly one `onBufferDrain` slot, shared
     * by every multiplexed channel, so this must NOT be re-armed (and
     * overwrite an earlier channel's pending resume) while already armed —
     * callers add to {@see $pausedForTunnelDrain} first and rely on this
     * no-op-if-already-armed guard.
     *
     * @return void
     *
     * @since SV-2.3
     */
    private function armTunnelDrainResume(): void
    {
        $tunnel = $this->connection;
        if ($tunnel === null || $tunnel->onBufferDrain !== null) {
            return;
        }

        $tunnel->onBufferDrain = function () use ($tunnel): void {
            // Clean up the drain handler first to avoid double-resume, but
            // only if the tunnel hasn't already been replaced by a reconnect
            // (in which case this is a stale callback on a dead object).
            if ($this->connection === $tunnel) {
                $tunnel->onBufferDrain = null;
            }

            $pendingChannelIds = array_keys($this->pausedForTunnelDrain);
            $this->pausedForTunnelDrain = [];

            foreach ($pendingChannelIds as $channelId) {
                $conn = $this->localConnections[$channelId] ?? null;
                if ($conn !== null && $conn->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                    $conn->resumeRecv();
                }
            }
        };
    }

    /**
     * Close the hub tunnel connection (triggers the onClose reconnect path).
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function closeTunnel(): void
    {
        $this->state = self::STATE_DISCONNECTED;
        if ($this->connection !== null) {
            $this->connection->close();
        }
    }

    /**
     * Log the relay session end with duration and reason.
     *
     * Guard clause: silently no-ops if the session was never started or if the
     * session-end log has already been emitted (e.g. onDisconnectedFrame called
     * closeTunnel which triggered handleDisconnect).
     *
     * @param string $reason Disconnect reason string.
     *
     * @return void
     */
    private function logSessionEnd(string $reason): void
    {
        if ($this->sessionStartTime === 0 || $this->sessionEndLogged) {
            return;
        }

        $durationNs = hrtime(true) - $this->sessionStartTime;
        $durationSeconds = $durationNs / 1_000_000_000.0;

        $this->logger->info('Relay session ended', [
            'relay_session_id' => $this->relaySessionId,
            'session_duration_seconds' => $durationSeconds,
            'disconnect_reason' => $reason,
        ]);

        $this->sessionEndLogged = true;
    }

    /**
     * Handle tunnel disconnection: clean up and schedule a reconnect.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function handleDisconnect(): void
    {
        // Log unexpected connection close (not initiated by hub DISCONNECTED frame).
        $this->logSessionEnd('connection_closed');

        $this->state = self::STATE_DISCONNECTED;
        $this->lastDisconnectTime = new \DateTimeImmutable();
        $this->connection = null;
        $this->recvBuffer = '';
        // Abandon any in-flight chunked-request assemblies — their tunnel is gone.
        $this->requestAccumulators = [];

        if ($this->heartbeatTimer !== null) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        $this->closeAllLocalConnections();

        if (!$this->running) {
            return;
        }

        $this->reconnectAttempts++;
        $this->scheduleReconnect();
    }

    /**
     * Schedule a reconnection attempt.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function scheduleReconnect(): void
    {
        if (!$this->running) {
            return;
        }

        if ($this->reconnectTimer !== null) {
            return;
        }

        // Exponential backoff: base_delay * 2^attempts, capped at maxDelay
        $baseDelay = $this->config->reconnectDelay;
        $maxDelay = $this->config->reconnectMaxDelay;
        $jitterFactor = $this->config->reconnectJitterFactor;
        $attempts = $this->reconnectAttempts;

        $exponentialDelay = min($baseDelay * (2 ** $attempts), $maxDelay);
        // Apply ±jitterFactor as a multiplier (e.g., 0.3 → 0.7 to 1.3)
        $jitterMultiplier = 1 + $jitterFactor * (((mt_rand() / mt_getrandmax()) * 2) - 1);
        $delay = $exponentialDelay * $jitterMultiplier;

        $this->logger->info('RelayConsumer scheduling reconnect', [
            'delay' => round($delay, 2),
            'attempts' => $attempts,
            'base_delay' => $baseDelay,
            'max_delay' => $maxDelay,
            'jitter_factor' => $jitterFactor,
        ]);

        try {
            // One-shot ($persistent = false): Workerman timers REPEAT by
            // default, and this callback nulls $this->reconnectTimer so the
            // repeating timer could never be deleted — every reconnect ever
            // scheduled kept firing connect() forever at its original delay,
            // silently replacing a healthy tunnel (~10s flap per orphaned
            // timer, worse as they accumulated across disconnects).
            $this->reconnectTimer = Timer::add($delay, function (): void {
                $this->reconnectTimer = null;
                if ($this->running) {
                    $this->connect();
                }
            }, [], false);
        } catch (Throwable $e) {
            // Workerman Timer unavailable (e.g. outside the event loop). Without
            // a loop there is nothing to reconnect to; skip silently.
            $this->reconnectTimer = null;
            $this->logger->debug('RelayConsumer: reconnect timer unavailable', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start the heartbeat timer that periodically sends HEARTBEAT frames.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function startHeartbeatTimer(): void
    {
        if ($this->heartbeatTimer !== null) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        $interval = $this->config->pingInterval;

        try {
            $this->heartbeatTimer = Timer::add($interval, function (): void {
                $this->sendFrame(RelayFrameType::HEARTBEAT, '');
            });
        } catch (Throwable $e) {
            // Workerman Timer unavailable (e.g. outside the event loop / unit
            // tests). The tunnel still works; only periodic heartbeats are
            // skipped until a real loop is running.
            $this->heartbeatTimer = null;
            $this->logger->debug('RelayConsumer: heartbeat timer unavailable', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode a JSON frame payload into an associative array (empty on failure).
     *
     * @param string $payload JSON payload bytes.
     *
     * @return array<string, mixed>
     *
     * @since 0.5.0
     */
    private function decodeJsonPayload(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Register a mount handler for a specific path prefix.
     *
     * @deprecated Since 0.5.0 — obsolete under the multiplexed raw-byte piping
     * model. The hub forwards opaque client bytes which are piped to this
     * server's local HTTP listener, so HLS/DLNA/Roku relay requests now arrive
     * as ordinary HTTP through the pipe and are served by the normal router.
     * This is a no-op compatibility shim retained so existing callers
     * ({@see \Phlix\LiveTv\Relay\HlsRelayManager},
     * {@see \Phlix\Dlna\RemoteRendererClient},
     * {@see \Phlix\Roku\RemoteRokuClient}) continue to compile and run.
     *
     * @param string   $pathPrefix Path prefix to handle (e.g. '/relay/live/{sessionId}').
     * @param callable $handler    Legacy handler (ignored).
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function registerMount(string $pathPrefix, callable $handler): void
    {
        $this->logger->debug('RelayConsumer: registerMount is a no-op under the multiplexed protocol', [
            'path_prefix' => $pathPrefix,
        ]);
    }

    /**
     * Unregister a mount handler.
     *
     * @deprecated Since 0.5.0 — see {@see registerMount()}. No-op shim.
     *
     * @param string $pathPrefix Path prefix to unregister (ignored).
     *
     * @return void
     *
     * @since 0.5.0
     */
    public function unregisterMount(string $pathPrefix): void
    {
        $this->logger->debug('RelayConsumer: unregisterMount is a no-op under the multiplexed protocol', [
            'path_prefix' => $pathPrefix,
        ]);
    }
}
