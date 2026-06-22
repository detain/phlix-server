<?php

/**
 * Hub subsystem configuration.
 *
 * @package Phlix\Config
 * @since 0.11.0
 */

// Public hostname this server is reachable at, set by scripts/install.sh from
// `--domain` (env `PHLIX_DOMAIN`). Empty when no domain was configured.
$phlixDomainEnv = getenv('PHLIX_DOMAIN');
$phlixDomain    = is_string($phlixDomainEnv) ? $phlixDomainEnv : '';
// Default TLS on when unset; otherwise honour the value (so PHLIX_TLS_ENABLED=0
// from `--no-tls` is respected — `?: true` would wrongly treat '0' as unset).
$tlsEnabledEnv  = getenv('PHLIX_TLS_ENABLED');
$tlsEnabled     = $tlsEnabledEnv === false
    ? true
    : filter_var($tlsEnabledEnv, FILTER_VALIDATE_BOOLEAN);

return [
    'hub_url' => getenv('PHLIX_HUB_URL') ?: null,

    'hub_jwks_url' => getenv('PHLIX_HUB_JWKS_URL') ?: null,

    'heartbeat_interval' => (int)(getenv('PHLIX_HUB_HEARTBEAT_INTERVAL') ?: 60),

    'enrollment_token_ttl' => 7 * 86400,

    'jwks_cache_ttl' => 900,

    'key_path' => __DIR__ . '/hub-server-key.pem',

    'config_dir' => __DIR__,

    'subdomain_auto_claim' => (bool)(getenv('PHLIX_SUBDOMAIN_AUTO_CLAIM') ?: true),

    'tls_enabled' => $tlsEnabled,

    'domain' => $phlixDomain !== '' ? $phlixDomain : 'phlix.media',

    // Public base URL (scheme + host) the server advertises to the hub as a
    // hostname candidate during pairing, so the hub records a reachable URL
    // (otherwise the daemon has no $_SERVER vars and reports none). Scheme
    // follows `tls_enabled`. Empty when no real domain is configured.
    'public_url' => $phlixDomain !== ''
        ? (($tlsEnabled ? 'https://' : 'http://') . $phlixDomain)
        : '',
];
