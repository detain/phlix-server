<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Updates;

use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use RuntimeException;

/**
 * A {@see VersionMarkerFetcherInterface} that calls back SYNCHRONOUSLY with a
 * fixed outcome and records every URL it was asked for (S74).
 *
 * A named class rather than an anonymous one so `$fetcher->urls` is a typed
 * property PHPStan can see: an anonymous class handed back as the interface
 * type loses its own members at every call site.
 *
 * @package Phlix\Tests\Support\Updates
 */
final class RecordingVersionMarkerFetcher implements VersionMarkerFetcherInterface
{
    /**
     * Every URL passed to {@see fetch()}, in order.
     *
     * @var list<string>
     */
    public array $urls = [];

    /**
     * @param string|null $body  Body to report, or null.
     * @param string|null $error Error to report, or null.
     * @param bool        $throw When true, {@see fetch()} throws instead of calling back.
     */
    public function __construct(
        private ?string $body = '1.2.2',
        private ?string $error = null,
        private readonly bool $throw = false,
    ) {
    }

    /**
     * Change the outcome AFTER construction — needed when the fetcher is bound
     * into a DI container before the test knows what it wants it to return.
     *
     * @param string|null $body  Body to report, or null.
     * @param string|null $error Error to report, or null.
     *
     * @return void
     */
    public function willReturn(?string $body, ?string $error = null): void
    {
        $this->body = $body;
        $this->error = $error;
    }

    /**
     * @param string                                  $url    Marker URL.
     * @param callable(string|null, string|null):void $onDone Completion callback.
     *
     * @return void
     */
    public function fetch(string $url, callable $onDone): void
    {
        $this->urls[] = $url;

        if ($this->throw) {
            throw new RuntimeException('transport exploded');
        }

        $onDone($this->body, $this->error);
    }
}
