<?php

/**
 * Pair this server with a Phlix Hub instance.
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
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HubClient;
use Phlix\Hub\HttpClient;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

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
$enrollmentPath = $configDir . '/hub-enrollment.json';
$logger = LoggerFactory::get(LogChannels::HUB);

/**
 * Hand a freshly-created file to whoever owns the config directory.
 *
 * Operators usually run this as root (`sudo php scripts/pair-with-hub.php`),
 * which leaves the Ed25519 key and enrollment root-owned (0600) — and the
 * non-root account the daemon runs as then can't read them ("Cannot read
 * Ed25519 private key"). Match the config dir's owner so the service can
 * use what pairing created. No-op when not root or without ext-posix.
 */
$handOwnershipToConfigOwner = static function (string $path) use ($configDir): void {
    if (!is_file($path)) {
        return;
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    $uid = fileowner($configDir);
    $gid = filegroup($configDir);
    if (is_int($uid)) {
        @chown($path, $uid);
    }
    if (is_int($gid)) {
        @chgrp($path, $gid);
    }
};

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

// The keypair was just (re)created by initiatePairing(); make sure the
// daemon user owns it even if this ran under sudo.
$handOwnershipToConfigOwner($keyPath);

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

    if ($status->status === \Phlix\Hub\ClaimStatusResult::STATUS_CLAIMED) {
        echo "Claimed! Server ID: {$status->serverId}\n";

        if ($status->enrollmentJwt && $status->hubJwksUrl && $status->serverId) {
            try {
                $hubClient->storeEnrollment(
                    $status->enrollmentJwt,
                    $status->hubJwksUrl,
                    $status->serverId,
                    $hubUrl,
                );
                $handOwnershipToConfigOwner($enrollmentPath);
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

    if ($status->status === \Phlix\Hub\ClaimStatusResult::STATUS_EXPIRED) {
        fwrite(STDERR, "ERROR: Claim code has expired. Please run the script again.\n");
        exit(1);
    }

    // STATUS_PENDING
    if ($pollCount % 15 === 0) {
        echo "Still waiting... (claim code expires in " . (600 - ($pollCount * 2)) . "s)\n";
    }
}
