<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Chunk;
use Workerman\Timer;

/**
 * Server-side relay consumer that maintains a persistent WSS tunnel to the hub.
 *
 * The consumer:
 * 1. Opens a persistent WSS connection to the hub's relay endpoint
 * 2. Receives HTTP request frames over the tunnel
 * 3. Dispatches them to the local Workerman HTTP router
 * 4. Sends the response back over the tunnel
 * 5. Auto-reconnects on connection drop
 *
 * @package Phlix\Hub
 * @since 0.12.0
 */
final class RelayConsumer
{
    /** @var RelayConfig */
    private RelayConfig $config;

    /** @var HubClient */
    private HubClient $hubClient;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var string */
    private string $serverId;

    /** @var Router */
    private Router $router;

    /** @var AsyncTcpConnection|null */
    private ?AsyncTcpConnection $connection = null;

    /** @var bool */
    private bool $running = false;

    /** @var int|null */
    private ?int $reconnectTimer = null;

    /** @var int|null */
    private ?int $pingTimer = null;

    /** @var int Sequence number for outbound frames. */
    private int $seq = 0;

    /** @var string Buffered incoming data. */
    private string $recvBuffer = '';

    /** @var array<string, callable> Registered mount handlers keyed by path prefix. */
    private array $mounts = [];

    /**
     * @param RelayConfig       $config   Relay configuration.
     * @param HubClient         $hubClient Hub client (for enrollment info).
     * @param StructuredLogger  $logger   Logger instance.
     * @param string            $serverId Hub-assigned server UUID.
     * @param Router|null       $router   Optional router override (for testing).
     */
    public function __construct(
        RelayConfig $config,
        HubClient $hubClient,
        StructuredLogger $logger,
        string $serverId,
        ?Router $router = null,
    ) {
        $this->config = $config;
        $this->hubClient = $hubClient;
        $this->logger = $logger;
        $this->serverId = $serverId;
        $this->router = $router ?? new Router();
    }

    /**
     * Start the relay consumer.
     *
     * Initiates a WSS connection to the hub's relay endpoint.
     *
     * @return void
     *
     * @since 0.12.0
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
     * @since 0.12.0
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

        if ($this->pingTimer !== null) {
            Timer::del($this->pingTimer);
            $this->pingTimer = null;
        }

        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }

        $this->recvBuffer = '';

        $this->logger->info('RelayConsumer stopped');
    }

    /**
     * Returns whether the consumer is connected to the hub.
     *
     * @return bool True if connected.
     *
     * @since 0.12.0
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Connect to the hub relay endpoint.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function connect(): void
    {
        $wssUrl = $this->config->buildHubWssUrl($this->serverId);

        $this->logger->info('RelayConsumer connecting', [
            'url' => $wssUrl,
            'server_id' => $this->serverId,
        ]);

        $enrollment = $this->hubClient->loadEnrollment();
        if ($enrollment === null) {
            $this->logger->error('RelayConsumer: cannot connect without enrollment');
            $this->scheduleReconnect();
            return;
        }

        $context = [
            'ssl' => [
                'verify_peer' => true,
                'SNI_enabled' => true,
            ],
        ];

        $this->connection = new AsyncTcpConnection($wssUrl, $context);

        $this->connection->onConnect = function (AsyncTcpConnection $conn) use ($enrollment): void {
            $this->logger->info('RelayConsumer connected');
            $this->sendRegistrationFrame($enrollment->enrollmentJwt);
            $this->startPingTimer();
        };

        $this->connection->onMessage = function (AsyncTcpConnection $conn, string $data): void {
            $this->handleData($data);
        };

        $this->connection->onError = function (AsyncTcpConnection $conn, int $code, string $msg): void {
            $this->logger->error('RelayConsumer connection error', [
                'code' => $code,
                'message' => $msg,
            ]);
        };

        $this->connection->onClose = function (AsyncTcpConnection $conn): void {
            $this->logger->warning('RelayConsumer connection closed');
            $this->handleDisconnect();
        };

        $this->connection->connect();
    }

    /**
     * Send the initial registration frame on connect.
     *
     * @param string $enrollmentJwt JWT from stored enrollment.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendRegistrationFrame(string $enrollmentJwt): void
    {
        $framer = new RelayMessageFramer();
        $bodyJson = json_encode(['server_id' => $this->serverId], JSON_THROW_ON_ERROR);
        $frame = $framer->frameRequest(
            $this->nextSeq(),
            'REGISTER',
            '/relay/register',
            [
                'Authorization' => 'Bearer ' . $enrollmentJwt,
                'X-Server-Id' => $this->serverId,
            ],
            $bodyJson,
        );

        if ($this->connection !== null) {
            $this->connection->send($frame);
        }
    }

    /**
     * Handle incoming binary data from the WebSocket.
     *
     * @param string $data Raw bytes.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function handleData(string $data): void
    {
        $this->recvBuffer .= $data;

        $framer = new RelayMessageFramer();

        while (true) {
            $frame = $framer->parse($this->recvBuffer);
            if ($frame === null) {
                break;
            }

            $frameLen = 9 + strlen(json_encode($frame->payload, JSON_THROW_ON_ERROR));
            $this->recvBuffer = substr($this->recvBuffer, $frameLen);

            $this->handleFrame($frame);
        }
    }

    /**
     * Handle a parsed relay frame.
     *
     * @param RelayFrame $frame Parsed frame.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function handleFrame(RelayFrame $frame): void
    {
        if ($frame->isPing()) {
            $this->handlePing($frame);
            return;
        }

        if ($frame->isPong()) {
            return;
        }

        if ($frame->isRequest()) {
            $this->handleRelayRequest($frame);
            return;
        }

        $this->logger->warning('RelayConsumer: unknown frame type', [
            'type' => $frame->type,
            'seq' => $frame->seq,
        ]);
    }

    /**
     * Handle a PING frame by responding with a PONG.
     *
     * @param RelayFrame $frame PING frame.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function handlePing(RelayFrame $frame): void
    {
        $framer = new RelayMessageFramer();
        $pongFrame = $framer->framePong($frame->seq);
        if ($this->connection !== null) {
            $this->connection->send($pongFrame);
        }
    }

    /**
     * Handle a relayed HTTP request by dispatching it locally.
     *
     * @param RelayFrame $frame HTTP request frame.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function handleRelayRequest(RelayFrame $frame): void
    {
        $payload = $frame->payload;

        $method = is_string($payload['method'] ?? null) ? $payload['method'] : 'GET';
        $path = is_string($payload['path'] ?? null) ? $payload['path'] : '/';
        $rawHeaders = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        $body = is_string($payload['body'] ?? null) ? $payload['body'] : '';

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($rawHeaders as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $headers[$k] = $v;
            }
        }

        try {
            $request = $this->buildRequest($method, $path, $headers, $body);
            $response = $this->dispatchLocally($request);
            $this->sendResponse($frame->seq, $response);
        } catch (Throwable $e) {
            $this->logger->error('RelayConsumer: dispatch error', [
                'seq' => $frame->seq,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            $errorResponse = (new Response())
                ->status(500)
                ->json(['error' => 'Internal Server Error']);
            $this->sendResponse($frame->seq, $errorResponse);
        }
    }

    /**
     * Build a PSR-7 compatible Request from relay payload data.
     *
     * @param string                $method  HTTP method.
     * @param string                $path    Request path.
     * @param array<string, string>  $headers Request headers.
     * @param string                $body    Request body.
     *
     * @return Request
     *
     * @since 0.12.0
     */
    private function buildRequest(string $method, string $path, array $headers, string $body): Request
    {
        /** @var array<string, string> $safeHeaders */
        $safeHeaders = [];
        foreach ($headers as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $safeHeaders[$k] = $v;
            }
        }

        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->headers = $safeHeaders;
        $request->queryString = '';
        $request->query = [];
        $request->body = [];
        $request->files = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->bearerToken = $safeHeaders['Authorization'] ?? null;

        return $request;
    }

    /**
     * Dispatch a request to the local router and return the response.
     *
     * First checks if a mount handler is registered for the path.
     *
     * @param Request $request
     *
     * @return Response
     *
     * @since 0.12.0
     */
    private function dispatchLocally(Request $request): Response
    {
        // Check for mount handlers first (HLS relay, etc.)
        return $this->dispatchViaMount($request);
    }

    /**
     * Send an HTTP response back over the tunnel.
     *
     * @param int      $seq     Sequence number (matches request).
     * @param Response $response The response to send.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function sendResponse(int $seq, Response $response): void
    {
        $headers = [];
        foreach ($response->headers as $name => $value) {
            $headers[$name] = is_string($value) ? $value : (string) $value;
        }

        $body = $response->body;

        $framer = new RelayMessageFramer();
        $frame = $framer->frameResponse($seq, $response->statusCode, $headers, $body);

        if ($this->connection !== null) {
            $this->connection->send($frame);
        }
    }

    /**
     * Handle disconnection by scheduling a reconnect.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function handleDisconnect(): void
    {
        if ($this->pingTimer !== null) {
            Timer::del($this->pingTimer);
            $this->pingTimer = null;
        }

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
     * @since 0.12.0
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

        $this->reconnectTimer = Timer::add($delay, function (): void {
            $this->reconnectTimer = null;
            if ($this->running) {
                $this->connect();
            }
        });
    }

    /**
     * Start the keep-alive ping timer.
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function startPingTimer(): void
    {
        if ($this->pingTimer !== null) {
            Timer::del($this->pingTimer);
        }

        $interval = $this->config->pingInterval;

        $this->pingTimer = Timer::add($interval, function (): void {
            if ($this->connection !== null) {
                $framer = new RelayMessageFramer();
                $this->connection->send($framer->framePing($this->nextSeq()));
            }
        });
    }

    /**
     * Return the next sequence number.
     *
     * @return int
     *
     * @since 0.12.0
     */
    private function nextSeq(): int
    {
        $this->seq = ($this->seq + 1) & 0xFFFFFFFF;
        return $this->seq;
    }

    /**
     * Register a mount handler for a specific path prefix.
     *
     * When a relay request arrives for a path matching the prefix,
     * the handler is called instead of the local router.
     *
     * @param string   $pathPrefix Path prefix to handle (e.g., '/relay/live/{sessionId}').
     * @param callable $handler    Handler function that receives the full path and returns response content.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function registerMount(string $pathPrefix, callable $handler): void
    {
        $this->mounts[$pathPrefix] = $handler;
        $this->logger->info('RelayConsumer: registered mount', [
            'path_prefix' => $pathPrefix,
        ]);
    }

    /**
     * Unregister a mount handler.
     *
     * @param string $pathPrefix Path prefix to unregister.
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function unregisterMount(string $pathPrefix): void
    {
        unset($this->mounts[$pathPrefix]);
        $this->logger->info('RelayConsumer: unregistered mount', [
            'path_prefix' => $pathPrefix,
        ]);
    }

    /**
     * Check if a path matches any registered mount.
     *
     * @param string $path Request path.
     *
     * @return string|null Matching path prefix or null.
     *
     * @since 0.12.0
     */
    private function findMount(string $path): ?string
    {
        foreach ($this->mounts as $prefix => $handler) {
            if (str_starts_with($path, $prefix)) {
                return $prefix;
            }
        }
        return null;
    }

    /**
     * Dispatch a request via registered mount if available, otherwise use local router.
     *
     * @param Request $request The request to dispatch.
     *
     * @return Response The dispatch result.
     *
     * @since 0.12.0
     */
    private function dispatchViaMount(Request $request): Response
    {
        $path = $request->path;
        $mountPrefix = $this->findMount($path);

        if ($mountPrefix !== null) {
            $handler = $this->mounts[$mountPrefix];

            try {
                $content = $handler($path);

                if ($content === null) {
                    return (new Response())
                        ->status(404)
                        ->text('Not Found');
                }

                // Determine content type based on path extension
                $contentType = 'application/octet-stream';
                if (str_ends_with($path, '.m3u8')) {
                    $contentType = 'application/vnd.apple.mpegurl';
                } elseif (str_ends_with($path, '.ts')) {
                    $contentType = 'video/MP2T';
                } elseif (str_ends_with($path, '.json')) {
                    $contentType = 'application/json';
                }

                return (new Response())
                    ->status(200)
                    ->header('Content-Type', $contentType)
                    ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Access-Control-Allow-Origin', '*')
                    ->text($content);
            } catch (Throwable $e) {
                $this->logger->error('RelayConsumer: mount handler error', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                return (new Response())
                    ->status(500)
                    ->json(['error' => 'Internal Server Error']);
            }
        }

        // No mount matched, dispatch to local router
        return $this->router->dispatch($request);
    }
}
