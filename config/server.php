<?php

return [
    'server' => [
        'name' => 'Phlix Media Server',
        'host' => '0.0.0.0',
        'port' => 8096,
        'context' => [],
    ],
    'worker' => [
        'count' => 'auto',
        'stdout_file' => __DIR__ . '/../.logs/stdout.log',
        'pid_file' => '/var/run/phlix/pid',
    ],
    'process' => [
        'reloadable' => true,
        'reuse_port' => true,
    ],

    // Swoole coroutine runtime (consumed by start.php via
    // src/Server/Runtime/SwooleRuntime.php). The HTTP worker runs under Swoole's
    // event loop with a CURATED hook ALLOWLIST: SWOOLE_HOOK_ALL crashed the
    // worker with general-protection faults inside swoole.so (exit status 139)
    // on PHP 8.5 / Swoole 6.2.1 / kernel-7 io_uring. Only socket/sleep/stream
    // hooks (needed by the coroutine MySQL pool + network IO) are enabled;
    // file/proc/curl/stdio AND the exec/shell_exec blocking-function hook (the
    // ffmpeg-spawn crash trigger) run as plain blocking syscalls.
    'coroutine' => [
        // Set false to disable the coroutine runtime hook entirely while keeping
        // Swoole as the event loop — the most conservative option.
        'enabled' => true,
        // Override the curated default with an explicit SWOOLE_HOOK_* bitmask if
        // you need to re-enable a specific hook, e.g.
        //   'hook_flags' => SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_FILE,
        // Leave unset to use SwooleRuntime::safeHookFlags().
        // 'hook_flags' => null,
    ],

    // FFmpeg / transcoding settings (binary paths, hardware accel, timeouts).
    // Loaded here so the DI providers (MediaServicesProvider, the transcode
    // wiring) see it via $appConfig['ffmpeg'] in BOTH entry points
    // (public/index.php CGI path and the Workerman daemon Application boot),
    // rather than only the ad-hoc include bin/phlix does for hwaccel probing.
    'ffmpeg' => require __DIR__ . '/ffmpeg.php',

    // HLS streaming settings. `segment_dir` is the SINGLE source of truth for
    // where transcoded HLS variants (stream_0.m3u8 + segment_0_NNN.ts) live: the
    // TranscodeManager writes there and HlsController/HlsStreamer read from the
    // very same directory. It defaults to a writable temp path so on-demand
    // transcoding works out of the box without provisioning /var/segments.
    // `base_url` is only used to build absolute playlist URLs for casting.
    'hls' => [
        'segment_dir' => sys_get_temp_dir() . '/phlix_hls',
        'base_url' => 'http://localhost:8096',
        // Target HLS segment duration (seconds). 6s is the Apple-recommended
        // default and keeps the segment count (and per-request overhead) sane.
        'segment_seconds' => 6,
    ],
];
