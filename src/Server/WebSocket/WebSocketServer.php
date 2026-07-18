<?php

/**
 * Phlix media server component: WebSocket.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Phlix\Common\Http\TrustedProxyResolver;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Stats\Metrics\MetricsCollector;

/**
 * WebSocket server implementation for real-time communication.
 *
 * This class manages the Workerman-based WebSocket server, handling
 * client connections, message routing, and connection lifecycle events.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description WebSocket server using Workerman for real-time bidirectional communication.
 * @see Connection For WebSocket connection representation
 * @see MessageHandler For message routing
 * @see ConnectionPool For connection management
 */
class WebSocketServer
{
    /** @var Worker The underlying Workerman worker instance */
    private Worker $worker;

    /** @var MessageHandler Handles incoming WebSocket messages */
    private MessageHandler $handler;

    /** @var ConnectionPool Manages active WebSocket connections */
    private ConnectionPool $connections;

    /** @var SyncPlayManager|null SyncPlay manager for group state */
    private ?SyncPlayManager $syncPlayManager = null;

    /** @var array<string, mixed> Server configuration */
    private array $config;

    /** @var MetricsCollector|null Metrics collector for connection tracking */
    private ?MetricsCollector $metrics = null;

    /**
     * Handshake-stage authenticator, or null when no JWT secret is configured.
     *
     * When a `jwt_secret` is present in the config this is a
     * {@see SyncPlayAuthMiddleware} with auth REQUIRED — token-less/invalid
     * handshakes are rejected. When null (no secret — dev) connections are
     * allowed anonymously (SV-4.7 Gap 2/3).
     *
     * @var SyncPlayAuthMiddleware|null
     */
    private ?SyncPlayAuthMiddleware $authMiddleware = null;

    /**
     * Per-surface connect-rate limiter for the `:8097` WS worker (SV-4.15(h));
     * the worker-local in-memory
     * {@see \Phlix\Common\RateLimit\RateLimitProfiles::WS_CONNECT} instance in the
     * resident `start.php` path. Null when unset (all direct-construction call
     * sites and tests) — the connect hook then applies no throttling.
     *
     * In-memory is already GLOBAL here: the :8097 WS worker runs `count=1`, so
     * per-worker == server-wide. A trip is enforced INLINE (there is no HTTP
     * response after the WS-upgrade hook, so the central 429 mapping cannot
     * apply): the connection is removed from the pool and closed, and the hook
     * returns WITHOUT throwing (a throw out of onWebSocketConnect triggers
     * Workerman's `Worker::stopAll()`, killing the worker).
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $wsConnectLimiter = null;

    /**
     * Trusted-proxy-aware client-IP resolver for the WS-connect limiter key
     * (SV-4.15 MEDIUM). Lazily built once per worker (immutable config, not
     * request state). See {@see onWebSocketConnect()} for why the TCP peer alone
     * is useless behind the loopback HAProxy front.
     *
     * @var TrustedProxyResolver|null
     */
    private ?TrustedProxyResolver $trustedProxyResolver = null;

    /**
     * Creates a new WebSocket server instance.
     *
     * @param array<string, mixed> $config Server configuration with 'host' and 'port' keys
     * @param MessageHandler|null $handler Optional message handler (for SP1 singletons)
     * @param Worker|null $worker Optional pre-created listening worker to attach
     *        the connection-lifecycle callbacks to. In the resident `start.php`
     *        path this MUST be the same worker that actually accepts connections
     *        on :8097 — otherwise the callbacks (and, critically, the pong
     *        handler the Websocket protocol resolves via
     *        `$connection->worker->onWebSocketPong`) bind to a throwaway worker
     *        that never listens, so the pool is never populated and half-open
     *        detection (S-F28) cannot function. When null, the server owns a
     *        freshly created worker (standalone / test / {@see run()} path).
     *
     * @example
     * ```php
     * $server = new WebSocketServer([
     *     'host' => '0.0.0.0',
     *     'port' => 8097,
     * ]);
     * $server->run();
     * ```
     */
    public function __construct(array $config, ?MessageHandler $handler = null, ?Worker $worker = null)
    {
        $this->config = $config;
        $this->connections = ConnectionPool::getInstance();
        $this->handler = $handler ?? new MessageHandler($this->connections);

        // SV-4.7: build the handshake-stage authenticator when a JWT secret is
        // configured. With a secret, auth is REQUIRED (token-less/invalid
        // handshakes are rejected); with no secret the middleware stays null and
        // connections are allowed anonymously (dev).
        $jwtSecret = $this->config['jwt_secret'] ?? null;
        if (is_string($jwtSecret) && $jwtSecret !== '') {
            $this->authMiddleware = new SyncPlayAuthMiddleware($jwtSecret, true);
        }

        if ($worker !== null) {
            // Resident path (start.php): attach to the ACTUAL listening worker.
            // The caller owns onWorkerStart (it builds the per-worker container
            // and invokes onStart() itself), so we do NOT overwrite it here — we
            // only bind the connection-lifecycle callbacks onto the real listener.
            $this->worker = $worker;
        } else {
            // Standalone path (tests / SyncPlayWorker / run()): own the worker
            // fully, including its onWorkerStart lifecycle.
            $host = $config['host'] ?? '0.0.0.0';
            $port = $config['port'] ?? 8097;
            $hostStr = is_string($host) ? $host : '0.0.0.0';
            $portStr = is_numeric($port) ? (string)(int)$port : '8097';

            $this->worker = new Worker("websocket://{$hostStr}:{$portStr}");
            $this->worker->onWorkerStart = [$this, 'onStart'];
        }

        $this->bindConnectionCallbacks();
    }

    /**
     * Bind the connection-lifecycle callbacks onto the listening worker.
     *
     * Workerman reads these at runtime off the ACCEPTING worker
     * (`$connection->worker`): onConnect populates the pool, onMessage routes to
     * the handler, onClose reaps, and — the S-F28 load-bearing one —
     * onWebSocketPong is resolved by the Websocket protocol as
     * `$connection->worker->onWebSocketPong` to clear the outstanding-ping count.
     * They MUST live on the same worker that accepts connections; see the
     * constructor for why the resident path injects that worker rather than
     * letting this class create its own (which, being built after Worker::runAll()
     * inside a forked child, would never listen).
     *
     * onWebSocketPong is a dynamic Workerman worker callback (Worker is
     * #[AllowDynamicProperties]).
     *
     * @return void
     */
    private function bindConnectionCallbacks(): void
    {
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
        // SV-4.7: authenticate at the WS-HANDSHAKE stage (not TCP-accept). The
        // Websocket protocol resolves this off the accepting worker
        // (`$connection->worker->onWebSocketConnect`) and passes the parsed
        // upgrade Request whose query string (`?token=`) is populated — unlike
        // onConnect's $_GET at TCP-accept.
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // @phpstan-ignore-next-line property.notFound
        $this->worker->onWebSocketPong = [$this, 'onWebSocketPong'];
    }

    /**
     * The underlying listening worker these callbacks/timers are bound to.
     *
     * Exposed so callers (and the dual-entrypoint regression test) can assert
     * that the callback surface — especially onWebSocketPong — is attached to the
     * same worker instance that accepts connections.
     *
     * @return Worker The Workerman worker this server is wired to.
     */
    public function getWorker(): Worker
    {
        return $this->worker;
    }

    /**
     * Called when the worker process starts.
     *
     * Initializes logging and starts the connection cleanup timer.
     *
     * @return void
     */
    public function onStart(): void
    {
        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->config['port'] ?? 8097;
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->info('WebSocket server started', [
            'host' => is_string($host) ? $host : '0.0.0.0',
            'port' => is_numeric($port) ? (int)$port : 8097,
        ]);

        $staleConnectionTimeoutRaw = $this->config['stale_connection_timeout'] ?? 300;
        $staleConnectionTimeout = is_numeric($staleConnectionTimeoutRaw) ? (int) $staleConnectionTimeoutRaw : 300;

        $staleGroupTimeoutRaw = $this->config['stale_group_timeout'] ?? 3600;
        $staleGroupTimeout = is_numeric($staleGroupTimeoutRaw) ? (int) $staleGroupTimeoutRaw : 3600;

        // Start cleanup timer for stale connections (every 60 seconds)
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(60, function () use ($staleConnectionTimeout): void {
                $this->connections->cleanupStaleConnections($staleConnectionTimeout);
            });

            // Start cleanup timer for stale SyncPlay groups (every 5 minutes)
            $syncPlayManager = $this->syncPlayManager;
            if ($syncPlayManager !== null) {
                \Workerman\Timer::add(300, function () use ($syncPlayManager, $staleGroupTimeout): void {
                    $removed = $syncPlayManager->cleanupStaleGroups($staleGroupTimeout);
                    if ($removed > 0) {
                        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
                        $logger->info('Cleaned up stale SyncPlay groups', [
                            'removed' => $removed,
                        ]);
                    }
                });
            }

            // S-F28: application-level ping timer. Each tick pings every live
            // connection and reaps any whose peer has not answered within the
            // non-response limit — this detects half-open sockets that the
            // receive-side-only stale-connection reaper cannot see.
            //
            // Reap latency: a silently-gone peer is reaped on the sweep that
            // OBSERVES pendingPings >= limit, which is the (limit + 1)th sweep
            // (each of the first `limit` sweeps only increments the counter). So
            // with the defaults (interval 30s, limit 2) a dead peer is reaped
            // after ~(limit + 1) x interval ≈ 90s, not ~2x.
            $pingIntervalRaw = $this->config['ping_interval'] ?? 30;
            $pingInterval = is_numeric($pingIntervalRaw) ? (int) $pingIntervalRaw : 30;
            if ($pingInterval < 1) {
                $pingInterval = 30;
            }

            $pingLimitRaw = $this->config['ping_not_response_limit'] ?? 2;
            $pingLimit = is_numeric($pingLimitRaw) ? (int) $pingLimitRaw : 2;
            if ($pingLimit < 1) {
                $pingLimit = 2;
            }

            \Workerman\Timer::add($pingInterval, function () use ($pingLimit): void {
                $this->pingConnections($pingLimit);
            });
        }
    }

    /**
     * Ping every live connection and reap those whose peer has stopped answering.
     *
     * Armed on a periodic timer by {@see onStart()}. A connection that has not
     * answered {@see $notRespondedLimit} consecutive pings is treated as a
     * half-open socket and closed + removed from the pool; every other
     * connection is pinged (incrementing its outstanding-ping count until a pong
     * resets it via {@see onWebSocketPong()}).
     *
     * @param int $notRespondedLimit Outstanding-ping count at which a connection
     *                               is considered dead and reaped.
     * @return void
     */
    public function pingConnections(int $notRespondedLimit): void
    {
        foreach ($this->connections->all() as $wsConnection) {
            if (!$wsConnection instanceof Connection) {
                continue;
            }

            if ($wsConnection->getPendingPings() >= $notRespondedLimit) {
                // Half-open socket: pings went unanswered past the limit. Reap it.
                $wsConnection->close();
                $this->connections->remove($wsConnection->getId());
                continue;
            }

            $wsConnection->ping();
        }
    }

    /**
     * Called by the WebSocket protocol layer when a peer answers a ping.
     *
     * Clears the connection's outstanding-ping count so the ping timer does not
     * reap a peer that is alive and responding.
     *
     * @param TcpConnection $connection The Workerman TCP connection that ponged.
     * @param string        $data       The pong payload (echo of the ping data).
     * @return void
     */
    public function onWebSocketPong(TcpConnection $connection, string $data): void
    {
        $wsConnection = $this->findConnection($connection);
        if ($wsConnection !== null) {
            $wsConnection->recordPong();
        }
    }

    /**
     * Called when a new client connects (TCP-accept stage).
     *
     * Creates the {@see Connection} wrapper, registers it in the pool and sends
     * the welcome message. Authentication is NOT performed here: at TCP-accept
     * the WS handshake has not run yet, so the `?token=` query string is not
     * available (reading `$_GET` here returns empty/stale data). Authentication
     * happens at the handshake stage in {@see onWebSocketConnect()} (SV-4.7).
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onConnect(TcpConnection $connection): void
    {
        $wsConnection = new Connection($connection);
        $this->connections->add($wsConnection);

        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->debug('New WebSocket connection', [
            'connection_id' => $wsConnection->getId(),
            'authenticated' => $wsConnection->isAuthenticated(),
            'user_id' => $wsConnection->getUserId(),
        ]);

        $wsConnection->sendMessage('connected', [
            'connection_id' => $wsConnection->getId(),
            'timestamp' => time(),
        ]);

        // S2 metrics: the connection is NOT opened here. At TCP-accept the WS
        // upgrade request (and its X-Forwarded-For) has not been parsed yet, so the
        // only IP available is the loopback HAProxy peer — recording it would stamp
        // every WS row with 127.0.0.1. The metrics record is opened in
        // {@see onWebSocketConnect()} instead, where the REAL client IP is resolved
        // (item5+).
    }

    /**
     * Called at the WebSocket HANDSHAKE stage, after the upgrade request is
     * parsed (SV-4.7).
     *
     * This is the correct lifecycle stage for connect-time authentication: the
     * Websocket protocol resolves this callback off the accepting worker and
     * passes the parsed upgrade {@see Request}, whose query string carries the
     * `?token=<jwt>` the client supplied — unlike {@see onConnect()}'s $_GET at
     * TCP-accept, which is empty/stale under Workerman.
     *
     * Enforcement (Gap 2/3):
     * - No JWT secret configured ($authMiddleware === null) → allow anonymous
     *   connections (dev).
     * - Secret configured → delegate to {@see SyncPlayAuthMiddleware::authenticateConnection()}
     *   (auth REQUIRED): a valid token marks the connection authenticated with
     *   its derived user id; a missing/invalid/expired token causes the handshake
     *   to be REJECTED — the connection is removed from the pool and closed.
     *
     * @param TcpConnection $connection The Workerman TCP connection.
     * @param Request       $request    The parsed WebSocket upgrade request.
     * @return void
     */
    public function onWebSocketConnect(TcpConnection $connection, Request $request): void
    {
        $wsConnection = $this->findConnection($connection);
        if ($wsConnection === null) {
            // onConnect populates the pool before the handshake; if the wrapper
            // is somehow absent there is nothing to authenticate.
            return;
        }

        // Resolve the REAL client IP ONCE, up front — it feeds BOTH the S2 metrics
        // record (item5+) and the connect rate-limiter key (SV-4.15 MEDIUM).
        //
        // The :8097 WS worker is fronted by HAProxy over loopback (see
        // deploy/haproxy.cfg) with NO PROXY-protocol, so $connection->getRemoteIp()
        // is ALWAYS the proxy's loopback address for EVERY client. Recording that
        // would stamp every WS metrics row with 127.0.0.1, and keying the limiter on
        // it would collapse the whole server into ONE bucket (an availability bug
        // worse than no limit). Instead we derive the REAL client from the trusted
        // upgrade-request headers (HAProxy/nginx set X-Forwarded-For / X-Real-IP)
        // using the SAME trusted-proxy-aware resolution as the HTTP limiters. If the
        // peer is not a trusted proxy the resolver safely falls back to the peer
        // address. This resolution is only possible HERE (not in onConnect): the
        // upgrade request is not parsed until the handshake.
        $this->trustedProxyResolver ??= new TrustedProxyResolver();
        $clientIp = $this->trustedProxyResolver->resolve(
            $connection->getRemoteIp(),
            self::upgradeHeader($request, 'x-forwarded-for'),
            self::upgradeHeader($request, 'x-real-ip'),
        );

        // S2 metrics: open the connection record with the RESOLVED client IP
        // (item5+). Deferred from onConnect (TCP-accept) to here so the row carries
        // the real client address instead of the loopback proxy peer. user_id is the
        // pre-auth value (null until the auth gate below marks the connection),
        // matching the previous onConnect timing.
        if ($this->metrics !== null) {
            $this->metrics->openConnection(
                $wsConnection->getId(),
                'websocket',
                $wsConnection->getUserId(),
                $clientIp,
                null,
                null,
            );
        }

        // SV-4.15(h): per-IP connect throttle, enforced BEFORE the auth gate so
        // it protects the anonymous/dev path too. WS != HTTP — there is no HTTP
        // response after the upgrade hook, so the central 429 mapping (SV-4.15(c))
        // does NOT apply here; the check is INLINE and must NOT throw (a throw out
        // of onWebSocketConnect triggers Workerman's Worker::stopAll(), killing the
        // :8097 worker — see the protocol handshake in Workerman\Protocols\Websocket).
        // On a trip we mirror the existing reject idiom below: remove the pool
        // wrapper + close the connection + return. We deliberately use a plain
        // close() (no WS 1013 frame): this hook fires BEFORE the 101 upgrade
        // response is sent, so the socket is not yet a WebSocket at the byte layer
        // and a raw close frame would be malformed/out-of-order — a TCP close is
        // the correct rejection at this stage.
        if ($this->wsConnectLimiter !== null) {
            $state = $this->wsConnectLimiter->hit('ws_connect:' . $clientIp);
            if ($state->limited) {
                LoggerFactory::get(LogChannels::WEBSOCKET)->warning(
                    'WebSocket connect rate-limited',
                    ['client_ip' => $clientIp, 'reset_at' => $state->resetAt],
                );
                $this->connections->remove($wsConnection->getId());
                $connection->close();
                return;
            }
        }

        // No secret configured (dev): allow the connection anonymously.
        if ($this->authMiddleware === null) {
            return;
        }

        $tokenRaw = $request->get('token');
        $token = is_string($tokenRaw) ? $tokenRaw : null;

        if (!$this->authMiddleware->authenticateConnection($wsConnection, $token)) {
            // Secret configured + missing/invalid token: reject the handshake.
            $this->connections->remove($wsConnection->getId());
            $connection->close();
        }
    }

    /**
     * Read a single header value from the parsed WS upgrade request as a string,
     * or null when absent/non-scalar. {@see Request::header()} returns `mixed`,
     * so we coerce defensively for the {@see TrustedProxyResolver}.
     */
    private static function upgradeHeader(Request $request, string $name): ?string
    {
        $value = $request->header($name);
        return is_string($value) ? $value : null;
    }

    /**
     * Called when a message is received from a client.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @param string $data The raw message data
     * @return void
     */
    public function onMessage(TcpConnection $connection, string $data): void
    {
        $wsConnection = $this->findConnection($connection);

        if (!$wsConnection) {
            return;
        }

        $this->handler->handle($wsConnection, $data);
    }

    /**
     * Called when a client disconnects.
     *
     * Removes the connection from the pool and broadcasts disconnection
     * to other clients if the user was authenticated.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     */
    public function onClose(TcpConnection $connection): void
    {
        $wsConnection = $this->findConnection($connection);

        if ($wsConnection) {
            $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
            $logger->info('WebSocket connection closed', [
                'connection_id' => $wsConnection->getId(),
                'user_id' => $wsConnection->getUserId(),
                'authenticated' => $wsConnection->isAuthenticated(),
            ]);

            // Notify SyncPlay manager to vacate member from group
            if ($this->syncPlayManager !== null) {
                $this->syncPlayManager->onConnectionClose($wsConnection->getId());
            }

            $this->connections->remove($wsConnection->getId());

            // Broadcast disconnection if authenticated
            if ($wsConnection->isAuthenticated()) {
                $this->handler->broadcast('client_disconnected', [
                    'connection_id' => $wsConnection->getId(),
                    'user_id' => $wsConnection->getUserId(),
                ], [$wsConnection->getId()]);
            }

            // S2 metrics: record the FINAL cumulative byte counts. We deliberately
            // do NOT closeConnection() here — that unset the registry row before the
            // next flush could persist it, so a WebSocket's bytes never reached
            // metrics_connections. Leaving the final touch in place lets the coming
            // flush write the real totals; the flush service then TTL-prunes the now
            // idle row (its last_seen_at ages past connection_ttl) from the registry
            // AND the table, so it drops out of the live panel ~ttl seconds after
            // close without leaking worker memory.
            if ($this->metrics !== null) {
                $this->metrics->touchConnection(
                    $wsConnection->getId(),
                    $connection->bytesRead,
                    $connection->bytesWritten,
                );
            }
        }
    }

    /**
     * Push the current cumulative byte counts of every live WebSocket connection
     * into the metrics registry.
     *
     * Armed on a periodic timer by start.php's WS worker (between flushes) so the
     * admin live-connection panel reflects real per-connection throughput for the
     * whole connection lifetime — previously the only touch was in
     * {@see onClose()}, so an open connection showed a permanent zero row and its
     * bytes were then deleted before any flush could persist them. No-op when the
     * collector is absent; the collector itself no-ops when metrics is disabled.
     *
     * @return void
     */
    public function touchActiveConnections(): void
    {
        if ($this->metrics === null) {
            return;
        }
        foreach ($this->connections->all() as $wsConnection) {
            if (!$wsConnection instanceof Connection) {
                continue;
            }
            $tcp = $wsConnection->getConnection();
            $this->metrics->touchConnection(
                $wsConnection->getId(),
                $tcp->bytesRead,
                $tcp->bytesWritten,
            );
        }
    }

    /**
     * Called when a connection error occurs.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @param int $code Error code
     * @param string $reason Error reason description
     * @return void
     */
    public function onError(TcpConnection $connection, int $code, string $reason): void
    {
        $logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $logger->error('WebSocket error', [
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    /**
     * Finds the Connection wrapper for a TcpConnection.
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return Connection|null The Connection wrapper or null if not found
     */
    private function findConnection(TcpConnection $connection): ?Connection
    {
        $wsConnection = $this->connections->getByObjectId($connection);

        return $wsConnection instanceof Connection ? $wsConnection : null;
    }

    /**
     * Gets the message handler for this server.
     *
     * @return MessageHandler The message handler instance
     */
    public function getHandler(): MessageHandler
    {
        return $this->handler;
    }

    /**
     * Sets the SyncPlay manager for group state management.
     *
     * This must be called before onStart() to enable the stale groups
     * cleanup timer.
     *
     * @param \Phlix\Session\SyncPlay\SyncPlayManager $manager The SyncPlay manager
     * @return void
     */
    public function setSyncPlayManager(\Phlix\Session\SyncPlay\SyncPlayManager $manager): void
    {
        $this->syncPlayManager = $manager;
    }

    /**
     * Sets the metrics collector for connection tracking.
     *
     * @param MetricsCollector $metrics The metrics collector
     * @return void
     */
    public function setMetricsCollector(MetricsCollector $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * Sets the connect-rate limiter enforced at the WS handshake (SV-4.15(h)).
     *
     * Injected by the resident `start.php` WS worker from the container's
     * {@see \Phlix\Common\RateLimit\RateLimitProfiles::WS_CONNECT} in-memory
     * profile. When left unset the connect hook applies no throttling, so
     * existing direct-construction call sites and tests keep working unchanged.
     *
     * @param RateLimiterInterface $limiter The connect-rate limiter.
     * @return void
     */
    public function setWsConnectLimiter(RateLimiterInterface $limiter): void
    {
        $this->wsConnectLimiter = $limiter;
    }

    /**
     * Starts the WebSocket server.
     *
     * This method blocks as it runs the Workerman event loop.
     *
     * @return void
     */
    public function run(): void
    {
        Worker::runAll();
    }
}
