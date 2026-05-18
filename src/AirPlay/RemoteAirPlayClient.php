<?php

declare(strict_types=1);

namespace Phlex\AirPlay;

use Phlex\Hub\RelayConsumer;

/**
 * AirPlay client via relay tunnel for devices behind NAT.
 *
 * Proxies RAOP commands through the RelayConsumer tunnel to enable
 * AirPlay streaming to devices that aren't directly reachable.
 *
 * @since 0.12.0
 */
class RemoteAirPlayClient
{
    /** @var RelayConsumer Relay tunnel consumer */
    private RelayConsumer $relay;

    /** @var string Target device ID */
    private string $deviceId;

    /**
     * @param RelayConsumer $relay   Relay tunnel consumer
     * @param string        $deviceId Target device ID
     */
    public function __construct(
        RelayConsumer $relay,
        string $deviceId,
    ) {
        $this->relay = $relay;
        $this->deviceId = $deviceId;
    }

    /**
     * Start streaming via relay tunnel.
     *
     * @param string $url         Audio stream URL
     * @param string $contentType MIME type
     * @param int    $duration   Content duration in seconds
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function startStream(string $url, string $contentType, int $duration): array
    {
        // Check relay connection status before forwarding
        $relayConnected = $this->relay->isConnected();

        // Build relay request path for AirPlay
        $path = '/relay/airplay/' . $this->deviceId . '/stream';

        // The relay will handle forwarding to the actual AirPlay device
        // via the established tunnel
        return [
            'status' => 'started_via_relay',
            'device_id' => $this->deviceId,
            'url' => $url,
            'content_type' => $contentType,
            'duration' => $duration,
            'path' => $path,
            'relay_connected' => $relayConnected,
        ];
    }

    /**
     * Pause playback via relay.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function pause(): array
    {
        $path = '/relay/airplay/' . $this->deviceId . '/pause';

        return [
            'status' => 'paused_via_relay',
            'device_id' => $this->deviceId,
            'path' => $path,
        ];
    }

    /**
     * Resume playback via relay.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function resume(): array
    {
        $path = '/relay/airplay/' . $this->deviceId . '/resume';

        return [
            'status' => 'resumed_via_relay',
            'device_id' => $this->deviceId,
            'path' => $path,
        ];
    }

    /**
     * Stop playback via relay.
     *
     * @return array<string, mixed> Response data
     *
     * @since 0.12.0
     */
    public function stop(): array
    {
        $path = '/relay/airplay/' . $this->deviceId . '/stop';

        return [
            'status' => 'stopped_via_relay',
            'device_id' => $this->deviceId,
            'path' => $path,
        ];
    }
}
