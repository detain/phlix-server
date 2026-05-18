<?php

declare(strict_types=1);

namespace Phlex\Chromecast;

use Phlex\Common\Logger\StructuredLogger;

/**
 * HTTP/JSON Cast protocol client.
 *
 * Implements the Google Cast protocol for communicating with Chromecast
 * devices over HTTP. Uses the Default Media Receiver app (CC1AD845)
 * for media playback control.
 *
 * Cast protocol reference:
 * https://developers.google.com/cast/docs/reference/chromium/
 *
 * @since 0.12.0
 */
class CastApiClient
{
    /** Default Media Receiver app ID */
    public const APP_ID_DEFAULT = 'CC1AD845';

    /** @var string Device host IP or hostname */
    private string $deviceHost;

    /** @var int Device API port */
    private int $devicePort;

    /** @var StructuredLogger|null */
    private ?StructuredLogger $logger;

    /**
     * @param string $deviceHost Device host IP or hostname
     * @param int $devicePort Device API port (default: 8009)
     * @param StructuredLogger|null $logger Optional logger instance
     *
     * @since 0.12.0
     */
    public function __construct(string $deviceHost, int $devicePort = 8009, ?StructuredLogger $logger = null)
    {
        $this->deviceHost = $deviceHost;
        $this->devicePort = $devicePort;
        $this->logger = $logger;
    }

    /**
     * Get device API version and transport ID.
     *
     * Fetches `/setup/eureka_info` to get device information including
     * name, model, and UUID.
     *
     * @return array<string, mixed> Device info array
     *
     * @since 0.12.0
     */
    public function connect(): array
    {
        $response = $this->sendHttpRequest('/setup/eureka_info', []);

        return $response;
    }

    /**
     * Launch an app by name or ID.
     *
     * Posts to `/apps/{appId}` to launch a receiver app
     * and obtain the transport ID for subsequent commands.
     *
     * @param string $appId App ID to launch (e.g., 'CC1AD845' for Default Media Receiver)
     *
     * @return array<string, mixed> Launch response with transport ID
     *
     * @since 0.12.0
     */
    public function launchApp(string $appId): array
    {
        return $this->sendHttpRequest('/apps/' . $appId, []);
    }

    /**
     * Get the current app sessions (media control URLs).
     *
     * @return array<string, mixed> Session info
     *
     * @since 0.12.0
     */
    public function getAppSessions(): array
    {
        return $this->sendHttpRequest('/apps', []);
    }

    /**
     * Load media into the receiver.
     *
     * Sends the media URL and content type to the Chromecast's
     * media receiver endpoint.
     *
     * @param string $mediaUrl Media URL to cast
     * @param string $mimeType MIME content type (e.g., 'application/x-mpegurl')
     * @param array<string, mixed> $metadata Optional media metadata
     *
     * @return array<string, mixed> Load response
     *
     * @since 0.12.0
     */
    public function loadMedia(string $mediaUrl, string $mimeType, array $metadata = []): array
    {
        $body = [
            'contentId' => $mediaUrl,
            'contentType' => $mimeType,
            'streamType' => 'LIVE',
        ];

        if (!empty($metadata)) {
            $body['metadata'] = $metadata;
        }

        $path = '/apps/' . self::APP_ID_DEFAULT . '/media';
        return $this->sendHttpRequest($path, $body);
    }

    /**
     * Send a media command (PLAY, PAUSE, STOP, SEEK).
     *
     * @param string $command Command to send
     * @param array<string, mixed> $params Optional command parameters
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function sendMediaCommand(string $command, array $params = []): array
    {
        $body = array_merge(['command' => $command], $params);

        $path = '/apps/' . self::APP_ID_DEFAULT . '/media';
        return $this->sendHttpRequest($path, $body);
    }

    /**
     * Get current media status.
     *
     * @return array<string, mixed> Media status response
     *
     * @since 0.12.0
     */
    public function getMediaStatus(): array
    {
        return $this->sendHttpRequest('/apps/' . self::APP_ID_DEFAULT . '/media', []);
    }

    /**
     * Send an HTTP POST request to the Cast device API.
     *
     * Uses PHP's stream_socket_client for network communication.
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $body Request body as associative array
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws \RuntimeException If request fails
     */
    private function sendHttpRequest(string $path, array $body = []): array
    {
        $url = sprintf('http://%s:%d%s', $this->deviceHost, $this->devicePort, $path);

        $jsonBody = empty($body) ? '' : json_encode($body);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'content' => $jsonBody,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $this->log('debug', 'Sending HTTP request', ['url' => $url, 'body' => $body]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException(
                'Cast API request failed: ' . ($error['message'] ?? 'Unknown error') . ' for URL: ' . $url
            );
        }

        // Parse response headers to check status
        $responseHeaders = $http_response_header;
        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($statusCode >= 400) {
            throw new \RuntimeException("Cast API returned HTTP {$statusCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('warning', 'Failed to decode JSON response', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 200),
            ]);
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string> $headers Response headers
     *
     * @return int HTTP status code (default 0 if not found)
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $header, $matches)) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    /**
     * Log a message if logger is available.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Message to log
     * @param array<string, mixed> $context Context data
     *
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $formatted = 'CastApiClient: ' . $message;
        match ($level) {
            'debug' => $this->logger->debug($formatted, $context),
            'info' => $this->logger->info($formatted, $context),
            'warning' => $this->logger->warning($formatted, $context),
            'error' => $this->logger->error($formatted, $context),
            default => $this->logger->info($formatted, $context),
        };
    }
}
