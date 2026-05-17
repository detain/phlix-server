<?php

/**
 * Pair this server with a Phlex Hub instance.
 *
 * Usage:
 *   php scripts/pair-with-hub.php <hub-url> <server-name>
 *
 * Example:
 *   php scripts/pair-with-hub.php https://hub.example.com "Alice's NAS"
 *
 * This script:
 * 1. Initiates pairing with the hub, receiving a claim code.
 * 2. Displays the claim code for the operator to enter on the hub's web portal.
 * 3. Polls the hub every 2 seconds for claim completion.
 * 4. On success, stores the enrollment and starts the heartbeat loop.
 * 5. On expiry, reports failure.
 *
 * Press Ctrl+C to cancel at any time.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Hub\Ed25519KeyManager;
use Phlex\Hub\HubClient;
use Phlex\Hub\HttpClient;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

if (($_SERVER['argc'] ?? 0) < 3) {
    fwrite(STDERR, "Usage: php scripts/pair-with-hub.php <hub-url> <server-name>\n");
    fwrite(STDERR, "Example: php scripts/pair-with-hub.php https://hub.example.com \"Alice's NAS\"\n");
    exit(1);
}

$argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$hubUrl = rtrim(is_string($argv[1] ?? null) ? $argv[1] : '', '/');
$serverName = is_string($argv[2] ?? null) ? $argv[2] : '';
$configDir = __DIR__ . '/../config';
$keyPath = $configDir . '/hub-server-key.pem';
$logger = LoggerFactory::get(LogChannels::HUB);

$keyManager = new Ed25519KeyManager($keyPath);
$httpClient = new HttpClient($hubUrl);
$hubClient = new HubClient($keyManager, $httpClient, $logger, $configDir);

echo "Initiating pairing with {$hubUrl}...\n";

try {
    $result = $hubClient->initiatePairing($hubUrl, $serverName);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: Failed to initiate pairing: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Pairing initiated.\n";
echo "Claim code: {$result->claimCode}\n";
echo "Enter this code at {$hubUrl}/claim-server\n";
echo "Waiting for claim... (press Ctrl+C to cancel)\n";

$pollCount = 0;
while (true) {
    sleep(2);
    $pollCount++;

    try {
        $status = $hubClient->pollClaimStatus($result->claimId, $hubUrl);
    } catch (\Throwable $e) {
        echo "Poll #{$pollCount}: error - " . $e->getMessage() . "\n";
        continue;
    }

    if ($status->status === \Phlex\Hub\ClaimStatusResult::STATUS_CLAIMED) {
        echo "Claimed! Server ID: {$status->serverId}\n";

        if ($status->enrollmentJwt && $status->hubJwksUrl && $status->serverId) {
            try {
                $hubClient->storeEnrollment(
                    $status->enrollmentJwt,
                    $status->hubJwksUrl,
                    $status->serverId,
                    $hubUrl,
                );
                echo "Enrollment stored.\n";
            } catch (\Throwable $e) {
                fwrite(STDERR, "ERROR: Failed to store enrollment: " . $e->getMessage() . "\n");
                exit(1);
            }
        }

        echo "Pairing complete. Server is now connected to the hub.\n";
        echo "Heartbeat loop has been started in the background.\n";
        exit(0);
    }

    if ($status->status === \Phlex\Hub\ClaimStatusResult::STATUS_EXPIRED) {
        fwrite(STDERR, "ERROR: Claim code has expired. Please run the script again.\n");
        exit(1);
    }

    // STATUS_PENDING
    if ($pollCount % 15 === 0) {
        echo "Still waiting... (claim code expires in " . (600 - ($pollCount * 2)) . "s)\n";
    }
}
