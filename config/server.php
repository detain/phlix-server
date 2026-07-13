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

    // Hub subsystem config (enrollment, heartbeat, the public URL the server
    // advertises during pairing). Sourced from config/hub.php so the DI layer
    // (HubServicesProvider reads $config['hub']) gets the real key_path /
    // config_dir / public_url instead of its bare defaults — previously this
    // file was never loaded, so the server advertised no hostname candidates.
    'hub' => require __DIR__ . '/hub.php',
    'relay' => require __DIR__ . '/relay.php',

    // Live TV / DVR settings (tuners, storage_path, comskip, dvr padding).
    // Loaded here so the DI layer (LiveTvServicesProvider reads
    // $appConfig['livetv']) wires the fully-assembled DVR stack — Recorder +
    // LiveTvManager + RecordingScheduler — with the real storage_path,
    // max_storage_bytes and comskip settings in BOTH entry points
    // (public/index.php CGI path and the Workerman daemon in start.php),
    // instead of the bare per-class constructor defaults. Without this the
    // capture pipeline had no wired producer at all. (SV-3.1b0)
    'livetv' => require __DIR__ . '/livetv.php',

    // Theme-music (M3) producer config. Sourced here so MediaServicesProvider
    // (which reads $config['theme_music']) builds the ThemeMusicConfig with the
    // real enabled/source/cache_dir instead of bare defaults.
    'theme_music' => require __DIR__ . '/theme_music.php',

    // Local artwork cache (SV-3.4) config. Sourced here so MediaServicesProvider
    // constructs ArtworkStorage with the operator-configured `storage_path`
    // (env ARTWORK_STORAGE_PATH) instead of the hard-coded /var/artwork default.
    // Reaches BOTH entry points (public/index.php CGI path and the Workerman
    // daemon in start.php), exactly like the ffmpeg/hub/relay/theme_music sub-arrays.
    'artwork' => require __DIR__ . '/artwork.php',

    // Metrics / live-traffic telemetry config (Step S1). Sourced here so
    // MetricsServicesProvider (which reads $config['metrics']) constructs the
    // registry/collector/flush-service/repository with the real
    // enabled/bucket/retention knobs instead of bare defaults. Reaches BOTH
    // entry points (public/index.php CGI path and the Workerman daemon in
    // start.php), exactly like the ffmpeg/hub/relay/theme_music sub-arrays.
    'metrics' => require __DIR__ . '/metrics.php',

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
        // Ceiling on simultaneous on-demand segment encodes across all jobs. Each
        // segment is a full decode+encode; unbounded concurrency (many viewers, or
        // one viewer's timed-out retries) saturates the CPU so every encode slows
        // past the client's fragment timeout and playback fails. Requests over the
        // ceiling get a fast 503 + Retry-After so the client backs off. Tune toward
        // the box's core count. Env override: HLS_MAX_CONCURRENT_SEGMENTS.
        'max_concurrent_segments' => (int) (getenv('HLS_MAX_CONCURRENT_SEGMENTS') ?: 8),
        // Size budget (bytes) for the on-demand segment cache. `segment_dir` is
        // often a RAM-backed tmpfs, and on-demand jobs never self-clean, so without
        // a ceiling the cache grows until the filesystem fills and encodes fail with
        // ENOSPC. Over budget, least-recently-used sessions are evicted. Default 8 GiB.
        'cache_max_bytes' => (int) (getenv('HLS_CACHE_MAX_BYTES') ?: 8 * 1024 * 1024 * 1024),
        // Age (seconds) after which an idle segment session is reclaimed regardless
        // of the size budget — an abandoned watch. Default 3 hours.
        'cache_max_age' => (int) (getenv('HLS_CACHE_MAX_AGE') ?: 10800),
    ],

    // WebSocket server settings for SyncPlay realtime communication.
    // The WS worker runs as count=1 (one authoritative SyncPlayManager for all
    // connections) on a dedicated port separate from the HTTP workers.
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8097,
        // Interval for cleaning up stale connections (seconds).
        'stale_connection_timeout' => 300,
        // Interval for cleaning up stale SyncPlay groups (seconds).
        'stale_group_timeout' => 3600,
        // SV-4.7: HMAC secret used to validate the JWT presented in the WS
        // handshake query string (?token=). Sourced from the SAME JWT_SECRET the
        // HTTP auth layer (AuthServicesProvider / JwtHandler) uses, so a token
        // minted at login validates here too. When set, WS auth is ENFORCED:
        // token-less/invalid handshakes are rejected. When empty (JWT_SECRET
        // unset — a bare dev box), connections are allowed anonymously. In
        // production JWT_SECRET is always present (the boot guard refuses to
        // start otherwise), so WS auth is enforced there.
        'jwt_secret' => getenv('JWT_SECRET') ?: '',
    ],
];
