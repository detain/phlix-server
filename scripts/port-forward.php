#!/usr/bin/env php
<?php

declare(strict_types=1);

use Phlix\Network\PortForwardService;
use Phlix\Network\UpnpIgdClient;
use Phlix\Network\StunClient;

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Error: Composer autoload not found. Run 'composer install' first.\n");
    exit(1);
}

require $autoloadPath;
require_once __DIR__ . '/bootstrap_env.php';

function getPortForwardService(): PortForwardService
{
    $baseDir = dirname(__DIR__);
    $configFile = $baseDir . '/config/port-forward.php';
    $config = file_exists($configFile) ? (require $configFile) : [];
    $pfConfig = is_array($config['port_forwarding'] ?? null) ? $config['port_forwarding'] : [];

    $autoEnabled = (bool) ($pfConfig['auto'] ?? true);
    $portRaw = $pfConfig['port'] ?? 32400;
    $port = is_numeric($portRaw) ? (int) $portRaw : 32400;

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

    // §3.3/§3.2.1 maintenance ledger acted on by the resident
    // NatPmpMaintenanceWorker (start.php §4f-bis).
    $state = $svc->getState();
    if (is_array($state)) {
        echo "\nNAT-PMP Maintenance:\n";
        $renewAt = $state['mapping_renew_at'] ?? null;
        $renewLine = 'n/a';
        if (is_int($renewAt)) {
            $renewLine = date(DATE_ATOM, $renewAt) . ($renewAt <= time() ? ' (due)' : ' (pending)');
        }
        echo "  Renew at:     " . $renewLine . "\n";
        $granted = $state['mapping_granted_lifetime'] ?? null;
        echo "  Granted:      " . (is_int($granted) ? $granted . 's' : 'n/a') . "\n";
        $gatewayIp = $state['gateway_ip'] ?? null;
        echo "  Gateway pin:  " . (is_string($gatewayIp) && $gatewayIp !== '' ? $gatewayIp : 'unpinned') . "\n";
        $retryAt = $state['mapping_retry_at'] ?? null;
        $failedAttempts = $state['mapping_failed_attempts'] ?? null;
        $backoff = ' none';
        if (is_int($retryAt) && $retryAt > time() && is_int($failedAttempts)) {
            $backoff = ' until ' . date(DATE_ATOM, $retryAt) . " ({$failedAttempts} consecutive failures)";
        }
        echo "  Retry backoff:" . $backoff . "\n";
    }

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

/**
 * Force one RFC 6886 §3.3 renewal attempt NOW, bypassing the persisted
 * deadline and the backoff ledger (which is what the resident
 * NatPmpMaintenanceWorker consults — see start.php §4f-bis). Success re-rolls
 * `mapping_renew_at` to half the newly-granted lifetime; failure records the
 * next backoff slot exactly like the worker's tick would.
 */
function cmdRenew(): void
{
    $svc = getPortForwardService();
    echo "Attempting NAT-PMP mapping renewal (§3.3)...\n";
    $result = $svc->renewOnce();

    if ($result['renewed']) {
        echo "SUCCESS: Mapping renewed via NAT-PMP\n";
        echo "External IP: {$result['external_ip']}\n";
        echo "Granted lifetime: {$result['granted_lifetime']}s\n";
        return;
    }

    echo "FAILED: Renewal did not succeed (reason: {$result['reason']}).\n";
    echo "A backoff retry slot has been recorded; the maintenance worker will retry on its own cadence.\n";
    echo "Run `php scripts/port-forward.php status` to see it, or `enable` for a full re-cascade.\n";
}

function cmdInfo(): void
{
    $svc = getPortForwardService();
    $localIp = 'unknown';
    $connections = @net_get_interfaces();
    if (is_array($connections)) {
        foreach ($connections as $info) {
            if (is_array($info) && isset($info['unicast']) && is_array($info['unicast'])) {
                foreach ($info['unicast'] as $addr) {
                    if (is_array($addr) && isset($addr['address'])) {
                        $ip = $addr['address'];
                        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
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
        // S169: print the CLASSIFIED outcome, not just open/not-open. "refused"
        // points at the router's forwarding rules (or a router with no NAT
        // loopback, so this probe cannot see its own forward); "timed-out"
        // points at a firewall dropping the packet; "unreachable" at routing.
        $outcome = $stun->probePort($publicIp, $svc->getStatus()['port']);
        echo "Port {$svc->getStatus()['port']} on {$publicIp}: "
            . ($outcome->isOpen() ? 'OPEN' : 'NOT OPEN') . " ({$outcome->value})\n";
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
    echo "  renew    Force one NAT-PMP §3.3 renewal attempt now\n";
    echo "  disable  Remove port mappings and disable\n";
    echo "  info     Display network info and candidate hostnames\n";
    echo "  help     Show this help message\n";
}

$command = $argv[1] ?? 'help';

match ($command) {
    'status' => cmdStatus(),
    'enable' => cmdEnable(),
    'renew' => cmdRenew(),
    'disable' => cmdDisable(),
    'info' => cmdInfo(),
    'help' => cmdHelp(),
    default => cmdHelp(),
};
