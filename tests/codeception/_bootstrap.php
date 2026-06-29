<?php

declare(strict_types=1);

/**
 * Codeception bootstrap for SyncPlay e2e tests.
 *
 * This file is included before each test run to set up the environment.
 */

// Define test constants
define('SYNCPLAY_TEST_HOST', 'localhost');
define('SYNCPLAY_TEST_PORT', 8097);
define('SYNCPLAY_TEST_TIMEOUT', 5);

// Autoload from main project
$autoloadPath = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}
