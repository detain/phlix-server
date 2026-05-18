<?php

/**
 * Notification providers configuration.
 *
 * Each provider can be enabled/disabled and requires provider-specific settings.
 * All providers use HTTP-based APIs with no additional dependencies.
 */

declare(strict_types=1);

return [
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
        'topic' => 'phlex/events',
        'username' => '',
        'password' => '',
        'enabled' => false,
    ],
];
