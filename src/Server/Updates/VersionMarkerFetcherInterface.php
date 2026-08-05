<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Updates;

/**
 * Non-blocking fetcher for a remote plain-text version marker (S74).
 *
 * The contract is deliberately CALLBACK-shaped rather than
 * `fetch(string $url): ?string`: phlix-server is a resident-memory Workerman
 * process, so the only implementation allowed to reach the network must hand
 * control back to the event loop and be resumed by it. A return-a-string
 * signature can only be honoured by blocking (`file_get_contents`, blocking
 * cURL) or by suspending a coroutine.
 *
 * ## Why not {@see \Phlix\Plugins\Catalog\PluginCatalogService::defaultFetcher()}
 *
 * That is the shape the step text prescribes, and it is the wrong one here. It
 * is a `callable(string $url, int $timeout): string` that FORKS on
 * `WorkerContext::inCoroutine()` (`PluginCatalogService.php:560-562`) and waits
 * on a `Swoole\Coroutine\Channel` on the async arm. Under PHPUnit
 * `Coroutine::getCid()` is always `-1`, so that arm is structurally
 * unreachable from the test suite while being exactly the arm production
 * takes — the S170 defect class. This interface has no such fork: there is one
 * code path, and the suite drives it.
 *
 * @package Phlix\Server\Updates
 * @since   S74 (core update check)
 */
interface VersionMarkerFetcherInterface
{
    /**
     * Fetch `$url` and invoke `$onDone` exactly once with the outcome.
     *
     * Implementations MUST NOT block the calling worker. `$onDone` receives
     * either the response body (first argument) or an error message (second
     * argument); exactly one of the two is non-null.
     *
     * @param string                                  $url    Absolute http(s) URL of the marker.
     * @param callable(string|null, string|null):void $onDone Completion callback: (body, error).
     *
     * @return void
     */
    public function fetch(string $url, callable $onDone): void;
}
