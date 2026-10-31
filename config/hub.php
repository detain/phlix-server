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
// Subdomain claiming is on unless the operator explicitly says off — SAME
// tolerant rule as PHLIX_TLS_ENABLED above (0/off/false/no/'' disable, an
// unrecognised value fails toward off). This line previously read
// `(bool)(getenv('PHLIX_SUBDOMAIN_AUTO_CLAIM') ?: true)`, which re-enabled the
// flag for every explicit value — the string '0' is PHP-falsy, so `?:` mapped
// the deliberate opt-out right back onto the default (minors M-SERVER-72,
// batch W-min105; the trap config/database.php's DB_POOL_ENABLED comment
// names). Enforcement point: scripts/claim-subdomain.php refuses to claim.
$subdomainClaimEnv = getenv('PHLIX_SUBDOMAIN_AUTO_CLAIM');

use Phlix\Common\Env\EnvConfig;

return [
    'hub_url' => EnvConfig::nullableText('PHLIX_HUB_URL'),

    'hub_jwks_url' => EnvConfig::nullableText('PHLIX_HUB_JWKS_URL'),

    'heartbeat_interval' => EnvConfig::integer('PHLIX_HUB_HEARTBEAT_INTERVAL', 60),

    'enrollment_token_ttl' => 7 * 86400,

    'jwks_cache_ttl' => 900,

    'enrollment_renewal_threshold' => EnvConfig::integer('PHLIX_HUB_ENROLLMENT_RENEWAL_THRESHOLD', 86400),  // renew if expiring within 24h

    'key_path' => __DIR__ . '/hub-server-key.pem',

    'config_dir' => __DIR__,

    'subdomain_auto_claim' => $subdomainClaimEnv === false
        ? true
        : filter_var($subdomainClaimEnv, FILTER_VALIDATE_BOOLEAN),

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
