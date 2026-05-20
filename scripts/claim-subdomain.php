#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Claim a subdomain from the hub.
 *
 * After enrollment, this script claims a *.phlix.media subdomain
 * for the server, provisions TLS certificates, and stores the
 * configuration locally.
 *
 * Usage: php scripts/claim-subdomain.php
 *
 * @package Phlix\Scripts
 * @since 0.12.0
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Phlix\Hub\SubdomainClient;
use Phlix\Hub\HubClient;
use Phlix\Hub\Ed25519KeyManager;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpResponse;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;

$configDir = __DIR__ . '/../config';
$hubConfigFile = $configDir . '/hub.php';

if (!file_exists($hubConfigFile)) {
    echo "Error: Hub configuration not found at config/hub.php\n";
    exit(1);
}

$hubConfig = require $hubConfigFile;
$hubBaseUrl = $hubConfig['hub_base_url'] ?? 'https://hub.phlix.media';
$subdomainAutoClaim = $hubConfig['subdomain_auto_claim'] ?? true;

$keyManager = new Ed25519KeyManager($configDir . '/hub-signing-key.pem');
$logger = LoggerFactory::get('hub');

$httpClient = new HttpClient($hubBaseUrl);
$hubClient = new HubClient($keyManager, $httpClient, $logger, $configDir);

$enrollment = $hubClient->loadEnrollment();
if ($enrollment === null) {
    echo "Error: Server is not enrolled with hub. Run pairing first.\n";
    exit(1);
}

$subdomainClient = new SubdomainClient(
    $hubClient,
    $enrollment->serverId,
    $logger,
    $configDir,
);

$existingSubdomain = $subdomainClient->getCurrentSubdomain();
if ($existingSubdomain !== null) {
    echo "Subdomain already claimed: {$existingSubdomain}.phlix.media\n";
    echo "Use --release to release and re-claim.\n";
    exit(0);
}

if (!$subdomainAutoClaim) {
    echo "Error: subdomain_auto_claim is disabled in config/hub.php\n";
    exit(1);
}

echo "Claiming subdomain from hub...\n";

$result = $subdomainClient->claimSubdomain();

if ($result === null) {
    echo "Error: Failed to claim subdomain from hub.\n";
    exit(1);
}

echo "Allocated subdomain: {$result->subdomain}.phlix.media\n";
echo "Certificate: {$result->tlsCertPath}\n";
echo "Key: {$result->tlsKeyPath}\n";
echo "Success!\n";

exit(0);
