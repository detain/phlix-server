<?php

return [
    'default' => 'file',
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/app.log',
            'max_files' => 30,
            'level' => 'debug',
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
        // PSR-14 event-dispatch debug log. Active only when
        // PHLEX_DEBUG_EVENTS=1; otherwise the file stays empty.
        'events' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/events.log',
            'max_files' => 14,
            'level' => 'debug',
        ],
        // Plugin lifecycle log — install / enable / disable / uninstall
        // events, manifest validation failures, composer-runner output,
        // signature verification. Introduced in step A.4.
        'plugins' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/plugins.log',
            'max_files' => 14,
            'level' => 'debug',
        ],
    ],
];
