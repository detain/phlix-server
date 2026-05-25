<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Relay\RelayFrame;
use Phlix\Shared\Relay\RelayFrameType;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Timer;

use function array_key_last;
use function is_array;
use function is_string;
use function json_decode;
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
 *          local HTTP listener (default 127.0.0.1:8096) for that client_id,
 *          remembering the client_id -> local connection mapping.
 *        - DATA: write the raw bytes verbatim to the client's local
 *          connection. Local response bytes are wrapped back into DATA frames
 *          (chunked to <= 65535) and sent to the hub.
 *        - CLIENT_DISCONNECT: close + forget that client's local connection.
 *        - HEARTBEAT: reply with a HEARTBEAT frame and track liveness.
 *        - DISCONNECTED / ERROR: log; tunnel-level errors trigger reconnect.
 *
 * Raw-byte piping (rather than re-parsing HTTP) is the protocol-correct match:
 * the hub forwards opaque client bytes, so the server forwards them opaque too.
 *
 * KNOWN LIMITATION (single active client per tunnel)
 * --------------------------------------------------
 * The hub currently BROADCASTS server->client DATA to ALL clients, and DATA
 * frames carry NO client_id. As a result the protocol reliably supports only
 * ONE active client stream per tunnel; concurrent clients would cross-talk on
 * the return path. Server->hub DATA frames are therefore routed back without a
 * client_id and the server maps inbound DATA to its single live local
 * connection. True per-client response demultiplexing requires a coordinated
 * hub + shared + server protocol change (adding client_id to DATA frames).
 * See the {@see TODO(relay-mux)} markers below.
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

    /** @var int Sequence number for outbound binary frames. */
    private int $seq = 0;

    /** @var string Buffered incoming binary data awaiting frame boundaries. */
    private string $recvBuffer = '';

    /**
     * Local HTTP connections keyed by client_id.
     *
     * @var array<string, AsyncTcpConnection>
     */
    private array $localConnections = [];

    /**
     * Most recently opened client_id, used to route inbound DATA frames back
     * to the local connection (see "known limitation" — DATA has no client_id).
     *
     * @var string|null
     */
    private ?string $activeClientId = null;

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
     */
    public function __construct(
        RelayConfig $config,
        HubClient $hubClient,
        StructuredLogger $logger,
        string $serverId,
        ?callable $hubConnectionFactory = null,
        ?callable $localConnectionFactory = null,
    ) {
        $this->config = $config;
        $this->hubClient = $hubClient;
        $this->logger = $logger;
        $this->serverId = $serverId;
        $this->codec = new RelayMessageFramer();
        $this->hubConnectionFactory = $hubConnectionFactory;
        $this->localConnectionFactory = $localConnectionFactory;
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
        return $this->connection !== null;
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
     * Connect to the hub's server-tunnel WS endpoint.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function connect(): void
    {
        $wsUrl = $this->config->buildHubRelayWsUrl();
        if ($wsUrl === '') {
            $this->logger->error('RelayConsumer: no hub relay WS endpoint configured');
            $this->scheduleReconnect();
            return;
        }

        $enrollment = $this->hubClient->loadEnrollment();
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
        $this->connection = $this->openHubConnection($wsUrl);

        $enrollmentJwt = $enrollment->enrollmentJwt;

        $this->connection->onConnect = function (AsyncTcpConnection $conn) use ($enrollmentJwt): void {
            $this->logger->info('RelayConsumer connected; sending HELLO');
            $this->sendHello($enrollmentJwt);
        };

        $this->connection->onMessage = function (ConnectionInterface $conn, string $data): void {
            $this->onHubMessage($data);
        };

        $this->connection->onError = function (ConnectionInterface $conn, int $code, string $msg): void {
            $this->logger->error('RelayConsumer connection error', [
                'code' => $code,
                'message' => $msg,
            ]);
        };

        $this->connection->onClose = function (ConnectionInterface $conn): void {
            $this->logger->warning('RelayConsumer connection closed');
            $this->handleDisconnect();
        };

        $this->connection->connect();
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
        if ($this->hubConnectionFactory !== null) {
            return ($this->hubConnectionFactory)($wsUrl);
        }

        $context = [
            'ssl' => [
                'verify_peer' => true,
                'SNI_enabled' => true,
            ],
        ];

        return new AsyncTcpConnection($wsUrl, $context);
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
        $this->seq = 0;

        $this->logger->info('RelayConsumer: tunnel active', [
            'relay_session_id' => is_string($ack['relay_session_id'] ?? null) ? $ack['relay_session_id'] : null,
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
     * @param RelayFrame $frame CLIENT_CONNECT frame; payload is {client_id, session_id}.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onClientConnect(RelayFrame $frame): void
    {
        $payload = $this->decodeJsonPayload($frame->payload);
        $clientId = is_string($payload['client_id'] ?? null) ? $payload['client_id'] : '';

        if ($clientId === '') {
            $this->logger->warning('RelayConsumer: CLIENT_CONNECT missing client_id');
            return;
        }

        if (isset($this->localConnections[$clientId])) {
            // Already connected — ignore duplicate.
            return;
        }

        $localUrl = $this->config->buildLocalHttpUrl();
        $local = $this->openLocalConnection($localUrl);

        $local->onMessage = function (ConnectionInterface $conn, string $data) use ($clientId): void {
            $this->onLocalData($clientId, $data);
        };

        $local->onClose = function (ConnectionInterface $conn) use ($clientId): void {
            $this->onLocalClose($clientId);
        };

        $local->onError = function (ConnectionInterface $conn, int $code, string $msg) use ($clientId): void {
            $this->logger->warning('RelayConsumer: local connection error', [
                'client_id' => $clientId,
                'code' => $code,
                'message' => $msg,
            ]);
        };

        $this->localConnections[$clientId] = $local;
        // TODO(relay-mux): the hub does not tag DATA frames with client_id, so we
        // track the most-recent client to route inbound DATA. Multi-client demux
        // needs a protocol change adding client_id to DATA frames.
        $this->activeClientId = $clientId;

        $local->connect();

        $this->logger->info('RelayConsumer: client connected', [
            'client_id' => $clientId,
            'local_url' => $localUrl,
        ]);
    }

    /**
     * Handle a CLIENT_DISCONNECT frame: close + forget the client's local conn.
     *
     * @param RelayFrame $frame CLIENT_DISCONNECT frame; payload is {client_id}.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onClientDisconnect(RelayFrame $frame): void
    {
        $payload = $this->decodeJsonPayload($frame->payload);
        $clientId = is_string($payload['client_id'] ?? null) ? $payload['client_id'] : '';

        if ($clientId === '') {
            return;
        }

        $this->closeLocalConnection($clientId);

        $this->logger->info('RelayConsumer: client disconnected', [
            'client_id' => $clientId,
        ]);
    }

    /**
     * Handle a DATA frame from the hub: pipe raw bytes to the local connection.
     *
     * @param RelayFrame $frame DATA frame; payload is raw client bytes verbatim.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onData(RelayFrame $frame): void
    {
        // TODO(relay-mux): DATA frames carry no client_id, so route to the
        // single active client connection. True multi-client support requires
        // client_id on DATA frames (hub + shared + server change).
        $clientId = $this->activeClientId;
        if ($clientId === null || !isset($this->localConnections[$clientId])) {
            $this->logger->warning('RelayConsumer: DATA with no active local connection');
            return;
        }

        $this->localConnections[$clientId]->send($frame->payload, true);
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
        $this->logger->info('RelayConsumer: hub sent DISCONNECTED', [
            'reason' => is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
        ]);
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
     * Wraps them into DATA frames (chunked to <= 65535) and sends to the hub.
     *
     * @param string $clientId Owning client_id.
     * @param string $data     Raw response bytes from the local listener.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onLocalData(string $clientId, string $data): void
    {
        if (!isset($this->localConnections[$clientId])) {
            return;
        }

        $offset = 0;
        $length = strlen($data);
        $maxChunk = 65535;

        do {
            $chunk = substr($data, $offset, $maxChunk);
            $this->sendFrame(RelayFrameType::DATA, $chunk);
            $offset += $maxChunk;
        } while ($offset < $length);
    }

    /**
     * Handle a local connection close: forget it.
     *
     * @param string $clientId Owning client_id.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function onLocalClose(string $clientId): void
    {
        if (isset($this->localConnections[$clientId])) {
            unset($this->localConnections[$clientId]);
            if ($this->activeClientId === $clientId) {
                $this->activeClientId = array_key_last($this->localConnections);
            }
            $this->logger->info('RelayConsumer: local connection closed', [
                'client_id' => $clientId,
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
     * Close and forget a single client's local connection.
     *
     * @param string $clientId Owning client_id.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function closeLocalConnection(string $clientId): void
    {
        $conn = $this->localConnections[$clientId] ?? null;
        if ($conn === null) {
            return;
        }

        unset($this->localConnections[$clientId]);
        if ($this->activeClientId === $clientId) {
            $this->activeClientId = array_key_last($this->localConnections);
        }
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
        $this->activeClientId = null;
    }

    /**
     * Encode and send a binary frame to the hub.
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

        $encoded = $this->codec->encode($type, $this->nextSeq(), $payload);
        $this->connection->send($encoded);
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
     * Handle tunnel disconnection: clean up and schedule a reconnect.
     *
     * @return void
     *
     * @since 0.5.0
     */
    private function handleDisconnect(): void
    {
        $this->state = self::STATE_DISCONNECTED;
        $this->connection = null;
        $this->recvBuffer = '';

        if ($this->heartbeatTimer !== null) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }

        $this->closeAllLocalConnections();

        if (!$this->running) {
            return;
        }

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

        $delay = $this->config->reconnectDelay;

        $this->logger->info('RelayConsumer scheduling reconnect', [
            'delay' => $delay,
        ]);

        try {
            $this->reconnectTimer = Timer::add($delay, function (): void {
                $this->reconnectTimer = null;
                if ($this->running) {
                    $this->connect();
                }
            });
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
     * Return the next 32-bit unsigned sequence number.
     *
     * @return int
     *
     * @since 0.5.0
     */
    private function nextSeq(): int
    {
        $this->seq = ($this->seq + 1) & 0xFFFFFFFF;
        return $this->seq;
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
