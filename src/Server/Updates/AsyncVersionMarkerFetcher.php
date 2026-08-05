<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
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
     * Issue the non-blocking GET.
     *
     * Every failure mode — a synchronous throw from `parseAddress()`, a
     * transport error, an oversized body — is funnelled into `$onDone`'s error
     * argument, so a caller can rely on exactly one invocation and never has to
     * catch. A throw escaping here would land inside a Workerman timer callback
     * and take the worker's tick with it.
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
                'error'   => static function (mixed $exception) use ($complete): void {
                    $complete(null, self::describe($exception));
                },
            ]);
        } catch (Throwable $e) {
            $complete(null, $e->getMessage());
        }
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
