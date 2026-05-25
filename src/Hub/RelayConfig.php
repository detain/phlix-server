<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * Configuration for the server-side relay tunnel.
 *
 * The server connects outbound to the hub's dedicated server-tunnel
 * WebSocket worker ({@see \Phlix\Hub\Relay\RelayWorker}, default
 * `ws://<hub-host>:8802`). The enrollment JWT is presented in the JSON
 * HELLO body sent as the first WS message — NOT as an HTTP Authorization
 * header — so {@see RelayConfig::buildHubRelayWsUrl()} intentionally
 * targets the bare WS endpoint with no path/query/auth.
 *
 * @package Phlix\Hub
 * @since 0.5.0
 */
final class RelayConfig
{
    /**
     * Default TCP port the hub's server-tunnel WS worker listens on.
     */
    public const DEFAULT_HUB_RELAY_WS_PORT = 8802;

    /**
     * @param bool   $enabled          Whether the relay tunnel is enabled.
     * @param string $hubWssUrl         Legacy hub relay endpoint template
     *                                  (e.g. wss://hub.example.com/api/v1/servers/{id}/relay).
     *                                  Used only to derive the hub host when
     *                                  $hubRelayWsUrl is not explicitly set.
     * @param string $localAddress      Local address the relay consumer binds to
     *                                  (default 127.0.0.1:0 for auto).
     * @param string $tunnelHostname    Public hostname for the tunnel (e.g. my-server.phlix.media).
     * @param int    $reconnectDelay    Seconds to wait before reconnecting after disconnect.
     * @param int    $pingInterval      Seconds between heartbeat frames.
     * @param int    $pingTimeout       Seconds to wait before considering the tunnel dead.
     * @param string $hubRelayWsUrl     Explicit hub server-tunnel WS endpoint
     *                                  (e.g. ws://hub.example.com:8802). When empty
     *                                  it is derived from $hubWssUrl + port 8802.
     * @param string $localHttpAddress  Address of this server's own local HTTP
     *                                  listener that relayed client bytes are piped
     *                                  to (default 127.0.0.1:8096).
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $hubWssUrl = '',
        public readonly string $localAddress = '127.0.0.1:0',
        public readonly string $tunnelHostname = '',
        public readonly int $reconnectDelay = 5,
        public readonly int $pingInterval = 30,
        public readonly int $pingTimeout = 10,
        public readonly string $hubRelayWsUrl = '',
        public readonly string $localHttpAddress = '127.0.0.1:8096',
    ) {
    }

    /**
     * Create a RelayConfig from environment variables and an optional array.
     *
     * @param array<string, mixed>|null $overrides Config overrides (useful for testing).
     *
     * @return self
     *
     * @since 0.5.0
     */
    public static function fromEnv(?array $overrides = null): self
    {
        $enabled = self::getEnvBool('PHLIX_RELAY_ENABLED', false);
        $hubUrl = getenv('PHLIX_RELAY_HUB_URL') ?: '';
        $tunnelHostname = getenv('PHLIX_RELAY_TUNNEL_HOSTNAME') ?: '';
        $reconnectDelay = (int)(getenv('PHLIX_RELAY_RECONNECT_DELAY') ?: '5');
        $pingInterval = (int)(getenv('PHLIX_RELAY_PING_INTERVAL') ?: '30');
        $pingTimeout = (int)(getenv('PHLIX_RELAY_PING_TIMEOUT') ?: '10');
        $localAddress = '127.0.0.1:0';
        $hubRelayWsUrl = getenv('PHLIX_RELAY_HUB_WS_URL') ?: '';
        $localHttpAddress = getenv('PHLIX_RELAY_LOCAL_HTTP_ADDRESS') ?: '127.0.0.1:8096';

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
            $hubRelayWsUrl = is_string($overrides['hub_relay_ws_url'] ?? null)
                ? $overrides['hub_relay_ws_url'] : $hubRelayWsUrl;
            $localHttpAddress = is_string($overrides['local_http_address'] ?? null)
                ? $overrides['local_http_address'] : $localHttpAddress;
        }

        return new self(
            enabled: $enabled,
            hubWssUrl: $hubUrl,
            localAddress: $localAddress,
            tunnelHostname: $tunnelHostname,
            reconnectDelay: $reconnectDelay,
            pingInterval: $pingInterval,
            pingTimeout: $pingTimeout,
            hubRelayWsUrl: $hubRelayWsUrl,
            localHttpAddress: $localHttpAddress,
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
     * Returns the legacy HTTP-route relay URL with the server ID substituted.
     *
     * Retained for backwards compatibility with older configs/tests. The
     * multiplexed protocol does NOT connect here — use
     * {@see buildHubRelayWsUrl()} for the actual server-tunnel WS endpoint.
     *
     * @param string $serverId The hub-assigned server UUID.
     *
     * @return string
     *
     * @since 0.5.0
     */
    public function buildHubWssUrl(string $serverId): string
    {
        return str_replace('{id}', $serverId, $this->hubWssUrl);
    }

    /**
     * Build the Workerman address for the hub's server-tunnel WS worker.
     *
     * The multiplexed relay protocol connects to the hub's dedicated
     * {@see \Phlix\Hub\Relay\RelayWorker} (default `ws://<hub>:8802`),
     * NOT to the `/api/v1/servers/{id}/relay` HTTP route. The enrollment
     * JWT is carried in the JSON HELLO body (first WS message), so no path,
     * query string, or Authorization header is needed on the upgrade.
     *
     * Resolution order:
     *   1. Explicit {@see $hubRelayWsUrl} (e.g. `ws://hub.example.com:8802`).
     *   2. Derived from {@see $hubWssUrl} host with the scheme normalised to
     *      ws/wss and port forced to {@see DEFAULT_HUB_RELAY_WS_PORT}.
     *
     * Returns a Workerman `AsyncTcpConnection` address (`ws://host:port`
     * or `wss://host:port`); an empty string if no hub host can be derived.
     *
     * @return string Workerman WS address, or '' when unconfigured.
     *
     * @since 0.5.0
     */
    public function buildHubRelayWsUrl(): string
    {
        if ($this->hubRelayWsUrl !== '') {
            return $this->hubRelayWsUrl;
        }

        if ($this->hubWssUrl === '') {
            return '';
        }

        $parts = parse_url($this->hubWssUrl);
        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host'])) {
            return '';
        }

        $scheme = (isset($parts['scheme']) && $parts['scheme'] === 'wss') ? 'wss' : 'ws';

        return $scheme . '://' . $parts['host'] . ':' . self::DEFAULT_HUB_RELAY_WS_PORT;
    }

    /**
     * Returns the local HTTP listener address as a Workerman tcp:// address.
     *
     * Relayed client bytes are piped verbatim to this listener (the server's
     * own Workerman HTTP worker, default 127.0.0.1:8096).
     *
     * @return string Workerman `tcp://host:port` address.
     *
     * @since 0.5.0
     */
    public function buildLocalHttpUrl(): string
    {
        return 'tcp://' . $this->localHttpAddress;
    }
}
