<?php

declare(strict_types=1);

namespace Phlex\Network;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates port-forward configuration using UPnP-IGD, NAT-PMP, and STUN.
 *
 * Tries automatic discovery (UPnP first, then NAT-PMP), falls back to manual
 * instructions, and persists the result to config/port-forward.json.
 *
 * @package Phlex\Network
 * @since 0.11.0
 */
class PortForwardService
{
    private const PORT = 32400;
    private const CONFIG_FILE = 'config/port-forward.json';

    private UpnpIgdClient $upnp;
    private StunClient $stun;
    private NatPmpClient $natpmp;
    private LoggerInterface $logger;
    private int $port;
    private bool $autoEnabled;
    private string $configPath;

    public function __construct(
        ?UpnpIgdClient $upnp = null,
        ?StunClient $stun = null,
        ?NatPmpClient $natpmp = null,
        ?LoggerInterface $logger = null,
        int $port = self::PORT,
        bool $autoEnabled = true,
        string $configPath = ''
    ) {
        $this->upnp = $upnp ?? new UpnpIgdClient();
        $this->stun = $stun ?? new StunClient();
        $this->natpmp = $natpmp ?? new NatPmpClient();
        $this->logger = $logger ?? new NullLogger();
        $this->port = $port;
        $this->autoEnabled = $autoEnabled;
        $this->configPath = $configPath !== '' ? $configPath : dirname(__DIR__, 2) . '/' . self::CONFIG_FILE;
    }

    /**
     * Attempts automatic port forwarding via UPnP-IGD.
     *
     * First tries UPnP-IGD AddPortMapping, then NAT-PMP as fallback.
     * If successful, returns the public IP:port string and stores result
     * in config/port-forward.json.
     *
     * @return array{success: bool, public_endpoint: string|null, method: string|null, external_ip: string|null}
     */
    public function autoConfigure(): array
    {
        if (!$this->autoEnabled) {
            return $this->result(false, null, 'disabled', null);
        }

        $localIp = $this->getLocalIpAddress();
        if ($localIp === null) {
            $this->logger->warning('Cannot determine local IP address for port forwarding');
            return $this->result(false, null, 'no-local-ip', null);
        }

        $gateway = $this->upnp->discoverGateway();
        if ($gateway !== null) {
            $this->logger->info('UPnP IGD discovered', ['gateway' => $gateway]);

            $externalIp = $this->upnp->getExternalIp($gateway);
            if ($externalIp !== null) {
                $success = $this->upnp->addPortMapping($gateway, (string) $this->port, $localIp, (string) $this->port);
                if ($success) {
                    $endpoint = $externalIp . ':' . $this->port;
                    $this->logger->info('UPnP port mapping added', [
                        'endpoint' => $endpoint,
                        'local_ip' => $localIp,
                        'port' => $this->port,
                    ]);
                    $this->persistConfig([
                        'method' => 'upnp',
                        'external_ip' => $externalIp,
                        'port' => $this->port,
                        'enabled' => true,
                    ]);
                    return $this->result(true, $endpoint, 'upnp', $externalIp);
                }
            }

            $this->logger->info('UPnP GetExternalIP failed, trying NAT-PMP', ['gateway' => $gateway]);
        }

        $gatewayIp = $this->discoverDefaultGateway();
        if ($gatewayIp !== null) {
            $externalIp = $this->natpmp->discoverGateway($gatewayIp);
            if ($externalIp !== null) {
                $assignedPort = $this->natpmp->addPortMapping($gatewayIp, $this->port, $this->port);
                if ($assignedPort !== null) {
                    $endpoint = $externalIp . ':' . $assignedPort;
                    $this->logger->info('NAT-PMP port mapping added', [
                        'endpoint' => $endpoint,
                        'local_ip' => $localIp,
                        'port' => $assignedPort,
                    ]);
                    $this->persistConfig([
                        'method' => 'natpmp',
                        'external_ip' => $externalIp,
                        'port' => $assignedPort,
                        'enabled' => true,
                    ]);
                    return $this->result(true, $endpoint, 'natpmp', $externalIp);
                }
            }
        }

        $publicIp = $this->stun->getPublicIp();
        if ($publicIp !== null) {
            $portOpen = $this->stun->testPortAccessibility($publicIp, $this->port);
            if ($portOpen) {
                $endpoint = $publicIp . ':' . $this->port;
                $this->logger->info('STUN detected port is already open', [
                    'public_ip' => $publicIp,
                    'port' => $this->port,
                ]);
                $this->persistConfig([
                    'method' => 'stun-already-open',
                    'external_ip' => $publicIp,
                    'port' => $this->port,
                    'enabled' => true,
                ]);
                return $this->result(true, $endpoint, 'stun-open', $publicIp);
            }
        }

        $this->logger->info('Automatic port forwarding failed, falling back to manual instructions');
        return $this->result(false, null, 'failed', $publicIp);
    }

    /**
     * Returns platform-specific manual port-forward instructions.
     *
     * @return array{instructions: string, router_detection: string, external_port: int, internal_port: int}
     */
    public function getManualInstructions(): array
    {
        $localIp = $this->getLocalIpAddress() ?? '192.168.1.100';
        $routerIp = $this->discoverDefaultGateway() ?? '192.168.1.1';

        $instructions = <<<TEXT
Open your router's admin panel (typically http://{$routerIp}) and navigate to:
  Port Forwarding / NAT / Firewall settings

Create a new port forwarding rule:
  Protocol:     TCP
  External Port: {$this->port}
  Internal Port: {$this->port}
  Internal IP:  {$localIp}
  Description:  Phlex Media Server

Save and apply the settings. Your router may require a reboot.

After configuring, verify the port is open at: https://www.yougetsignal.com/tools/open-ports/
TEXT;

        return [
            'instructions' => $instructions,
            'router_detection' => "Router IP: {$routerIp}\nServer IP: {$localIp}",
            'external_port' => $this->port,
            'internal_port' => $this->port,
        ];
    }

    /**
     * Returns a list of hostname/IP candidates the server believes it is reachable at.
     *
     * Includes:
     * - LAN IP:port
     * - mDNS hostname.local:port
     * - Public IP:port (if STUN or UPnP succeeded)
     *
     * @return array<int, array{type: string, url: string}>
     */
    public function discoverHostnameCandidates(): array
    {
        $candidates = [];
        $localIp = $this->getLocalIpAddress();

        if ($localIp !== null) {
            $candidates[] = [
                'type' => 'lan',
                'url' => 'http://' . $localIp . ':' . $this->port,
            ];
            $candidates[] = [
                'type' => 'lan-mdns',
                'url' => 'http://phlex.local:' . $this->port,
            ];
        }

        $hostname = gethostname();
        if ($hostname !== false && $hostname !== '') {
            $candidates[] = [
                'type' => 'lan-hostname',
                'url' => 'http://' . $hostname . '.local:' . $this->port,
            ];
        }

        $publicIp = $this->stun->getPublicIp();
        if ($publicIp !== null) {
            $portOpen = $this->stun->testPortAccessibility($publicIp, $this->port);
            if ($portOpen) {
                $candidates[] = [
                    'type' => 'public',
                    'url' => 'http://' . $publicIp . ':' . $this->port,
                ];
            }
        }

        $savedConfig = $this->loadConfig();
        if ($savedConfig !== null && (bool)($savedConfig['enabled'] ?? false)) {
            $externalIp = $savedConfig['external_ip'] ?? null;
            if (is_string($externalIp) && $externalIp !== '') {
                $savedPort = $savedConfig['port'] ?? $this->port;
                if (!is_int($savedPort)) {
                    $savedPort = $this->port;
                }
                $candidates[] = [
                    'type' => 'upnp-mapped',
                    'url' => 'http://' . $externalIp . ':' . $savedPort,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Returns the current port forwarding status.
     *
     * @return array{enabled: bool, method: string|null, external_ip: string|null, port: int, endpoint: string|null}
     */
    public function getStatus(): array
    {
        $config = $this->loadConfig();
        if ($config === null) {
            return [
                'enabled' => false,
                'method' => null,
                'external_ip' => null,
                'port' => $this->port,
                'endpoint' => null,
            ];
        }

        $enabled = (bool)($config['enabled'] ?? false);
        $externalIpRaw = $config['external_ip'] ?? null;
        $externalIp = is_string($externalIpRaw) ? $externalIpRaw : null;
        $portRaw = $config['port'] ?? $this->port;
        $port = is_int($portRaw) ? $portRaw : $this->port;
        $methodRaw = $config['method'] ?? null;
        $method = is_string($methodRaw) ? $methodRaw : null;

        return [
            'enabled' => $enabled,
            'method' => $method,
            'external_ip' => $externalIp,
            'port' => $port,
            'endpoint' => $enabled && $externalIp !== null ? $externalIp . ':' . $port : null,
        ];
    }

    /**
     * Disables port forwarding by removing mappings and clearing config.
     *
     * @return bool True on success, false on failure.
     */
    public function disable(): bool
    {
        $gateway = $this->upnp->discoverGateway();
        if ($gateway !== null) {
            $this->upnp->removePortMapping($gateway, (string) $this->port);
        }

        $gatewayIp = $this->discoverDefaultGateway();
        if ($gatewayIp !== null) {
            $this->natpmp->removePortMapping($gatewayIp, $this->port);
        }

        $this->persistConfig(['enabled' => false]);
        return true;
    }

    /**
     * Returns the local IP address of this machine.
     */
    private function getLocalIpAddress(): ?string
    {
        $connections = @net_get_interfaces();
        if (!is_array($connections)) {
            return null;
        }

        foreach ($connections as $info) {
            if (!is_array($info) || !isset($info['unicast']) || !is_array($info['unicast'])) {
                continue;
            }
            foreach ($info['unicast'] as $addr) {
                if (!is_array($addr) || !isset($addr['address']) || !is_string($addr['address'])) {
                    continue;
                }
                $ip = $addr['address'];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        $sock = @fsockopen('8.8.8.8', 53, $errno, $errstr, 2);
        if ($sock !== false) {
            $localAddr = stream_socket_get_name($sock, false);
            fclose($sock);
            if ($localAddr !== false && $localAddr !== '') {
                $colonPos = strrpos($localAddr, ':');
                $host = $colonPos !== false ? substr($localAddr, 0, $colonPos) : $localAddr;
                if ($host !== '') {
                    return $host;
                }
            }
        }

        return null;
    }

    /**
     * Discovers the default gateway IP address.
     */
    private function discoverDefaultGateway(): ?string
    {
        $connections = @net_get_interfaces();
        if (!is_array($connections)) {
            return null;
        }

        foreach ($connections as $info) {
            if (!is_array($info) || !isset($info['unicast']) || !is_array($info['unicast'])) {
                continue;
            }
            foreach ($info['unicast'] as $addr) {
                if (!is_array($addr) || !isset($addr['address']) || !is_string($addr['address'])) {
                    continue;
                }
                $ip = $addr['address'];
                if (str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.')) {
                    $gateway = $this->findGatewayForIp($ip);
                    if ($gateway !== null) {
                        return $gateway;
                    }
                }
            }
        }

        return '192.168.1.1';
    }

    /**
     * Finds the gateway IP for a given local IP by checking common gateway patterns.
     */
    private function findGatewayForIp(string $localIp): ?string
    {
        $parts = explode('.', $localIp);
        if (count($parts) !== 4) {
            return null;
        }

        $gateway = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.1';
        $sock = @fsockopen('tcp://' . $gateway, 80, $errno, $errstr, 1);
        if ($sock !== false) {
            fclose($sock);
            return $gateway;
        }

        return $gateway;
    }

    /**
     * Persists the port-forward configuration to disk.
     *
     * @param array<string, mixed> $config
     */
    private function persistConfig(array $config): void
    {
        $configFile = $this->getConfigFilePath();
        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $existing = $this->loadConfig();
        $merged = $existing !== null ? array_merge($existing, $config) : $config;

        @file_put_contents($configFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Loads the port-forward configuration from disk.
     *
     * @return array<string, mixed>|null
     */
    private function loadConfig(): ?array
    {
        $configFile = $this->getConfigFilePath();
        if (!file_exists($configFile)) {
            return null;
        }

        $content = @file_get_contents($configFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Returns the resolved config file path.
     */
    private function getConfigFilePath(): string
    {
        if (is_file($this->configPath)) {
            return $this->configPath;
        }
        return rtrim($this->configPath, '/') . '/' . self::CONFIG_FILE;
    }

    /**
     * Builds a standardized result array.
     *
     * @return array{success: bool, public_endpoint: string|null, method: string|null, external_ip: string|null}
     */
    private function result(bool $success, ?string $endpoint, ?string $method, ?string $externalIp): array
    {
        return [
            'success' => $success,
            'public_endpoint' => $endpoint,
            'method' => $method,
            'external_ip' => $externalIp,
        ];
    }
}
