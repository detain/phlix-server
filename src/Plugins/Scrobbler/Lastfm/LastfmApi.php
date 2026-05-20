<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP client for the Last.fm Web Service v2 (`https://ws.audioscrobbler.com/2.0/`).
 *
 * This client implements the three call types needed for scrobbling from
 * an authenticated user's session: `auth.getSession` (token exchange),
 * `track.updateNowPlaying` (real-time status) and `track.scrobble`
 * (history submission).
 *
 * ## Signature
 *
 * Last.fm signs every authenticated call. The signature input is the
 * alphabetically-sorted concatenation of all request parameters
 * (excluding `format` and `callback`), with their values appended, and
 * the shared secret tacked on at the end. The MD5 of that UTF-8 byte
 * string becomes the `api_sig` parameter. See the worked example in
 * {@see self::buildApiSig()}'s tests.
 *
 * The class is intentionally framework-agnostic — it talks HTTPS via
 * `file_get_contents` so test doubles can swap in a custom HTTP runner
 * without dragging cURL in.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
class LastfmApi
{
    /** Default Last.fm Web Service endpoint. */
    public const API_ROOT = 'https://ws.audioscrobbler.com/2.0/';

    private LoggerInterface $logger;

    /**
     * Pluggable HTTP runner for tests. Defaults to {@see self::defaultHttp()}.
     *
     * @var callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string}
     */
    private $http;

    /**
     * @param string                              $apiKey       Last.fm API key.
     * @param string                              $sharedSecret Shared secret used to sign requests.
     * @param LoggerInterface|null                $logger       Optional PSR-3 logger.
     * @param (callable(string, string, array<string, string>): array{status: int, body: string})|null $http
     *        Optional HTTP runner override (test hook).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $sharedSecret,
        ?LoggerInterface $logger = null,
        ?callable $http = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->http = $http ?? self::defaultHttp();
    }

    /**
     * Exchange a request token for a long-lived session.
     *
     * Mirrors Last.fm `auth.getSession`. The returned session key never
     * expires unless the user revokes it from their Last.fm settings.
     *
     * @param string $token Request token obtained by redirecting the user
     *                      through `https://www.last.fm/api/auth/?api_key=...&token=...`.
     *
     * @return array{session_key: string, username: string}|null
     *         Hydrated session payload, or null when Last.fm rejected the
     *         token.
     */
    public function getSession(string $token): ?array
    {
        $params = [
            'method'  => 'auth.getSession',
            'api_key' => $this->apiKey,
            'token'   => $token,
        ];
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->call($params);
        if ($response === null) {
            return null;
        }

        $session = $response['session'] ?? null;
        if (!is_array($session)) {
            return null;
        }
        $key = $session['key'] ?? null;
        $name = $session['name'] ?? null;
        if (!is_string($key) || $key === '' || !is_string($name) || $name === '') {
            return null;
        }
        return ['session_key' => $key, 'username' => $name];
    }

    /**
     * Notify Last.fm that the user has just begun listening to a track.
     *
     * Calls `track.updateNowPlaying`. Returns true on a 200 response with
     * no `error` field — false on any other outcome.
     *
     * @param string $sessionKey Authenticated session key from {@see getSession()}.
     * @param string $track      Track title.
     * @param string $artist     Artist name.
     * @param string|null $album Optional album name.
     */
    public function updateNowPlaying(
        string $sessionKey,
        string $track,
        string $artist,
        ?string $album = null,
    ): bool {
        $params = [
            'method'  => 'track.updateNowPlaying',
            'api_key' => $this->apiKey,
            'sk'      => $sessionKey,
            'track'   => $track,
            'artist'  => $artist,
        ];
        if ($album !== null && $album !== '') {
            $params['album'] = $album;
        }
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->call($params);
        return $response !== null && !isset($response['error']);
    }

    /**
     * Submit a single scrobble to Last.fm.
     *
     * Calls `track.scrobble`. Last.fm's scrobble rules
     * (>30s duration AND >50% played) are NOT enforced here — the caller
     * (see {@see LastfmScrobbler::onPlaybackStopped()}) is responsible
     * for that gating.
     *
     * @param string      $sessionKey Authenticated session key.
     * @param string      $track      Track title.
     * @param string      $artist     Artist name.
     * @param string|null $album      Optional album name.
     * @param int|null    $timestamp  Unix timestamp when the user started
     *                                listening. Defaults to `time()`.
     */
    public function scrobble(
        string $sessionKey,
        string $track,
        string $artist,
        ?string $album = null,
        ?int $timestamp = null,
    ): bool {
        $params = [
            'method'    => 'track.scrobble',
            'api_key'   => $this->apiKey,
            'sk'        => $sessionKey,
            'track'     => $track,
            'artist'    => $artist,
            'timestamp' => (string) ($timestamp ?? time()),
        ];
        if ($album !== null && $album !== '') {
            $params['album'] = $album;
        }
        $params['api_sig'] = $this->buildApiSig($params);
        $params['format']  = 'json';

        $response = $this->call($params);
        return $response !== null && !isset($response['error']);
    }

    /**
     * Build the `api_sig` parameter per the Last.fm authentication spec.
     *
     * Algorithm:
     *  1. Drop `format` and `callback` from the parameter set.
     *  2. Sort the remaining keys alphabetically.
     *  3. Concatenate `key . value` for each kept parameter, in order.
     *  4. Append the shared secret.
     *  5. UTF-8 encode then MD5 the resulting string.
     *
     * Exposed (package-private style — public but not part of the public
     * contract) so {@see LastfmApiTest} can exercise the worked example
     * from the Last.fm documentation directly.
     *
     * @param array<string, string> $params Parameter set destined for the API.
     *
     * @return string Lower-case hex-encoded MD5 of the signature input.
     */
    public function buildApiSig(array $params): string
    {
        $filtered = $params;
        unset($filtered['format'], $filtered['callback'], $filtered['api_sig']);
        ksort($filtered);

        $input = '';
        foreach ($filtered as $key => $value) {
            $input .= $key . $value;
        }
        $input .= $this->sharedSecret;

        return md5($input);
    }

    /**
     * Execute one Last.fm API call via POST and decode the JSON body.
     *
     * Returns null on any non-2xx status, a transport failure, or an
     * unparseable body — callers translate that to a `false` outcome.
     *
     * @param array<string, string> $params Final parameter set including `api_sig` + `format=json`.
     *
     * @return array<string, mixed>|null Decoded body, or null on failure.
     */
    private function call(array $params): ?array
    {
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $headers = [
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Content-Length' => (string) strlen($body),
            'User-Agent'     => 'phlix/last.fm (+https://github.com/detain/phlix-server)',
        ];

        $result = ($this->http)(self::API_ROOT, $body, $headers);
        $status = $result['status'] ?? 0;
        $payload = $result['body'] ?? '';

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Last.fm API returned non-2xx', [
                'status' => $status,
                'method' => $params['method'] ?? null,
            ]);
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $decodedArr */
        $decodedArr = $decoded;
        return $decodedArr;
    }

    /**
     * Default HTTP runner using PHP streams. Tests can override via the
     * constructor's `$http` parameter.
     *
     * @return callable(string $url, string $body, array<string, string> $headers): array{status: int, body: string}
     */
    private static function defaultHttp(): callable
    {
        return static function (string $url, string $body, array $headers): array {
            $headerLines = '';
            foreach ($headers as $name => $value) {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => $headerLines,
                    'content' => $body,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            $statusLine = isset($http_response_header[0]) && is_string($http_response_header[0])
                ? $http_response_header[0]
                : '';
            $status = 0;
            if ($statusLine !== '' && preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m) === 1) {
                $status = (int) $m[1];
            }
            return ['status' => $status, 'body' => is_string($response) ? $response : ''];
        };
    }
}
