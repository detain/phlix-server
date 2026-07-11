<?php

/**
 * Hardware acceleration configuration - base settings.
 *
 * This file provides the base hwaccel settings.
 * For the authoritative merged configuration at runtime, use:
 *   \Phlix\Config\HwAccelConfig::get()
 *
 * @since 0.11.0
 */

declare(strict_types=1);

// Return the same base config as hwaccel_base.php for backward compatibility.
// The authoritative merged config is provided by \Phlix\Config\HwAccelConfig::get().
return require __DIR__ . '/hwaccel_base.php';
