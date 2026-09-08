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
 * ---------------------------------------------------------------------------
 * On-disk size policy (S130)
 * ---------------------------------------------------------------------------
 * Every file handler is now `size_rotating` (see SizeRotatingFileHandler),
 * which caps the ON-DISK SIZE of each log, not merely its daily file COUNT.
 * The previous `rotating_file` (Monolog RotatingFileHandler) rotated by day and
 * pruned by `max_files`, so the file count was bounded but no individual file
 * ever was — one chatty day (a six-hour media scan, an error storm) could grow
 * a single log without limit. Each handler now declares `max_file_size_mb` plus
 * `max_files`; the footprint is hard-bounded at (max_files + 1) *
 * max_file_size_mb MB:
 *   - app.log     — (14 + 1) * 16 MB = 240 MB
 *   - error.log   — (10 + 1) *  8 MB =  88 MB
 *   - events.log  — ( 7 + 1) *  4 MB =  32 MB
 *   - plugins.log — ( 7 + 1) *  4 MB =  32 MB
 *   - estate worst case ≈ 392 MB, bounded, vs unbounded before.
 * The active file keeps a stable name (`app.log`, …); rolled shards take a
 * `.1`, `.2`, … suffix that the admin log viewer's `*.log` glob does not list,
 * so the viewer still shows one file per stream.
 *
 * ---------------------------------------------------------------------------
 * Production log LEVEL for the general app.log (S130)
 * ---------------------------------------------------------------------------
 * The `file` handler is deliberately at `info`, NOT `debug`. Debug was never a
 * considered production choice — it was the shipped default — and it is the
 * lever behind the scan-time log volume S96 first had to cut a caller for. The
 * decision, and what is still visible with it, is stated here per the S130 land-
 * mine (do not reduce level and add rotation without saying what an operator can
 * still see during a six-hour scan):
 *
 *   AT `info`, during a long (multi-hour) library scan an operator running
 *   `tail -f .logs/app.log` (or the admin Logs view) STILL SEES, for the video
 *   and music scanners alike:
 *     - every INFO line: the scan start/complete summaries, per-item "added"/
 *       "updated" milestones the scanner emits at info, adoption/prune tallies;
 *     - every WARNING and ERROR line: skipped/failed files, missing paths,
 *       "no present files for this root, refusing to bulk-delete" guards,
 *       metadata/EXIF failures, DB errors — the whole actionable signal set;
 *     - every record above error (critical/alert/emergency).
 *   What is DROPPED is only the DEBUG per-item chatter (the verbose "indexing
 *   <file>", per-track progress and similar trace lines). The scanners' own
 *   diagnostic structure — their info/warning/error output — is UNCHANGED, so
 *   the video scanner's precedent observability that S96 used as its reference
 *   is preserved, and the music scanner's error reporting is untouched.
 *   When that dropped debug detail IS needed, it is a one-line, deliberate,
 *   per-handler change of `'level' => 'debug'` here — and because app.log is now
 *   size-bounded, turning debug back on can no longer fill the disk.
 *
 *   Note the standing caveat unrelated to level: music logging is not visible in
 *   an interactive journal because the scanner runs under systemd `PrivateTmp`;
 *   it is written to app.log (this handler) which lives in `.logs/` on the host.
 *   That PrivateTmp visibility concern is out of scope for S130 (a systemd
 *   concern, tracked separately) — the change here is level + size bound only.
 *
 * Resulting routing:
 *   - app.log     — ALL channels, INFO and above (the general app log; the
 *                   deliberate production floor, size-bounded).
 *   - error.log   — ALL error-and-above records from ALL channels (error
 *                   aggregation across the whole app), size-bounded.
 *   - events.log  — ONLY the EVENTS channel, and ONLY when
 *                   PHLIX_DEBUG_EVENTS is truthy; otherwise stays empty.
 *                   Size-bounded even when enabled.
 *   - plugins.log — ONLY the PLUGINS channel (plugin lifecycle), size-bounded.
 *
 * NOTE: there is deliberately no top-level `default` handler key. app.log's
 * catch-all behavior comes purely from the `file` handler carrying no
 * `channels` tag (untagged = attaches to every channel); nothing in
 * StructuredLogger::setupHandlers() reads a `default` key, so adding one
 * would only imply routing behavior that does not exist.
 */
return [
    'handlers' => [
        // General application log — every channel, INFO and above (see the
        // S130 level rationale above), size-bounded at 240 MB worst case.
        'file' => [
            'type' => 'size_rotating',
            'path' => __DIR__ . '/../.logs/app.log',
            'max_files' => 14,
            'max_file_size_mb' => 16,
            'level' => 'info',
        ],
        // Error aggregation — every channel, error level and above.
        // Size-bounded at 88 MB worst case (an error storm cannot grow it past
        // the ceiling; the oldest shards roll off first).
        'error' => [
            'type' => 'size_rotating',
            'path' => __DIR__ . '/../.logs/error.log',
            'max_files' => 10,
            'max_file_size_mb' => 8,
            'level' => 'error',
        ],
        // PSR-14 event-dispatch debug log. Scoped to the EVENTS channel and
        // active only when PHLIX_DEBUG_EVENTS is truthy; otherwise the file
        // stays empty. Debug is fine here precisely because the handler is
        // env-gated OFF by default AND now size-bounded at 32 MB even when on.
        'events' => [
            'type' => 'size_rotating',
            'path' => __DIR__ . '/../.logs/events.log',
            'max_files' => 7,
            'max_file_size_mb' => 4,
            'level' => 'debug',
            'channels' => [LogChannels::EVENTS],
            'env' => LogChannels::DEBUG_EVENTS_ENV,
        ],
        // Plugin lifecycle log — install / enable / disable / uninstall
        // events, manifest validation failures, composer-runner output,
        // signature verification. Scoped to the PLUGINS channel, size-bounded
        // at 32 MB. Introduced in step A.4.
        'plugins' => [
            'type' => 'size_rotating',
            'path' => __DIR__ . '/../.logs/plugins.log',
            'max_files' => 7,
            'max_file_size_mb' => 4,
            'level' => 'debug',
            'channels' => [LogChannels::PLUGINS],
        ],
    ],
];
