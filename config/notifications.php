<?php

/**
 * Notification providers configuration.
 *
 * Each provider can be enabled/disabled and requires provider-specific settings.
 * All providers use HTTP-based APIs with no additional dependencies.
 */

declare(strict_types=1);

return [
    /**
     * Path to the CA bundle used to verify TLS certificates of all
     * notification provider endpoints (Discord, Slack, Telegram, ntfy,
     * Pushover, Apprise, MQTT). Each provider can override this with
     * its own `ca_bundle` key. Defaults to the Debian system bundle.
     *
     * @default '/etc/ssl/certs/ca-certificates.crt'
     */
    'ca_bundle' => '/etc/ssl/certs/ca-certificates.crt',

    'discord' => [
        'webhook_url' => '',
        'enabled' => false,
    ],

    'slack' => [
        'webhook_url' => '',
        'enabled' => false,
    ],

    'telegram' => [
        'bot_token' => '',
        'chat_id' => '',
        'enabled' => false,
    ],

    'ntfy' => [
        'topic' => '',
        'server' => 'https://ntfy.sh',
        'enabled' => false,
    ],

    'pushover' => [
        'user_key' => '',
        'api_token' => '',
        'enabled' => false,
    ],

    'apprise' => [
        'url' => '',
        'enabled' => false,
    ],

    'mqtt' => [
        'broker' => 'localhost:1883',
        'topic' => 'phlix/events',
        'username' => '',
        'password' => '',
        'enabled' => false,
    ],
];
