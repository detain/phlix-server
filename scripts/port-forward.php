#!/usr/bin/env php
<?php

declare(strict_types=1);

use Phlex\Network\PortForwardService;
use Phlex\Network\UpnpIgdClient;
use Phlex\Network\StunClient;

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoload not found. Run 'composer install' first.\n");
    exit(1);
}

require $autoloadPath;

function getPortForwardService(): PortForwardService
{
    $baseDir = dirname(__DIR__);
    $configFile = $baseDir . '/config/port-forward.php';
    $config = file_exists($configFile) ? (require $configFile) : [];
    $pfConfig = is_array($config['port_forwarding'] ?? null) ? $config['port_forwarding'] : [];

    $autoEnabled = (bool) ($pfConfig['auto'] ?? true);
    $port = (int) ($pfConfig['port'] ?? 32400);

    return new PortForwardService(
        new UpnpIgdClient(),
        new StunClient(),
        null,
        null,
        $port,
        $autoEnabled,
        $baseDir
    );
}

function cmdStatus(): void
{
    $svc = getPortForwardService();
    $status = $svc->getStatus();

    echo "Port Forwarding Status\n";
    echo "=======================\n";
    echo "Enabled:  " . ($status['enabled'] ? 'YES' : 'NO') . "\n";
    echo "Method:   " . ($status['method'] ?? 'none') . "\n";
    echo "External IP: " . ($status['external_ip'] ?? 'unknown') . "\n";
    echo "Port:     " . $status['port'] . "\n";
    echo "Endpoint: " . ($status['endpoint'] ?? 'n/a') . "\n";

    echo "\nHostname Candidates:\n";
    $candidates = $svc->discoverHostnameCandidates();
    foreach ($candidates as $candidate) {
        echo "  [{$candidate['type']}] {$candidate['url']}\n";
    }
}

function cmdEnable(): void
{
    $svc = getPortForwardService();
    echo "Attempting automatic port forwarding...\n";
    $result = $svc->autoConfigure();

    if ($result['success']) {
        echo "SUCCESS: Port forwarding active via {$result['method']}\n";
        echo "Endpoint: {$result['public_endpoint']}\n";
        echo "External IP: {$result['external_ip']}\n";
    } else {
        echo "FAILED: Automatic port forwarding not available.\n";
        echo "External IP detected: " . ($result['external_ip'] ?? 'unknown') . "\n";
        echo "\nManual instructions:\n";
        $instructions = $svc->getManualInstructions();
        echo $instructions['instructions'] . "\n";
    }
}

function cmdDisable(): void
{
    $svc = getPortForwardService();
    $svc->disable();
    echo "Port forwarding disabled. Mappings removed.\n";
}

function cmdInfo(): void
{
    $svc = getPortForwardService();
    $localIp = 'unknown';
    $connections = @net_get_interfaces();
    if (is_array($connections)) {
        foreach ($connections as $info) {
            if (is_array($info) && isset($info['unicast'])) {
                foreach ($info['unicast'] as $addr) {
                    if (is_array($addr) && isset($addr['address'])) {
                        $ip = $addr['address'];
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                            $localIp = $ip;
                            break 2;
                        }
                    }
                }
            }
        }
    }

    echo "Network Information\n";
    echo "====================\n";
    echo "Local IP:  " . $localIp . "\n";
    echo "Port:      " . $svc->getStatus()['port'] . "\n";

    echo "\nTesting STUN (public IP detection)...\n";
    $stun = new StunClient();
    $publicIp = $stun->getPublicIp();
    echo "Public IP: " . ($publicIp ?? 'unavailable') . "\n";

    if ($publicIp !== null) {
        $portOpen = $stun->testPortAccessibility($publicIp, $svc->getStatus()['port']);
        echo "Port {$svc->getStatus()['port']} on {$publicIp}: " . ($portOpen ? 'OPEN' : 'BLOCKED/FILTERED') . "\n";
    }

    echo "\nUPnP IGD Discovery...\n";
    $upnp = new UpnpIgdClient();
    $gateway = $upnp->discoverGateway();
    echo "Gateway:  " . ($gateway ?? 'not found') . "\n";

    if ($gateway !== null) {
        $externalIp = $upnp->getExternalIp($gateway);
        echo "External WAN IP: " . ($externalIp ?? 'unavailable') . "\n";
    }

    echo "\nHostname Candidates:\n";
    $candidates = $svc->discoverHostnameCandidates();
    foreach ($candidates as $candidate) {
        echo "  [{$candidate['type']}] {$candidate['url']}\n";
    }
}

function cmdHelp(): void
{
    echo "Usage: php scripts/port-forward.php <command>\n";
    echo "\nCommands:\n";
    echo "  status   Show current port forwarding status\n";
    echo "  enable   Attempt automatic port forwarding\n";
    echo "  disable  Remove port mappings and disable\n";
    echo "  info     Display network info and candidate hostnames\n";
    echo "  help     Show this help message\n";
}

$command = $argv[1] ?? 'help';

match ($command) {
    'status' => cmdStatus(),
    'enable' => cmdEnable(),
    'disable' => cmdDisable(),
    'info' => cmdInfo(),
    'help' => cmdHelp(),
    default => cmdHelp(),
};
