<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Updates;

use Throwable;
use Workerman\Http\Client;

/**
 * The production {@see VersionMarkerFetcherInterface}: a `workerman/http-client`
 * GET driven entirely by the worker's event loop (S74).
 *
 * ## Why this shape
 *
 * `Client::request($url, ['success' => …, 'error' => …])` with BOTH callbacks
 * supplied never enters `Client::request()`'s coroutine branch — that branch is
 * guarded by `!isset($options['success']) && Coroutine::isCoroutine()`
 * (vendor `workerman/http-client/src/Client.php:78`, verified in this tree).
 * The request is queued, the socket is driven by the Workerman event loop, and
 * the callback fires later on that same loop. So:
 *
 *  - nothing blocks the worker (no `file_get_contents`, no blocking cURL);
 *  - there is NO `inCoroutine()` fork, therefore no arm of this class that
 *    production takes and the test suite cannot;
 *  - the caller never waits on a `Swoole\Coroutine\Channel`, which outside a
 *    coroutine returns false immediately and fakes a timeout.
 *
 * The {@see Client} is created LAZILY on first fetch and then reused: its
 * constructor builds a connection pool, and one pooled client per server
 * process is bounded (it is not a per-request object, so it is not a
 * resident-memory leak).
 *
 * ## Fastly fallback
 *
 * `raw.githubusercontent.com` is served from GitHub's Fastly anycast POPs. On
 * the operator host observed 2026-11-05 a read against one POP
 * (185.199.111.133:443) times out: the TCP connection completes but no bytes
 * return within the socket timeout. Workerman's `AsyncTcpConnection`/
 * `ConnectionPool` dials ONLY the first `getaddrinfo` answer, so a single dead
 * POP poisons every fetch regardless of the other three addresses — the marker
 * leg is unrecoverable from that host by retry alone.
 *
 * So a FAILED fetch of a raw-github marker re-issues ONCE against
 * `api.github.com` (`/repos/{owner}/{repo}/contents/{path}?ref={ref}` with
 * `Accept: application/vnd.github.raw`), which lives on GitHub's own
 * `140.82.x` infrastructure — NOT Fastly — and is therefore reachable on the
 * exact path where the raw host is not. The raw-accept response is the bare
 * file bytes, so {@see MAX_BODY_BYTES} and the downstream `MARKER_PATTERN` are
 * unaffected. There is no ladder: one fallback leg, and its failure completes
 * with both messages combined. The ref is taken from the primary URL, so a
 * dev-channel (master) marker stays a master marker.
 *
 * @package Phlix\Server\Updates
 * @since   S74 (core update check)
 */
final class AsyncVersionMarkerFetcher implements VersionMarkerFetcherInterface
{
    /**
     * Hard cap on a marker body, in bytes.
     *
     * A `VERSION` file is a handful of bytes; anything larger is a captive
     * portal, an error page, or a redirect body, and must not be parsed or
     * persisted. Applied before {@see CoreUpdateCheckService} ever sees it.
     */
    public const MAX_BODY_BYTES = 256;

    /**
     * Matches a raw-github marker URL and captures its parts.
     *
     * `owner`, `repo` and `ref` never contain a slash on GitHub's raw host;
     * `path` may (e.g. `scripts/install.sh`), so the path group is greedy and
     * the preceding three are bounded by a slash. Scheme `https` and host
     * `raw.githubusercontent.com` are pinned exactly: anything else (an
     * operator mirror, a plain-URL override) fails this match and gets NO
     * fallback leg.
     */
    private const RAW_GITHUB_URL = '#^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/([^/]+)/(.+)$#';

    /**
     * `Accept` value that makes `api.github.com` return the bare file bytes
     * instead of the JSON content envelope, so the fallback body is
     * byte-for-byte what the raw leg would have returned.
     */
    private const GITHUB_RAW_ACCEPT = 'application/vnd.github.raw';

    /** Lazily-created shared HTTP client (one per process). */
    private ?Client $client = null;

    /**
     * @param int         $timeoutSeconds Socket timeout handed to the client.
     * @param Client|null $client         Pre-built client (tests inject a double).
     */
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        ?Client $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * Issue the non-blocking GET, with one Fastly fallback leg on failure.
     *
     * Every failure mode — a synchronous throw from `parseAddress()`, a
     * transport error, an oversized body — is funnelled into `$onDone`'s error
     * argument, so a caller can rely on exactly one invocation and never has to
     * catch. A throw escaping here would land inside a Workerman timer callback
     * and take the worker's tick with it.
     *
     * A transport error on a raw-github marker re-issues once against
     * `api.github.com` (see the class docblock); the fallback runs through the
     * SAME `$complete`, so the single-shot `$done` latch still bounds the
     * caller to exactly one completion no matter how many legs fire.
     *
     * @param string                                  $url    Absolute http(s) URL of the marker.
     * @param callable(string|null, string|null):void $onDone Completion callback: (body, error).
     *
     * @return void
     */
    public function fetch(string $url, callable $onDone): void
    {
        $done = false;
        $complete = static function (?string $body, ?string $error) use (&$done, $onDone): void {
            if ($done) {
                return;
            }
            $done = true;
            $onDone($body, $error);
        };

        try {
            $client = $this->client ??= new Client(['timeout' => $this->timeoutSeconds]);
            $this->requestMarker($client, $url, $complete);
        } catch (Throwable $e) {
            $complete(null, $e->getMessage());
        }
    }

    /**
     * Issue one marker GET. On a transport error whose URL is the raw-github
     * shape, re-issue once against `api.github.com`; any other error completes
     * as-is. A synchronous throw from the fallback leg is folded into the
     * combined error rather than escaping the primary's callback.
     *
     * @param Client                                   $client    The (possibly double) HTTP client.
     * @param string                                   $url       Primary marker URL.
     * @param callable(string|null, string|null):void  $complete  Single-shot completion latch.
     */
    private function requestMarker(Client $client, string $url, callable $complete): void
    {
        // `request()` rather than `get()`: supplying BOTH callbacks is what
        // keeps Client::request() out of its coroutine-suspending branch
        // (vendor Client.php:78), and the options array carries them
        // without relying on `get()`'s `@param null` signature.
        $client->request($url, [
            'method'  => 'GET',
            'success' => static function (mixed $response) use ($complete): void {
                // Destructured rather than spread: an argument-unpack against
                // a Closure-typed variable is opaque to static analysis (the
                // parameter list is not related to the unpacked list), so the
                // pair is bound to typed locals and passed positionally.
                // `$complete(...self::readBody($response))` makes Psalm emit
                // TooFewArguments + MixedArgument even though readBody()'s
                // return shape is exact — this cost S75 a red gate.
                [$body, $error] = self::readBody($response);
                $complete($body, $error);
            },
            'error'   => function (mixed $exception) use ($client, $url, $complete): void {
                $fallback = self::apiContentsFallback($url);
                if ($fallback === null) {
                    $complete(null, self::describe($exception));
                    return;
                }

                $primaryError = self::describe($exception);
                $this->requestFallback($client, $fallback, $primaryError, $complete);
            },
        ]);
    }

    /**
     * The single `api.github.com` fallback leg. A success here is a clean
     * status; an error (transport OR a synchronous throw) completes with both
     * legs' messages combined so the operator sees the primary timeout that
     * triggered the fallback and the reason the backup host also failed.
     *
     * @param Client                                   $client        The (possibly double) HTTP client.
     * @param string                                   $fallbackUrl   Derived api.github.com contents URL.
     * @param string                                   $primaryError  Primary leg's error message.
     * @param callable(string|null, string|null):void  $complete      Single-shot completion latch.
     */
    private function requestFallback(
        Client $client,
        string $fallbackUrl,
        string $primaryError,
        callable $complete,
    ): void {
        $onFallbackError = static function (mixed $exception) use ($complete, $primaryError): void {
            $complete(null, $primaryError . ' (fallback: ' . self::describe($exception) . ')');
        };

        try {
            $client->request($fallbackUrl, [
                'method'  => 'GET',
                'headers' => ['Accept' => self::GITHUB_RAW_ACCEPT],
                'success' => static function (mixed $response) use ($complete): void {
                    [$body, $error] = self::readBody($response);
                    $complete($body, $error);
                },
                'error'   => $onFallbackError,
            ]);
        } catch (Throwable $e) {
            $onFallbackError($e);
        }
    }

    /**
     * Derive the `api.github.com` contents URL for a raw-github marker URL, or
     * null when the URL is not that shape. The ref is carried verbatim from
     * the primary URL so the fallback reads the same branch/tag.
     *
     * @param string $url Primary marker URL.
     *
     * @return string|null The fallback URL, or null if not a raw-github URL.
     */
    private static function apiContentsFallback(string $url): ?string
    {
        if (preg_match(self::RAW_GITHUB_URL, $url, $m) !== 1) {
            return null;
        }

        [$owner, $repo, $ref, $path] = [$m[1], $m[2], $m[3], $m[4]];

        return 'https://api.github.com/repos/' . $owner . '/' . $repo
            . '/contents/' . $path . '?ref=' . $ref;
    }

    /**
     * Normalise a PSR-7-ish response into `[body, error]`.
     *
     * @param mixed $response Whatever the client handed the success callback.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function readBody(mixed $response): array
    {
        if (!is_object($response) || !method_exists($response, 'getBody')) {
            return [null, 'update check: unexpected response object'];
        }

        try {
            /** @var mixed $stream */
            $stream = $response->getBody();
            $body   = is_scalar($stream) || (is_object($stream) && method_exists($stream, '__toString'))
                ? (string) $stream
                : '';
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return [null, 'update check: version marker exceeds ' . self::MAX_BODY_BYTES . ' bytes'];
        }

        return [$body, null];
    }

    /**
     * Human-readable description of whatever the error callback received.
     *
     * @param mixed $exception Throwable, string, or anything else.
     *
     * @return string
     */
    private static function describe(mixed $exception): string
    {
        if ($exception instanceof Throwable) {
            return $exception->getMessage();
        }
        if (is_string($exception) && $exception !== '') {
            return $exception;
        }

        return 'update check: version marker fetch failed';
    }
}
