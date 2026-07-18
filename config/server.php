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
        // SV-1.9: minimum free bytes required on the segment-cache filesystem before
        // an on-demand encode is attempted. `segment_dir` is often a small RAM-backed
        // tmpfs; when free space falls below this floor the encode is rejected with a
        // fast 503 + Retry-After (via HlsController) and an opportunistic sweep is
        // triggered, rather than letting FFmpeg hit ENOSPC and cascade into silent
        // 404s at the player. Sibling of cache_max_bytes/cache_max_age; the
        // TranscodeManager applies the same 500 MiB default when this key is absent.
        // Env override: HLS_MIN_DISK_SPACE_BYTES (mirrors the config key, like
        // cache_max_bytes↔HLS_CACHE_MAX_BYTES and max_concurrent_segments↔
        // HLS_MAX_CONCURRENT_SEGMENTS).
        'min_disk_space_bytes' => (int) (getenv('HLS_MIN_DISK_SPACE_BYTES') ?: 500 * 1024 * 1024),
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

    // SV-4.15: per-surface rate limiting for the server's previously-UNLIMITED
    // auth surfaces (register / refresh / WebAuthn start+finish / public JWKS /
    // :8097 WS-connect). Each surface named in
    // Phlix\Common\RateLimit\RateLimitProfiles gets its OWN limiter instance
    // (registered in AuthServicesProvider) with its own {max, window}; a single
    // shared budget across unrelated surfaces would be wrong. `login` is
    // DELIBERATELY absent — it keeps its own IP-keyed DB-backed
    // DbLoginRateLimitStore (migration 074), untouched by this framework.
    //
    // Backend per surface (AuthServicesProvider decides via
    // RateLimitProfiles::isDbBacked()):
    //   register / refresh / webauthn_start / webauthn_finish -> shared
    //     DB-backed DbRateLimiter (migration 085) so the brute-force budget is
    //     TRUE-global across all HTTP workers (an in-memory limiter would give
    //     ~max × workers on these credential-enumeration surfaces);
    //   jwks / ws_connect -> worker-local in-memory RateLimiter (jwks is a
    //     public, cache-frontable DoS surface; the :8097 WS worker runs count=1
    //     so per-worker == global there anyway).
    //
    // Each surface's max/window is env-overridable via bare
    // RATE_LIMIT_<SURFACE>_MAX / RATE_LIMIT_<SURFACE>_WINDOW (server convention,
    // mirroring the hls block's HLS_* knobs); absent env vars fall back to
    // RateLimitProfiles::defaults().
    'rate_limit' => (static function (): array {
        $limits = [];
        foreach (\Phlix\Common\RateLimit\RateLimitProfiles::defaults() as $spec) {
            $envPrefix = 'RATE_LIMIT_' . strtoupper($spec['key']);
            $limits[$spec['key']] = [
                'max'    => (int) (getenv($envPrefix . '_MAX') ?: $spec['max']),
                'window' => (int) (getenv($envPrefix . '_WINDOW') ?: $spec['window']),
            ];
        }
        return $limits;
    })(),

    // SV-4.15 (HIGH fix): trusted-proxy set used to derive the REAL client IP for
    // rate-limit keys from X-Forwarded-For / X-Real-IP. The shipped nginx +
    // HAProxy front Phlix over loopback (nginx -> 127.0.0.1:8080, HAProxy ->
    // 127.0.0.1:8097) and APPEND the connecting address to XFF, so the DEFAULT
    // trusted set is LOOPBACK ONLY: TrustedProxyResolver then walks XFF
    // right-to-left past the loopback hop and returns the first untrusted entry
    // (the real client the edge proxy observed), ignoring any client-forged
    // leftmost value. Override with a comma-separated IP/CIDR list via the bare
    // TRUSTED_PROXIES env when a NON-loopback proxy fronts the server. Do NOT add
    // RFC1918 for this loopback-proxy deployment: it would make a LAN client's own
    // address be skipped as a "proxy" and re-expose the forged hop. This key is
    // introspection/documentation only — the resolver reads the env itself.
    'trusted_proxies' => \Phlix\Common\Http\TrustedProxyResolver::configuredProxies(),
];
