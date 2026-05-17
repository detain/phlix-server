<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Configuration for the server-side relay tunnel.
 *
 * @package Phlex\Hub
 * @since 0.12.0
 */
final class RelayConfig
{
    /**
     * @param bool   $enabled        Whether the relay tunnel is enabled.
     * @param string $hubWssUrl       WebSocket URL of the hub relay endpoint
     *                                (e.g. wss://hub.example.com/api/v1/servers/{id}/relay).
     * @param string $localAddress    Local address the relay consumer binds to
     *                                (default 127.0.0.1:0 for auto).
     * @param string $tunnelHostname  Public hostname for the tunnel (e.g. my-server.phlex.media).
     * @param int    $reconnectDelay  Seconds to wait before reconnecting after disconnect.
     * @param int    $pingInterval    Seconds between keep-alive pings.
     * @param int    $pingTimeout    Seconds to wait for pong before considering connection dead.
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $hubWssUrl = '',
        public readonly string $localAddress = '127.0.0.1:0',
        public readonly string $tunnelHostname = '',
        public readonly int $reconnectDelay = 5,
        public readonly int $pingInterval = 30,
        public readonly int $pingTimeout = 10,
    ) {
    }

    /**
     * Create a RelayConfig from environment variables and an optional array.
     *
     * @param array<string, mixed>|null $overrides Config overrides (useful for testing).
     *
     * @return self
     *
     * @since 0.12.0
     */
    public static function fromEnv(?array $overrides = null): self
    {
        $enabled = self::getEnvBool('PHLEX_RELAY_ENABLED', false);
        $hubUrl = getenv('PHLEX_RELAY_HUB_URL') ?: '';
        $tunnelHostname = getenv('PHLEX_RELAY_TUNNEL_HOSTNAME') ?: '';
        $reconnectDelay = (int)(getenv('PHLEX_RELAY_RECONNECT_DELAY') ?: '5');
        $pingInterval = (int)(getenv('PHLEX_RELAY_PING_INTERVAL') ?: '30');
        $pingTimeout = (int)(getenv('PHLEX_RELAY_PING_TIMEOUT') ?: '10');
        $localAddress = '127.0.0.1:0';

        if ($overrides !== null) {
            $enabled = is_bool($overrides['enabled'] ?? null)
                ? $overrides['enabled'] : $enabled;
            $hubUrl = is_string($overrides['hub_wss_url'] ?? null)
                ? $overrides['hub_wss_url'] : $hubUrl;
            $localAddress = is_string($overrides['local_address'] ?? null)
                ? $overrides['local_address'] : $localAddress;
            $tunnelHostname = is_string($overrides['tunnel_hostname'] ?? null)
                ? $overrides['tunnel_hostname'] : $tunnelHostname;
            $reconnectDelay = is_int($overrides['reconnect_delay'] ?? null)
                ? $overrides['reconnect_delay'] : $reconnectDelay;
            $pingInterval = is_int($overrides['ping_interval'] ?? null)
                ? $overrides['ping_interval'] : $pingInterval;
            $pingTimeout = is_int($overrides['ping_timeout'] ?? null)
                ? $overrides['ping_timeout'] : $pingTimeout;
        }

        return new self(
            enabled: $enabled,
            hubWssUrl: $hubUrl,
            localAddress: $localAddress,
            tunnelHostname: $tunnelHostname,
            reconnectDelay: $reconnectDelay,
            pingInterval: $pingInterval,
            pingTimeout: $pingTimeout,
        );
    }

    /**
     * Get a boolean environment variable.
     *
     * @param string $key     Env var name.
     * @param bool   $default Default value.
     *
     * @return bool
     */
    private static function getEnvBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Returns the full WSS URL with the server ID substituted.
     *
     * @param string $serverId The hub-assigned server UUID.
     *
     * @return string
     *
     * @since 0.12.0
     */
    public function buildHubWssUrl(string $serverId): string
    {
        return str_replace('{id}', $serverId, $this->hubWssUrl);
    }
}
