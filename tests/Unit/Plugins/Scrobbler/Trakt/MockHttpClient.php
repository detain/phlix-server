<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\HttpClientInterface;
use Phlix\Plugins\Scrobbler\Trakt\TraktApi;

/**
 * In-memory {@see HttpClientInterface} test double for the Trakt API client.
 *
 * Records the last request's method/url/headers/body (so outgoing headers — the
 * mandatory `trakt-api-key`/`trakt-api-version` pair — can be asserted) and
 * replays a queue of results. A queued array is returned as a decoded body; a
 * queued {@see \Throwable} is thrown when reached, so the 429 retry/backoff
 * loops in {@see TraktApi::getWatchedHistory()} / {@see TraktApi::getPlaybackProgress()}
 * can be exercised without a live server.
 */
final class MockHttpClient implements HttpClientInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';
    /** @var array<string, mixed> */
    public array $lastData = [];
    /** @var array<string, mixed> */
    public array $lastHeaders = [];
    public int $postCallCount = 0;

    /**
     * Queue of results. Each entry is either an array (a decoded response body)
     * or a {@see \Throwable} (thrown by the next call, to exercise the retry /
     * backoff loops in {@see TraktApi::getWatchedHistory()} /
     * {@see TraktApi::getPlaybackProgress()}).
     *
     * @var array<int, mixed>
     */
    private array $responses;
    private int $responseIndex = 0;

    /** @var array<int, array<string, string>> Response headers parallel to $responses. */
    private array $headerResponses;

    /**
     * @param array<int, mixed> $responses Queue of responses to return, in order.
     *   An array entry is returned as a decoded body; a {@see \Throwable} entry is
     *   thrown when reached (simulates a transport-level 429 / API error so the
     *   caller's retry loop can be asserted).
     * @param array<int, array<string, string>> $headerResponses Response headers
     *   parallel to $responses (indexed the same); missing entries default to [].
     */
    public function __construct(array $responses = [], array $headerResponses = [])
    {
        $this->responses = $responses;
        $this->headerResponses = $headerResponses;
    }

    public function get(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        return $this->getNextResponse();
    }

    public function getWithHeaders(string $url, array $params = [], array $headers = []): array
    {
        $this->lastMethod = 'GET';
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;

        if (!empty($params)) {
            $this->lastUrl .= '?' . http_build_query($params);
        }

        $index = $this->responseIndex;
        $body = $this->getNextResponse();

        return [
            'body' => $body,
            'headers' => $this->headerResponses[$index] ?? [],
        ];
    }

    public function post(string $url, array $data = [], array $headers = []): array
    {
        $this->lastMethod = 'POST';
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;
        ++$this->postCallCount;

        return $this->getNextResponse();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getNextResponse(): array
    {
        if ($this->responseIndex >= count($this->responses)) {
            return [];
        }

        $next = $this->responses[$this->responseIndex++];
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (!is_array($next)) {
            return [];
        }

        return $next;
    }
}
