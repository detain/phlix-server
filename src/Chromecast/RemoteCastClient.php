<?php

declare(strict_types=1);

namespace Phlix\Chromecast;

use Phlix\Hub\RelayConsumer;

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
    /** @var string Target device ID */
    private string $deviceId;

    /**
     * @param RelayConsumer $relay Relay consumer for tunnel communication.
     *        Accepted to preserve the constructor contract for when the hub
     *        relay tunnel becomes operational, but not yet used — see
     *        {@see self::sendRelayCommand()}.
     * @param string $deviceId Target Chromecast device ID
     *
     * @since 0.12.0
     */
    public function __construct(RelayConsumer $relay, string $deviceId)
    {
        // The relay tunnel feature this client depends on is not operational
        // yet, so the relay consumer is intentionally not stored. The parameter
        // remains so the public API does not change once support lands.
        unset($relay);

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
     * Forwarding Cast protocol commands over the relay tunnel depends on a
     * hub relay-tunnel feature that does not exist yet. Until that lands,
     * this method fails loudly instead of silently reporting success while
     * doing nothing — callers must surface a clear error to the user.
     *
     * @param string $command Command name
     * @param array<string, mixed> $params Command parameters
     *
     * @return array<string, mixed> Never returns — always throws
     *
     * @throws \RuntimeException Always, because relay casting is not operational
     *
     * @since 0.12.0
     */
    private function sendRelayCommand(string $command, array $params): array
    {
        unset($command, $params);

        throw new \RuntimeException(
            'Chromecast over relay requires the hub relay tunnel, which is not yet operational'
        );
    }
}
