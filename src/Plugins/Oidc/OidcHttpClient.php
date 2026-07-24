<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Phlix\Common\Http\EventLoopTls;
use Phlix\Common\Runtime\WorkerContext;
use Psr\Http\Message\ResponseInterface;
use Workerman\Http\Client;

/**
 * Non-blocking HTTP client for the OIDC provider flow.
 *
 * OIDC performs four outbound HTTP calls, all of which used to block the
 * resident Workerman worker with `file_get_contents()` / `curl_exec()`:
 *
 *  - discovery document  ({@see DiscoveryDocument::fetchDiscoveryDocument()})
 *  - token exchange      ({@see OidcProvider::exchangeCode()})
 *  - userinfo            ({@see OidcProvider::authenticateWithAccessToken()})
 *  - JWKS                ({@see IdTokenValidator::fetchJwks()})
 *
 * This client replaces those with the SAME cooperative-wait pattern used by
 * {@see \Phlix\Media\Metadata\MetadataHttpClient}, {@see \Phlix\Hub\HttpClient}
 * and {@see \Phlix\Admin\S3Client}: an async {@see Client} request whose
 * success/error callbacks push to a {@see \Swoole\Coroutine\Channel} that the
 * caller `pop()`s — yielding to the event loop instead of stalling it.
 *
 * When there is no running event loop / coroutine (CLI, tests) OR the URL is a
 * TLS endpoint that stalls under the Swoole loop ({@see EventLoopTls}), it
 * transparently falls back to a bounded, TLS-verifying blocking cURL request —
 * mirroring the exact branch selection in {@see MetadataHttpClient::get()}.
 *
 * Not `final`: the concrete class is injected into the OIDC classes so tests can
 * substitute a mock and exercise the flow with no real network.
 *
 * @package Phlix\Plugins\Oidc
 * @since 0.99.0
 */
class OidcHttpClient
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
     * context, exactly as {@see MetadataHttpClient::get()} does.
     *
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        // SV-0.4: the async path waits on a Swoole\Coroutine\Channel, valid only
        // inside a coroutine (getCid() > 0). Outside one, Channel::pop() returns
        // false immediately (a false timeout), so we must use blocking cURL. And
        // https + Swoole event loop stalls TLS reads (see EventLoopTls), so those
        // take the blocking path too.
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
     * Peer + hostname verification are asserted explicitly: an OIDC discovery/
     * JWKS/token response fetched over an unverified channel would let a MITM
     * forge the issuer's keys and mint accepted ID tokens.
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
