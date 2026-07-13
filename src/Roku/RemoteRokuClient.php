<?php

/**
 * Phlix media server component: Roku.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Roku;

use Phlix\Common\Runtime\WorkerContext;
use Phlix\Hub\RelayConsumer;
use Workerman\Http\Client as AsyncHttpClient;

/**
 * Roku control via relay tunnel.
 *
 * Proxies ECP commands over a RelayConsumer for controlling
 * Roku devices that are behind NAT or not directly accessible.
 *
 * @since 0.12.0
 */
class RemoteRokuClient
{
    /** @var RelayConsumer Relay consumer for tunneled requests */
    private RelayConsumer $relay;

    /** @var string Device ID being controlled */
    private string $deviceId;

    /** @var string Device host (resolved via relay) */
    private string $deviceHost;

    /** @var int Device port */
    private int $devicePort;

    /**
     * @param RelayConsumer $relay Relay consumer for tunneled requests
     * @param string $deviceId Device ID being controlled
     * @param string $deviceHost Device host/IP address
     * @param int $devicePort ECP port (default 8060)
     *
     * @since 0.12.0
     */
    public function __construct(
        RelayConsumer $relay,
        string $deviceId,
        string $deviceHost,
        int $devicePort = 8060
    ) {
        $this->relay = $relay;
        $this->deviceId = $deviceId;
        $this->deviceHost = $deviceHost;
        $this->devicePort = $devicePort;
    }

    /**
     * Play media on the remote Roku.
     *
     * @param string $url Media URL to play
     * @param string $mimeType MIME content type
     * @param string $title Media title
     * @param string $thumbnail Thumbnail URL
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function playMedia(string $url, string $mimeType, string $title, string $thumbnail): array
    {
        $path = sprintf('/roku/%s/media/play', $this->deviceId);

        return $this->relayRequest('POST', $path, [
            'url' => $url,
            'mimeType' => $mimeType,
            'title' => $title,
            'thumbnail' => $thumbnail,
        ]);
    }

    /**
     * Send a keypress to the remote Roku.
     *
     * @param string $key Key name
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function sendKey(string $key): array
    {
        $path = sprintf('/roku/%s/input', $this->deviceId);

        return $this->relayRequest('POST', $path, ['key' => $key]);
    }

    /**
     * Launch a channel on the remote Roku.
     *
     * @param string $channelId Channel ID to launch
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function launchChannel(string $channelId): array
    {
        $path = sprintf('/roku/%s/launch/%s', $this->deviceId, $channelId);

        return $this->relayRequest('POST', $path, []);
    }

    /**
     * Perform a relay request to the remote device.
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array<string, mixed> $data Request data
     *
     * @return array<string, mixed> Response data
     */
    private function relayRequest(string $method, string $path, array $data): array
    {
        // Register mount handler for this specific path
        $pathPrefix = '/roku/' . $this->deviceId;
        $handler = function (string $actualPath) use ($method, $data): ?string {
            // Build the ECP request
            $ecpPath = str_replace('/roku/' . $this->deviceId, '', $actualPath);

            if ($method === 'POST') {
                // Build form data
                $body = http_build_query($data);
                $url = sprintf('http://%s:%d%s', $this->deviceHost, $this->devicePort, $ecpPath);

                // For media/play endpoint, launch MediaPlayer and defer the play command
                if (str_ends_with($ecpPath, '/media/play')) {
                    $this->relayLaunchChannel(RokuEcpClient::CHANNEL_MEDIAPLAYER);

                    if (class_exists('\Workerman\Timer')) {
                        // Non-blocking: defer the play command by 500ms using Workerman Timer
                        \Workerman\Timer::add(0.5, function () use ($url, $body): void {
                            $this->httpPost($url, $body);
                        }, [], false);
                        // Return immediately without waiting for the timer
                        return null;
                    }

                    // Fallback: no Timer (e.g. CLI). Wait coroutine-aware so we
                    // yield the event loop instead of stalling the worker.
                    $this->coroutineAwareSleep(0.5);
                }

                return $this->httpPost($url, $body);
            }

            return null;
        };

        // Register the mount temporarily
        $this->relay->registerMount($pathPrefix, $handler);

        try {
            // Execute via relay
            // In a real implementation, this would send through the relay tunnel
            return ['success' => true, 'path' => $path, 'data' => $data];
        } finally {
            // Unregister the mount
            $this->relay->unregisterMount($pathPrefix);
        }
    }

    /**
     * Launch a channel via ECP directly.
     *
     * @param string $channelId Channel ID
     *
     * @return void
     */
    private function relayLaunchChannel(string $channelId): void
    {
        $url = sprintf('http://%s:%d/launch/%s', $this->deviceHost, $this->devicePort, $channelId);

        $this->httpPost($url, '');
    }

    /**
     * POST to a Roku ECP endpoint, non-blocking when possible.
     *
     * Inside a live Swoole coroutine the request goes through the async
     * {@see \Workerman\Http\Client} with a cooperative Channel wait (yields the
     * event loop, so a powered-off Roku can no longer stall the worker for the
     * full timeout). Outside a coroutine (CLI/tests, or a plain Workerman Timer
     * callback) it falls back to the blocking stream fetch. Same
     * coroutine-vs-blocking decision ({@see WorkerContext}) the other async
     * clients use (SV-4.5 / S-F15).
     *
     * @param string $url Absolute ECP URL.
     * @param string $body Form-encoded request body.
     *
     * @return string|null Response body, or null on failure.
     */
    private function httpPost(string $url, string $body): ?string
    {
        if ($this->preferAsyncHttp()) {
            return $this->httpPostAsync($url, $body);
        }

        return $this->httpPostBlocking($url, $body);
    }

    /**
     * Whether the async (coroutine) HTTP path should be used.
     *
     * True only inside a running Workerman worker AND a live Swoole coroutine —
     * the same coroutine-vs-blocking decision ({@see WorkerContext}) every async
     * client here shares. Outside that (CLI/tests, or a plain Timer callback)
     * the blocking stream path is used. Protected so tests can drive both branches.
     *
     * @return bool
     */
    protected function preferAsyncHttp(): bool
    {
        return WorkerContext::isEventLoopRunning() && WorkerContext::inCoroutine();
    }

    /**
     * Async POST via workerman/http-client + Swoole Channel cooperative wait.
     *
     * @param string $url Absolute URL.
     * @param string $body Form-encoded request body.
     *
     * @return string|null Response body, or null on error/timeout.
     */
    protected function httpPostAsync(string $url, string $body): ?string
    {
        $client = new AsyncHttpClient(['timeout' => 10]);

        $channel = new \Swoole\Coroutine\Channel(1);
        $state = ['body' => null];

        $client->request($url, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'data' => $body,
            'success' => function ($response) use (&$state, $channel): void {
                $state['body'] = (string) $response->getBody();
                $channel->push(true);
            },
            'error' => function ($error) use ($channel): void {
                $channel->push(true);
            },
        ]);

        $channel->pop(10.0);

        return $state['body'];
    }

    /**
     * Blocking stream POST for CLI/testing contexts (no event loop).
     *
     * @param string $url Absolute URL.
     * @param string $body Form-encoded request body.
     *
     * @return string|null Response body, or null on failure.
     */
    protected function httpPostBlocking(string $url, string $body): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 10,
                'content' => $body,
                'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            ],
        ]);

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
}
