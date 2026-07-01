<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\ThemeMusic;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;

/**
 * Default {@see ThemeMusicFetcherInterface} using PHP streams.
 *
 * Mirrors {@see \Phlix\Media\Metadata\MetadataHttpClient}'s outbound approach:
 * a `file_get_contents()` GET over a verified-TLS stream context. Under the
 * server's Swoole runtime the `file_get_contents` call is transparently
 * non-blocking (SWOOLE_HOOK_ALL), so this stays coroutine-friendly without a
 * bespoke async client. The HTTP status line is read from `$http_response_header`
 * (populated by the stream wrapper) so a `404` / `500` is treated as "no theme"
 * rather than caching an error page.
 *
 * Never throws: every failure path returns null.
 *
 * @since 0.66.0
 */
final class StreamThemeMusicFetcher implements ThemeMusicFetcherInterface
{
    /** Descriptive UA so the Plex archive can attribute the traffic. */
    private const USER_AGENT = 'Phlix-Server/ThemeMusic (+https://phlix.tv)';

    private StructuredLogger $logger;

    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * @inheritDoc
     */
    public function fetch(string $url, int $timeoutSeconds): ?string
    {
        $timeout = $timeoutSeconds > 0 ? $timeoutSeconds : 8;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 3,
                'ignore_errors' => true,
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\nAccept: audio/mpeg,*/*",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        // $http_response_header is populated by the stream wrapper in the local
        // scope of this call.
        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);

        if ($body === false || $body === '') {
            $this->logger->debug('ThemeMusic: fetch failed or empty body', [
                'url' => $url,
                'error' => error_get_last()['message'] ?? null,
            ]);
            return null;
        }

        $status = self::statusFromHeaders($http_response_header);
        if ($status !== 200) {
            $this->logger->debug('ThemeMusic: fetch non-200', [
                'url' => $url,
                'status' => $status,
            ]);
            return null;
        }

        return $body;
    }

    /**
     * Parse the numeric HTTP status from a `$http_response_header` array. Returns
     * 0 when no status line is present so the caller treats it as a failure.
     *
     * @param array<int, string> $headers Raw response header lines.
     */
    private static function statusFromHeaders(array $headers): int
    {
        // Redirects prepend additional status lines; the LAST `HTTP/…` line
        // reflects the final response.
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }
}
