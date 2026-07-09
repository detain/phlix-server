<?php

/**
 * Phlix media server component: Arr.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Arr;

use Phlix\Shared\Arr\Transport\ArrTransportInterface;
use RuntimeException;

/**
 * Non-blocking *arr HTTP transport for the Phlix server's Swoole event loop.
 *
 * ## Why this exists
 *
 * phlix-shared's {@see \Phlix\Shared\Arr\AbstractArrClient} delegates all HTTP I/O
 * to an injected {@see ArrTransportInterface}. When none is injected it falls back
 * to the bundled, **blocking** {@see \Phlix\Shared\Arr\Transport\CurlArrTransport}
 * (documented CLI/test only). The phlix server runs on Swoole's event loop with a
 * deliberately curated coroutine hook mask ({@see \Phlix\Server\Runtime\SwooleRuntime})
 * that EXCLUDES `SWOOLE_HOOK_NATIVE_CURL` (and file IO). A blocking `curl_exec()` is
 * therefore NOT yielded by the scheduler — it stalls every coroutine on the worker
 * until the (potentially slow/unreachable) *arr instance responds.
 *
 * This transport instead uses {@see \Swoole\Coroutine\Http\Client}, a native
 * coroutine HTTP client that yields the current coroutine while waiting on the
 * socket — independent of any `SWOOLE_HOOK_*` flag (it is not a hooked native call,
 * it is coroutine-native by construction). A slow *arr instance therefore parks one
 * coroutine instead of freezing the worker.
 *
 * TLS peer + hostname verification is ON for `https://` targets (matching the
 * server's F2 metadata-TLS posture and the shared transport's default), so a MITM
 * cannot tamper with *arr API responses in transit.
 *
 * @package Phlix\Server\Arr
 * @since 0.34.0
 */
final class WorkermanArrTransport implements ArrTransportInterface
{
    /**
     * Default system CA bundle used for TLS peer verification of `https://` targets.
     */
    public const DEFAULT_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /**
     * @param int $timeout Overall request timeout in seconds.
     * @param string $caBundle CA bundle path used to verify `https://` peers.
     */
    public function __construct(
        private readonly int $timeout = 30,
        private readonly string $caBundle = self::DEFAULT_CA_BUNDLE,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * Executes the request through a {@see \Swoole\Coroutine\Http\Client}, which
     * yields the coroutine while awaiting the response rather than blocking the
     * worker. Returns the HTTP status code and raw response body; status mapping
     * (401/404/4xx) is the caller's concern, per the interface contract.
     *
     * @param array<string> $headers Headers as raw `Name: value` strings.
     * @return array{status:int, body:string}
     * @throws RuntimeException On a transport-level failure (bad URL, connect error).
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        if ($url === '') {
            throw new RuntimeException('WorkermanArrTransport: empty request URL');
        }
        if ($method === '') {
            throw new RuntimeException('WorkermanArrTransport: empty request method');
        }
        if (!class_exists(\Swoole\Coroutine\Http\Client::class)) {
            throw new RuntimeException(
                'WorkermanArrTransport requires the Swoole coroutine HTTP client (ext-swoole)'
            );
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException('WorkermanArrTransport: malformed request URL: ' . $url);
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme'])
            ? strtolower($parts['scheme'])
            : 'http';
        $host = is_string($parts['host']) ? $parts['host'] : '';
        $ssl = $scheme === 'https';
        $port = isset($parts['port']) && is_int($parts['port'])
            ? $parts['port']
            : ($ssl ? 443 : 80);

        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);

        $settings = [
            'timeout' => $this->timeout,
        ];
        if ($ssl) {
            // Verify the peer certificate + hostname; reject self-signed certs.
            $settings['ssl_verify_peer'] = true;
            $settings['ssl_allow_self_signed'] = false;
            $settings['ssl_host_name'] = $host;
            if ($this->caBundle !== '' && is_file($this->caBundle)) {
                $settings['ssl_cafile'] = $this->caBundle;
            }
        }
        $client->set($settings);

        $client->setMethod($method);
        $client->setHeaders($this->parseHeaders($headers));

        if ($body !== null && $body !== '') {
            $client->setData($body);
        }

        $ok = $client->execute($path);

        $statusCode = is_int($client->statusCode) ? $client->statusCode : 0;
        $errCode = is_int($client->errCode) ? $client->errCode : 0;
        $rawBody = is_string($client->body) ? $client->body : '';

        $client->close();

        if ($ok === false || $statusCode <= 0 || $errCode !== 0) {
            throw new RuntimeException(
                'WorkermanArrTransport: HTTP request to ' . $host . ' failed'
                . ($errCode !== 0 ? ' (errCode ' . $errCode . ')' : ''),
                $errCode
            );
        }

        return ['status' => $statusCode, 'body' => $rawBody];
    }

    /**
     * Converts raw `Name: value` header strings into the associative map that
     * {@see \Swoole\Coroutine\Http\Client::setHeaders()} expects.
     *
     * @param array<string> $headers
     * @return array<string, string>
     */
    private function parseHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $pos = strpos($header, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($header, 0, $pos));
            $value = trim(substr($header, $pos + 1));
            if ($name !== '') {
                $map[$name] = $value;
            }
        }

        return $map;
    }
}
