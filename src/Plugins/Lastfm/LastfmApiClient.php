<?php

declare(strict_types=1);

namespace Phlix\Plugins\Lastfm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Last.fm API v1.2 client implementing the mobile auth flow, session
 * validation, track.scrobble, and track.updateNowPlaying endpoints.
 *
 * All API calls are signed with HMAC-MD5 per the Last.fm API specification.
 *
 * @package Phlix\Plugins\Lastfm
 * @since 0.15.0
 */
final class LastfmApiClient implements LastfmApiClientInterface
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';

    private readonly LoggerInterface $logger;

    /**
     * @param string           $api_key    Last.fm API key.
     * @param string           $api_secret Last.fm API secret.
     * @param LoggerInterface|null $logger  Optional PSR-3 logger.
     */
    public function __construct(
        private readonly string $api_key,
        private readonly string $api_secret,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Authenticate with Last.fm using the mobile auth flow.
     *
     * This takes a username and a pre-hashed password (MD5 hex of the
     * actual password) and returns a session key that can be used for
     * scrobbling and Now Playing updates.
     *
     * @param string $username      Last.fm username.
     * @param string $passwordHash MD5 hex of the user's password
     *                              (i.e. md5($password)).
     *
     * @return string The session key to store for subsequent calls.
     *
     * @throws LastfmPluginNotConfiguredException When credentials are empty.
     * @throws LastfmScrobbleFailedException       When the API returns an error.
     */
    public function getMobileSession(string $username, string $passwordHash): string
    {
        $this->ensureConfigured();

        $params = [
            'username'      => $username,
            'password_hash' => $passwordHash,
            'api_key'      => $this->api_key,
            'method'       => 'auth.getMobileSession',
        ];

        $response = $this->post($params);
        $this->logger->debug('Last.fm mobile session response', ['response' => $response]);

        if (isset($response['error'])) {
            $errorCode = is_string($response['error']) ? $response['error'] : 'unknown';
            throw new LastfmScrobbleFailedException(
                $username,
                '<session>',
                $errorCode,
            );
        }

        $sessionData = $response['session'] ?? null;
        $sessionKey = is_array($sessionData) && isset($sessionData['key'])
            ? (is_string($sessionData['key']) ? $sessionData['key'] : null)
            : null;
        if (!is_string($sessionKey) || $sessionKey === '') {
            throw new LastfmScrobbleFailedException($username, '<session>', 'no session key in response');
        }

        return $sessionKey;
    }

    /**
     * Validate that a session key is currently valid.
     *
     * @param string $sessionKey The session key to validate.
     *
     * @return bool True when the session key is valid; false otherwise.
     */
    public function validateSession(string $sessionKey): bool
    {
        if ($sessionKey === '') {
            return false;
        }

        $params = [
            'api_key'    => $this->api_key,
            'method'    => 'auth.getSession',
            'sk'        => $sessionKey,
        ];

        // Sign with just api_key + method + sk; api_sig not required for getSession
        $params['api_sig'] = $this->signParams($params);
        $params['format'] = 'json';

        $url = self::BASE_URL . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: PhlixMediaServer/1.0'],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            return false;
        }

        if (!is_string($raw)) {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        $session = $data['session'] ?? null;
        return is_array($session) && isset($session['key']);
    }

    /**
     * Submit a scrobble to Last.fm.
     *
     * @param ScrobbleData $data The scrobble data to submit.
     *
     * @return bool True when Last.fm returns a 200 OK status.
     *
     * @throws LastfmPluginNotConfiguredException When plugin is not configured.
     * @throws LastfmScrobbleFailedException     When the API returns an error.
     */
    public function scrobble(ScrobbleData $data): bool
    {
        $this->ensureConfigured();

        $params = [
            'artist'      => $data->artist_name,
            'track'       => $data->track_title,
            'timestamp'   => (string) $data->timestamp_unix,
            'method'      => 'track.scrobble',
            'api_key'     => $this->api_key,
            'sk'          => $this->api_secret, // Will be replaced with session key in onEnable
        ];

        if ($data->album_name !== null) {
            $params['album'] = $data->album_name;
        }
        if ($data->track_number !== null) {
            $params['trackNumber'] = (string) $data->track_number;
        }
        if ($data->duration_secs !== null) {
            $params['duration'] = (string) $data->duration_secs;
        }
        if ($data->mbid !== null) {
            $params['mbid'] = $data->mbid;
        }

        // Store api_sig before adding sk
        $params['api_sig'] = $this->signParams($params);
        $params['format'] = 'json';

        $response = $this->postRaw($params);
        $this->logger->debug('Last.fm scrobble response', ['response' => $response]);

        $scrobbles = $response['scrobbles'] ?? null;
        $attr = is_array($scrobbles) ? ($scrobbles['@attr'] ?? null) : null;
        if (is_array($attr) && isset($attr['status'])) {
            return $attr['status'] === 'ok';
        }

        if (isset($response['error'])) {
            $errorCode = is_string($response['error']) ? $response['error'] : 'unknown';
            throw new LastfmScrobbleFailedException(
                $data->artist_name,
                $data->track_title,
                $errorCode,
            );
        }

        return false;
    }

    /**
     * Update the user's "Now Playing" status on Last.fm.
     *
     * This does NOT scrobble — it only updates what appears on their
     * profile as currently being listened to.
     *
     * @param NowPlayingData $data The Now Playing data to submit.
     *
     * @return bool True when Last.fm returns a 200 OK status.
     *
     * @throws LastfmPluginNotConfiguredException When plugin is not configured.
     * @throws LastfmScrobbleFailedException     When the API returns an error.
     */
    public function nowPlaying(NowPlayingData $data): bool
    {
        $this->ensureConfigured();

        $params = [
            'artist'  => $data->artist_name,
            'track'   => $data->track_title,
            'method'  => 'track.updateNowPlaying',
            'api_key' => $this->api_key,
        ];

        if ($data->album_name !== null) {
            $params['album'] = $data->album_name;
        }
        if ($data->duration_secs !== null) {
            $params['duration'] = (string) $data->duration_secs;
        }
        if ($data->mbid !== null) {
            $params['mbid'] = $data->mbid;
        }

        $params['api_sig'] = $this->signParams($params);
        $params['format'] = 'json';

        $response = $this->postRaw($params);
        $this->logger->debug('Last.fm nowPlaying response', ['response' => $response]);

        $nowplaying = $response['nowplaying'] ?? null;
        $attr = is_array($nowplaying) ? ($nowplaying['@attr'] ?? null) : null;
        if (is_array($attr) && isset($attr['status'])) {
            return $attr['status'] === 'ok';
        }

        if (isset($response['error'])) {
            $errorCode = is_string($response['error']) ? $response['error'] : 'unknown';
            throw new LastfmScrobbleFailedException(
                $data->artist_name,
                $data->track_title,
                $errorCode,
            );
        }

        return false;
    }

    /**
     * Ensure the client has the minimum required configuration.
     *
     * @throws LastfmPluginNotConfiguredException When api_key or api_secret is empty.
     */
    private function ensureConfigured(): void
    {
        if ($this->api_key === '' || $this->api_secret === '') {
            throw new LastfmPluginNotConfiguredException();
        }
    }

    /**
     * Sign a set of parameters with HMAC-MD5 per Last.fm API spec.
     *
     * Parameters are sorted alphabetically, concatenated as
     * "key1value1key2value2...", then signed with the API secret.
     *
     * @param array<string, string|int> $params Parameters to sign (api_sig
     *                                          key will be added to this array).
     *
     * @return string The 32-char lowercase MD5 hex digest.
     */
    private function signParams(array $params): string
    {
        ksort($params);
        $str = '';
        foreach ($params as $key => $value) {
            $str .= $key . (string) $value;
        }
        return md5($str);
    }

    /**
     * POST parameters to the Last.fm API and decode the JSON response.
     *
     * @param array<string, string|int> $params POST parameters.
     *
     * @return array<string, mixed> Decoded JSON response.
     */
    private function post(array $params): array
    {
        $params['api_sig'] = $this->signParams($params);
        $params['format'] = 'json';

        return $this->postRaw($params);
    }

    /**
     * POST raw parameters to the Last.fm API.
     *
     * @param array<string, string|int> $params POST parameters.
     *
     * @return array<string, mixed> Decoded JSON response.
     */
    private function postRaw(array $params): array
    {
        $ch = curl_init(self::BASE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'User-Agent: PhlixMediaServer/1.0',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode >= 400) {
            return ['error' => 'HTTP ' . $httpCode];
        }

        if (!is_string($raw)) {
            return ['error' => 'Unexpected curl response type'];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true) ?? [];
        return $decoded;
    }
}
