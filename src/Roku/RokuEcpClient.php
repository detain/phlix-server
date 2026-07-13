<?php

/**
 * Phlix media server component: Roku.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Roku;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Runtime\WorkerContext;
use Workerman\Http\Client as AsyncHttpClient;

/**
 * HTTP ECP client for Roku device control.
 *
 * Uses plain HTTP requests (no SOAP, no special encoding) to communicate
 * with the Roku ECP (External Control Protocol) API on port 8060.
 *
 * Endpoints:
 * - POST /input — send keypress (body: key={keyName})
 * - POST /launch/{channelId} — launch a channel
 * - POST /media/play — play media (form data: url, mimeType, title, thumbnail)
 * - GET /query/device-info — get device info (XML response)
 * - GET /query/player-info — get player state
 *
 * @since 0.12.0
 */
class RokuEcpClient
{
    /** Default ECP port */
    public const DEFAULT_PORT = 8060;

    /** Built-in MediaPlayer channel ID */
    public const CHANNEL_MEDIAPLAYER = '6585';

    /** @var string Device host/IP address */
    private string $deviceHost;

    /** @var int ECP port number */
    private int $devicePort;

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /**
     * @param string $deviceHost Device host/IP address
     * @param int $devicePort ECP port number (default 8060)
     * @param StructuredLogger|null $logger Optional logger instance
     * @param int $timeout Request timeout in seconds (default 10)
     *
     * @since 0.12.0
     */
    public function __construct(
        string $deviceHost,
        int $devicePort = self::DEFAULT_PORT,
        ?StructuredLogger $logger = null,
        int $timeout = 10
    ) {
        $this->deviceHost = $deviceHost;
        $this->devicePort = $devicePort;
        $this->logger = $logger;
        $this->timeout = $timeout;
    }

    /**
     * Launch a channel by its ID.
     *
     * Examples: '12' for YouTube, '6585' for MediaPlayer
     *
     * @param string $channelId Channel ID to launch
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function launchChannel(string $channelId): array
    {
        return $this->post('/launch/' . $channelId, '');
    }

    /**
     * Send a media item to the Roku for playback.
     *
     * Launches the built-in MediaPlayer channel first, then sends
     * the media play command with URL and metadata.
     *
     * @param string $mediaUrl Media URL to play
     * @param string $mimeType MIME content type
     * @param string $title Media title for display
     * @param string $thumbnail Thumbnail URL
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function playMedia(string $mediaUrl, string $mimeType, string $title = '', string $thumbnail = ''): array
    {
        // Launch MediaPlayer channel first
        $this->launchChannel(self::CHANNEL_MEDIAPLAYER);

        // Give the channel time to launch using non-blocking Timer
        $formData = http_build_query([
            'url' => $mediaUrl,
            'mimeType' => $mimeType,
            'title' => $title,
            'thumbnail' => $thumbnail,
        ]);

        // Use Workerman\Timer for non-blocking delay before sending play command
        // The ECP protocol is generally responsive; 500ms is conservative for channel launch
        if (class_exists('\Workerman\Timer')) {
            \Workerman\Timer::add(0.5, function () use ($formData): void {
                $this->post('/media/play', $formData);
            }, [], false);
            // Return immediately without waiting for the timer
            return ['success' => true, 'response' => ''];
        }

        // Fallback: no Workerman Timer (e.g. CLI). Wait coroutine-aware so we
        // yield the event loop instead of stalling the worker (SV-4.5/S-F15).
        $this->coroutineAwareSleep(0.5);
        return $this->post('/media/play', $formData);
    }

    /**
     * Send a keypress command.
     *
     * Common keys: Play, Pause, Back, Home, Up, Down, Left, Right,
     * Select, Rev, Fwd, InstantReplay, Info, BackSpace
     *
     * @param string $key Key name to send
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function sendKeypress(string $key): array
    {
        return $this->post('/input', 'key=' . $key);
    }

    /**
     * Send an icon (for artwork/screensaver).
     *
     * @param string $iconUrl Icon/artwork URL
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function sendIcon(string $iconUrl): array
    {
        return $this->post('/icon', http_build_query(['url' => $iconUrl]));
    }

    /**
     * Get device info (name, model, serial, software version).
     *
     * Parses the XML response from /query/device-info.
     *
     * @return array<string, mixed> Device info array
     *
     * @since 0.12.0
     */
    public function getDeviceInfo(): array
    {
        $response = $this->get('/query/device-info');

        return $this->parseDeviceInfoXml($response);
    }

    /**
     * Get the current player state.
     *
     * @return array<string, mixed> Player state array
     *
     * @since 0.12.0
     */
    public function getPlayerState(): array
    {
        $response = $this->get('/query/player-info');

        return $this->parsePlayerInfoXml($response);
    }

    /**
     * Perform a GET request to the ECP endpoint.
     *
     * @param string $path ECP endpoint path
     *
     * @return string Response body
     *
     * @throws \RuntimeException On request failure
     */
    private function get(string $path): string
    {
        $url = sprintf('http://%s:%d%s', $this->deviceHost, $this->devicePort, $path);

        $this->log('debug', 'ECP GET: {url}', ['url' => $url]);

        $response = $this->fetch('GET', $url, null);

        if ($response === null) {
            throw new \RuntimeException('Failed to connect to Roku device at ' . $url);
        }

        $this->log('debug', 'ECP GET response: {response}', ['response' => substr($response, 0, 200)]);

        return $response;
    }

    /**
     * Perform a POST request to the ECP endpoint.
     *
     * @param string $path ECP endpoint path
     * @param string $body Request body
     *
     * @return array<string, mixed> Response data
     *
     * @throws \RuntimeException On request failure
     */
    private function post(string $path, string $body): array
    {
        $url = sprintf('http://%s:%d%s', $this->deviceHost, $this->devicePort, $path);

        $this->log('debug', 'ECP POST: {url} body={body}', [
            'url' => $url,
            'body' => substr($body, 0, 200),
        ]);

        $response = $this->fetch('POST', $url, $body);

        if ($response === null) {
            throw new \RuntimeException('Failed to connect to Roku device at ' . $url);
        }

        $this->log('debug', 'ECP POST response: {response}', ['response' => substr($response, 0, 200)]);

        // ECP POST responses are typically empty or XML
        // Return success indicator
        return [
            'success' => true,
            'response' => $response,
        ];
    }

    /**
     * Parse device info XML response.
     *
     * @param string $xml XML response from /query/device-info
     *
     * @return array<string, mixed> Parsed device info
     */
    private function parseDeviceInfoXml(string $xml): array
    {
        $result = [
            'friendlyName' => '',
            'modelName' => '',
            'softwareVersion' => '',
            'serialNumber' => '',
            'deviceId' => '',
        ];

        if (empty($xml)) {
            return $result;
        }

        // Parse XML response
        if (function_exists('simplexml_load_string')) {
            try {
                $element = @simplexml_load_string($xml);
                if ($element !== false) {
                    $result['friendlyName'] = (string)($element->friendlyName ?? '');
                    $result['modelName'] = (string)($element->modelName ?? '');
                    $result['softwareVersion'] = (string)($element->softwareVersion ?? '');
                    $result['serialNumber'] = (string)($element->serialNumber ?? '');
                    $result['deviceId'] = (string)($element->deviceId ?? '');
                }
            } catch (\Throwable) {
                // Fall through to return empty result
            }
        }

        return $result;
    }

    /**
     * Parse player info XML response.
     *
     * @param string $xml XML response from /query/player-info
     *
     * @return array<string, mixed> Parsed player state
     */
    private function parsePlayerInfoXml(string $xml): array
    {
        $result = [
            'state' => 'Unknown',
            'position' => 0,
            'duration' => 0,
            'format' => '',
        ];

        if (empty($xml)) {
            return $result;
        }

        // Parse XML response
        if (function_exists('simplexml_load_string')) {
            try {
                $element = @simplexml_load_string($xml);
                if ($element !== false) {
                    $result['state'] = (string)($element->state ?? 'Unknown');
                    $result['position'] = (int)($element->position ?? 0);
                    $result['duration'] = (int)($element->duration ?? 0);
                    $result['format'] = (string)($element->format ?? '');
                }
            } catch (\Throwable) {
                // Fall through to return default result
            }
        }

        return $result;
    }

    /**
     * Perform an HTTP request to the ECP device, non-blocking when possible.
     *
     * Inside a live Swoole coroutine the request goes through the async
     * {@see \Workerman\Http\Client} with a cooperative Channel wait (yields the
     * event loop, so a powered-off Roku can no longer stall the worker for the
     * full timeout). Outside a coroutine (CLI/tests) it falls back to the
     * blocking stream fetch. This is the same coroutine-vs-blocking decision
     * ({@see WorkerContext}) the other async clients use (SV-4.5 / S-F15).
     *
     * @param string $method HTTP method (GET or POST).
     * @param string $url Absolute ECP URL.
     * @param string|null $body Request body for POST, or null for GET.
     *
     * @return string|null Response body, or null on failure.
     */
    private function fetch(string $method, string $url, ?string $body): ?string
    {
        if ($this->preferAsyncHttp()) {
            return $this->fetchAsync($method, $url, $body);
        }

        return $this->fetchBlocking($method, $url, $body);
    }

    /**
     * Whether the async (coroutine) HTTP path should be used.
     *
     * True only inside a running Workerman worker AND a live Swoole coroutine —
     * the same coroutine-vs-blocking decision ({@see WorkerContext}) every async
     * client here shares. Outside that (CLI/tests) the blocking stream path is
     * used. Protected so tests can drive both branches.
     *
     * @return bool
     */
    protected function preferAsyncHttp(): bool
    {
        return WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine();
    }

    /**
     * Async request via workerman/http-client + Swoole Channel cooperative wait.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL.
     * @param string|null $body Request body for POST.
     *
     * @return string|null Response body, or null on error/timeout.
     */
    protected function fetchAsync(string $method, string $url, ?string $body): ?string
    {
        $client = new AsyncHttpClient(['timeout' => $this->timeout]);

        $options = [
            'method' => $method,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ];
        if ($body !== null) {
            $options['data'] = $body;
        }

        $channel = new \Swoole\Coroutine\Channel(1);
        $state = ['body' => null];

        $options['success'] = function ($response) use (&$state, $channel): void {
            $state['body'] = (string) $response->getBody();
            $channel->push(true);
        };
        $options['error'] = function ($error) use ($channel): void {
            $channel->push(true);
        };

        $client->request($url, $options);
        $channel->pop((float) $this->timeout);

        return $state['body'];
    }

    /**
     * Blocking stream fetch for CLI/testing contexts (no event loop).
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL.
     * @param string|null $body Request body for POST.
     *
     * @return string|null Response body, or null on failure.
     */
    protected function fetchBlocking(string $method, string $url, ?string $body): ?string
    {
        $http = [
            'method' => $method,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $http['content'] = $body;
            $http['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        }

        $context = stream_context_create(['http' => $http]);
        $response = @file_get_contents($url, false, $context);

        return $response === false ? null : $response;
    }

    /**
     * Sleep without blocking the Workerman/Swoole event loop.
     *
     * Uses the shared {@see WorkerContext::inCoroutine()} guard: inside a live
     * coroutine it uses the cooperative {@see \Swoole\Coroutine::sleep()};
     * outside a coroutine it falls back to blocking usleep.
     *
     * @param float $seconds Sleep duration in seconds.
     * @return void
     */
    private function coroutineAwareSleep(float $seconds): void
    {
        if (WorkerContext::inCoroutine()) {
            \Swoole\Coroutine::sleep($seconds);
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Log a message if logger is available.
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Log context
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->$level($message, $context);
    }
}
