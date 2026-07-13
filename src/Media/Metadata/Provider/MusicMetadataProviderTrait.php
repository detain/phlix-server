<?php

/**
 * Phlix media server component: Provider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Provider;

use Psr\Log\LoggerInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * MusicMetadataProviderTrait provides shared logic for music metadata providers.
 *
 * This trait handles rate-limiting enforcement (required by MusicBrainz) and
 * provides the required MusicBrainz user-agent headers.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @since 0.13.0
 */
trait MusicMetadataProviderTrait
{
    /**
     * Last-request timestamps keyed by target API host, SHARED across every
     * provider instance (SV-4.5 / S-F16).
     *
     * The rate limit is a property of the remote host (MusicBrainz allows ~1
     * request/second per client), so the state must be shared rather than
     * per-object — otherwise N concurrent provider instances collectively
     * exceed the limit. Bounded by {@see self::RATE_LIMIT_HOST_CAP} with
     * LRU eviction so it cannot grow without limit in a resident worker.
     *
     * @var array<string, float>
     */
    private static array $hostLastRequestTime = [];

    /** @var int Max distinct hosts tracked before LRU eviction. */
    private const RATE_LIMIT_HOST_CAP = 64;

    /** @var \Phlix\Common\Logger\StructuredLogger|null Structured logger instance */
    private ?\Phlix\Common\Logger\StructuredLogger $logger = null;

    /**
     * Apply rate limiting delay before making a request.
     *
     * MusicBrainz requires at least 1 request per second. The limiter state is
     * static-per-host (shared across all provider instances hitting the same
     * host) and the wait is coroutine-aware: inside a Swoole coroutine it uses
     * a cooperative {@see \Swoole\Coroutine::sleep()} that yields the event
     * loop; outside a coroutine (CLI/tests) it falls back to blocking usleep.
     *
     * @param float $seconds Minimum time between requests in seconds
     * @return void
     */
    protected function rateLimit(float $seconds): void
    {
        $host = $this->rateLimitBucket();
        $last = self::$hostLastRequestTime[$host] ?? 0.0;
        $now = microtime(true);

        if ($last > 0.0) {
            $elapsed = $now - $last;
            if ($elapsed < $seconds) {
                $this->rateLimitSleep($seconds - $elapsed);
            }
        }

        // Refresh timestamp; unset+reassign keeps the freshest host at the
        // logical "end" of the array so array_key_first() finds the LRU victim.
        unset(self::$hostLastRequestTime[$host]);
        self::$hostLastRequestTime[$host] = microtime(true);

        // Bound the map: evict the oldest (least-recently-used) host.
        if (count(self::$hostLastRequestTime) > self::RATE_LIMIT_HOST_CAP) {
            $oldest = array_key_first(self::$hostLastRequestTime);
            if ($oldest !== null) {
                unset(self::$hostLastRequestTime[$oldest]);
            }
        }
    }

    /**
     * Derive the rate-limiter bucket key (the target API host) for the
     * concrete provider using this trait.
     *
     * Keys off the provider's `BASE_URL` host when defined so that two
     * instances of the same provider (or different providers hitting the same
     * host) share one bucket, while distinct hosts get independent limits.
     *
     * @return string Lower-cased host, or the class name as a stable fallback.
     */
    private function rateLimitBucket(): string
    {
        $constName = static::class . '::BASE_URL';
        if (defined($constName)) {
            $base = constant($constName);
            if (is_string($base) && $base !== '') {
                $parsed = parse_url($base, PHP_URL_HOST);
                if (is_string($parsed) && $parsed !== '') {
                    return strtolower($parsed);
                }
            }
        }

        return strtolower(static::class);
    }

    /**
     * Sleep for the rate-limit backoff without blocking the event loop when
     * running inside a Swoole coroutine.
     *
     * Uses the shared {@see \Phlix\Common\Runtime\WorkerContext::inCoroutine()}
     * guard (the same coroutine-vs-blocking decision every async client here
     * uses) so the choice is consistent and testable. Only inside a live
     * coroutine may {@see \Swoole\Coroutine::sleep()} be called; elsewhere we
     * fall back to blocking usleep.
     *
     * @param float $seconds Sleep duration in seconds.
     * @return void
     */
    private function rateLimitSleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        if (\Phlix\Common\Runtime\WorkerContext::inCoroutine()) {
            \Swoole\Coroutine::sleep($seconds);
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Get the required headers for MusicBrainz API requests.
     *
     * MusicBrainz requires a User-Agent header with contact information
     * and enforces rate limiting. This method returns the required
     * headers for compliance.
     *
     * @param string $userAgent User-agent string (e.g., 'Phlix/1.0 (https://phlix.media)')
     * @return array<string, string> Headers array with user-agent and content-type
     */
    protected function mbHeaders(string $userAgent): array
    {
        return [
            'User-Agent' => $userAgent,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Get the logger instance.
     *
     * @return \Phlix\Common\Logger\StructuredLogger
     */
    protected function getLogger(): \Phlix\Common\Logger\StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::MEDIA);
        }
        return $this->logger;
    }

    /**
     * Set logger instance (for testing).
     *
     * @param LoggerInterface|null $logger
     * @return void
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger ? LoggerFactory::get(LogChannels::MEDIA) : null;
    }
}
