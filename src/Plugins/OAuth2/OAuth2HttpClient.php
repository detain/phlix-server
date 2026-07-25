<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;

/**
 * Non-blocking HTTP client for OAuth2 authorization-code providers (the GitHub
 * flow, and any future non-OIDC OAuth2 provider built on
 * {@see AbstractOAuth2Provider}).
 *
 * It mirrors the SAME proven cooperative-wait pattern as
 * {@see \Phlix\Plugins\Oidc\OidcHttpClient} / {@see \Phlix\Media\Metadata\MetadataHttpClient}
 * / {@see \Phlix\Hub\HttpClient} / {@see \Phlix\Admin\S3Client}: an async
 * {@see Client} request whose success/error callbacks push to a
 * {@see \Swoole\Coroutine\Channel} the caller `pop()`s — yielding to the event
 * loop instead of stalling the resident worker (CLAUDE.md "Async Patterns").
 *
 * When there is no running event loop / coroutine (CLI, tests) OR the URL is a
 * TLS endpoint that stalls under the Swoole loop ({@see EventLoopTls}), it
 * transparently falls back to a bounded, TLS-verifying blocking cURL request.
 * TLS peer + hostname verification are always enforced: an OAuth2 token/profile
 * response fetched over an unverified channel would let a MITM forge the
 * provider's identity.
 *
 * Not `final`: the concrete class is injected into the OAuth2 providers so tests
 * can substitute a double and exercise the flow with no real network.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
class OAuth2HttpClient
{
    /** @var int Request timeout in seconds. */
    private int $timeout;

    /** @var Client|null Async HTTP client instance (lazy initialised). */
    private ?Client $asyncClient = null;

    /**
     * @param int $timeout Request timeout in seconds (default 10).
     */
    public function __construct(int $timeout = 10)
    {
        $this->timeout = $timeout;
    }

    /**
     * Perform a non-blocking GET, yielding to the event loop while in flight.
     *
     * @param string                $url     Absolute URL.
     * @param array<string, string> $headers Request headers.
     *
     * @return ResponseInterface|null Response, or null on transport error/timeout.
     */
    public function get(string $url, array $headers = []): ?ResponseInterface
    {
        return $this->send('GET', $url, null, $headers);
    }

    /**
     * Perform a non-blocking POST, yielding to the event loop while in flight.
     *
     * @param string                $url     Absolute URL.
     * @param string                $body    Raw request body (already encoded).
     * @param array<string, string> $headers Request headers.
     *
     * @return ResponseInterface|null Response, or null on transport error/timeout.
     */
    public function post(string $url, string $body, array $headers = []): ?ResponseInterface
    {
        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * Route the request down the async or blocking-cURL path per the runtime
     * context, exactly as {@see \Phlix\Media\Metadata\MetadataHttpClient::get()} does.
     *
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        // The async path waits on a Swoole\Coroutine\Channel, valid only inside a
        // coroutine (getCid() > 0). Outside one, Channel::pop() returns false
        // immediately, so we must use blocking cURL. And https + the Swoole event
        // loop stalls TLS reads (see EventLoopTls), so those take blocking too.
        $needsBlocking = !WorkerContext::isEventLoopRunning()
            || !WorkerContext::inCoroutine()
            || EventLoopTls::requiresBlockingCurl($url);

        return $needsBlocking
            ? $this->requestCurl($method, $url, $body, $headers)
            : $this->requestAsync($method, $url, $body, $headers);
    }

    /**
     * Lazily construct the async client.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client(['timeout' => $this->timeout]);
        }
        return $this->asyncClient;
    }

    /**
     * Async request with cooperative wait via Channel.
     *
     * @param array<string, string> $headers
     */
    private function requestAsync(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        $client = $this->getAsyncClient();

        $options = [
            'method' => $method,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $options['data'] = $body;
        }

        $channel = new \Swoole\Coroutine\Channel(1);
        $state = ['response' => null, 'error' => null];

        $options['success'] = function (ResponseInterface $response) use (&$state, $channel): void {
            $state['response'] = $response;
            $channel->push(true);
        };
        $options['error'] = function (\Throwable $error) use (&$state, $channel): void {
            $state['error'] = $error;
            $channel->push(true);
        };

        // Initiate async request (non-blocking), then yield until done/timeout.
        $client->request($url, $options);
        $channel->pop((float) $this->timeout);

        if ($state['error'] !== null) {
            return null;
        }

        return $state['response'];
    }

    /**
     * Bounded, TLS-verifying blocking cURL fallback for CLI/test/https-under-Swoole.
     *
     * @param array<string, string> $headers
     */
    private function requestCurl(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        if ($url === '') {
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null && $body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            return null;
        }

        return new \Workerman\Http\Response((int) $httpCode, [], $raw);
    }
}
