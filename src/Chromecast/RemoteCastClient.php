<?php

declare(strict_types=1);

namespace Phlex\Chromecast;

use Phlex\Hub\RelayConsumer;

/**
 * Cast via relay tunnel.
 *
 * Proxies Cast protocol commands over the relay tunnel (RelayConsumer)
 * for casting to Chromecast devices behind NAT or on remote networks.
 *
 * @since 0.12.0
 */
class RemoteCastClient
{
    /** @var RelayConsumer Relay consumer for tunnel communication */
    private RelayConsumer $relay;

    /** @var string Target device ID */
    private string $deviceId;

    /**
     * @param RelayConsumer $relay Relay consumer for tunnel communication
     * @param string $deviceId Target Chromecast device ID
     *
     * @since 0.12.0
     */
    public function __construct(RelayConsumer $relay, string $deviceId)
    {
        $this->relay = $relay;
        $this->deviceId = $deviceId;
    }

    /**
     * Launch the Default Media Receiver app via relay.
     *
     * @return array<string, mixed> Launch response
     *
     * @since 0.12.0
     */
    public function launchApp(): array
    {
        return $this->sendRelayCommand('launchApp', [
            'device_id' => $this->deviceId,
            'app_id' => CastApiClient::APP_ID_DEFAULT,
        ]);
    }

    /**
     * Load media via relay tunnel.
     *
     * @param string $url Media URL to cast
     * @param string $mimeType MIME content type
     * @param array<string, mixed> $metadata Optional media metadata
     *
     * @return array<string, mixed> Load response
     *
     * @since 0.12.0
     */
    public function loadMedia(string $url, string $mimeType, array $metadata = []): array
    {
        return $this->sendRelayCommand('loadMedia', [
            'device_id' => $this->deviceId,
            'media_url' => $url,
            'mime_type' => $mimeType,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Resume playback via relay.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function play(): array
    {
        return $this->sendRelayCommand('play', [
            'device_id' => $this->deviceId,
        ]);
    }

    /**
     * Pause playback via relay.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        return $this->sendRelayCommand('pause', [
            'device_id' => $this->deviceId,
        ]);
    }

    /**
     * Stop playback via relay.
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        return $this->sendRelayCommand('stop', [
            'device_id' => $this->deviceId,
        ]);
    }

    /**
     * Seek via relay.
     *
     * @param int $positionMs Position in milliseconds
     *
     * @return array<string, mixed> Command response
     *
     * @since 0.12.0
     */
    public function seek(int $positionMs): array
    {
        return $this->sendRelayCommand('seek', [
            'device_id' => $this->deviceId,
            'position_ms' => $positionMs,
        ]);
    }

    /**
     * Send a command via relay tunnel.
     *
     * This is a placeholder implementation. Full relay support for
     * Chromecast would require registering a mount handler with the
     * relay consumer and implementing the Cast protocol over the tunnel.
     *
     * @param string $command Command name
     * @param array<string, mixed> $params Command parameters
     *
     * @return array<string, mixed> Empty response (not implemented)
     *
     * @since 0.12.0
     */
    private function sendRelayCommand(string $command, array $params): array
    {
        // Build the relay path for cast commands
        $path = '/relay/cast/' . $command;

        // Register mount handler for cast relay
        $this->relay->registerMount($path, function (string $relayPath) use ($command, $params): string {
            // This is a placeholder - actual implementation would
            // forward Cast protocol commands over the relay tunnel
            $encoded = json_encode(['command' => $command, 'params' => $params]);
            return is_string($encoded) ? $encoded : '{"error":"encoding failed"}';
        });

        // Return empty response for now
        return [];
    }
}
