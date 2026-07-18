<?php

use Phlix\Common\Logger\LogChannels;

/*
 * Handler routing (see StructuredLogger::setupHandlers()):
 *
 * Each handler may declare two optional gates that decide whether it
 * attaches to a given per-channel logger:
 *   - `channels`: list of channel names this handler serves. ABSENT (or an
 *     empty list) = attach to ALL channels.
 *   - `env`: name of an environment variable that must be truthy
 *     (1/true/yes/on) for the handler to attach. ABSENT = always attach.
 *
 * Without these gates every handler attaches to every channel, which is
 * what caused each debug/info record to be written to app.log + events.log
 * + plugins.log (3x write-amplification) and polluted the two subsystem
 * logs with unrelated application records (item-5b).
 *
 * Resulting routing:
 *   - app.log     — ALL records from ALL channels (the general app log;
 *                   capturing everything here is intended, so no diagnostic
 *                   coverage is lost).
 *   - error.log   — ALL error-and-above records from ALL channels (error
 *                   aggregation across the whole app).
 *   - events.log  — ONLY the EVENTS channel, and ONLY when
 *                   PHLIX_DEBUG_EVENTS is truthy; otherwise stays empty.
 *   - plugins.log — ONLY the PLUGINS channel (plugin lifecycle).
 */
return [
    'default' => 'file',
    'handlers' => [
        // General application log — every channel, every level (debug+).
        'file' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/app.log',
            'max_files' => 30,
            'level' => 'debug',
        ],
        // Error aggregation — every channel, error level and above.
        'error' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
        // PSR-14 event-dispatch debug log. Scoped to the EVENTS channel and
        // active only when PHLIX_DEBUG_EVENTS is truthy; otherwise the file
        // stays empty.
        'events' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/events.log',
            'max_files' => 14,
            'level' => 'debug',
            'channels' => [LogChannels::EVENTS],
            'env' => LogChannels::DEBUG_EVENTS_ENV,
        ],
        // Plugin lifecycle log — install / enable / disable / uninstall
        // events, manifest validation failures, composer-runner output,
        // signature verification. Scoped to the PLUGINS channel.
        // Introduced in step A.4.
        'plugins' => [
            'type' => 'rotating_file',
            'path' => __DIR__ . '/../.logs/plugins.log',
            'max_files' => 14,
            'level' => 'debug',
            'channels' => [LogChannels::PLUGINS],
        ],
    ],
];
