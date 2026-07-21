<?php

/**
 * Phlix media server component: Config.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Config;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Database\ConnectionPool;
use Workerman\MySQL\Connection;

/**
 * Boot-time overlay of persisted `server_settings` overrides onto the
 * `config/*.php` defaults.
 *
 * ## The problem this solves
 *
 * {@see SettingsRepository} defines a setting's effective value as
 * `override ?? default`, but almost nothing called `getEffective()`. Every
 * other consumer read the raw boot `$config` array (or `include`d a
 * `config/*.php` file directly), and nothing ever merged the `server_settings`
 * table into either. The shared `server-settings.schema.json` marks boot-only
 * keys `"restart": true` and the admin SPA renders "requires a server restart
 * to take effect" beside them — a promise that a restart could not keep,
 * because the restart re-read the same unchanged files.
 *
 * This class closes that gap generically. {@see self::bootstrapAndOverlay()}
 * is called at the top of every `onWorkerStart` (and in the CGI entry point)
 * BEFORE `ContainerFactory::create()`, so every DI provider that reads boot
 * config transparently observes the effective value.
 *
 * ## Dotted-key semantics (identical to {@see SettingsRepository})
 *
 * A setting key's leading segment names the **config file**; the remaining
 * segments walk into the array that file returns. `ffmpeg.transcode_timeout`
 * addresses `config/ffmpeg.php`'s `transcode_timeout`;
 * `server.hls.cache_max_age` addresses `config/server.php`'s
 * `['hls']['cache_max_age']`.
 *
 * Two overlay entry points implement that:
 *
 *  - {@see self::file()} — load one `config/<name>.php` and overlay the
 *    `<name>.*` overrides onto it. Used by consumers that `include` a config
 *    file directly ({@see HwAccelConfig}, `FfmpegRunner::getTranscodeTimeout()`,
 *    `Recorder::getTranscodeTimeout()`, `start.php`'s managed-worker gate).
 *  - {@see self::overlayAppConfig()} — overlay onto the assembled boot
 *    `$config` array (what `config/server.php` returns). `config/server.php`
 *    composes several sibling files under their own names (`'ffmpeg' =>
 *    require __DIR__ . '/ffmpeg.php'`), so an override is applied at BOTH
 *    candidate locations: the full dotted path (`$config['ffmpeg']['…']`) and,
 *    for `server.*` keys, the path after the leading `server.` segment
 *    (`$config['hls']['…']`).
 *
 * ## Safety rules
 *
 *  - **An override is applied ONLY where the default already exists.** The
 *    walk refuses to create keys or to descend through a non-array, so a
 *    malformed, unknown or hand-edited `server_settings` row can never inject
 *    a new config key — it is silently ignored. That also means the overlay is
 *    incapable of changing a config shape the code does not already read.
 *  - **Key shape is validated** (`[A-Za-z0-9_-]+` per segment, at least two
 *    segments), matching {@see SettingsRepository}'s own config-path jail.
 *  - **Every failure degrades to the file defaults.** An unreachable database,
 *    a missing `server_settings` table (fresh install, migrations not yet
 *    run), an unreadable database config — all are caught and yield an empty
 *    override set. The server always boots; it just boots on the shipped
 *    defaults. It never crash-loops on a settings-store failure.
 *  - **Before {@see self::bootstrap()} runs, the overlay is inert.** CLI
 *    scripts, unit tests and any not-yet-booted context see exactly the
 *    file defaults, unchanged.
 *
 * ## Resident-memory notes (Workerman/Swoole)
 *
 * The two statics hold shared, immutable *configuration* — never per-request
 * state — and neither grows without bound: {@see self::$overrides} is replaced
 * wholesale on each bootstrap and is bounded by the admin allow-list, and
 * {@see self::$fileCache} is bounded by the fixed number of `config/*.php`
 * files. Both are cleared by {@see self::bootstrap()} / {@see self::reset()}.
 *
 * {@see self::generation()} is a monotonic counter bumped by every bootstrap.
 * Consumers that keep their own derived cache ({@see HwAccelConfig}) key it on
 * the generation so a re-bootstrap invalidates them without this class needing
 * to know they exist.
 *
 * @package Phlix\Config
 * @since 1.5.0
 */
final class EffectiveConfig
{
    /**
     * Persisted overrides, dotted key → decoded value; null until
     * {@see self::bootstrap()} runs (in which state the overlay is inert).
     *
     * Shared immutable config, not request state. Replaced wholesale on every
     * bootstrap, so it cannot grow unbounded in a resident worker.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $overrides = null;

    /**
     * Memoised, already-overlaid `config/<name>.php` arrays keyed by file name.
     * Bounded by the fixed number of shipped config files; cleared on every
     * bootstrap.
     *
     * @var array<string, array<array-key, mixed>>
     */
    private static array $fileCache = [];

    /**
     * Monotonic bootstrap counter. Starts at 0 (never bootstrapped) and is
     * incremented by each {@see self::bootstrap()}.
     */
    private static int $generation = 0;

    /** Directory holding `config/*.php`; resolved on first bootstrap. */
    private static ?string $configDir = null;

    /**
     * Purely static utility.
     */
    private function __construct()
    {
    }

    /**
     * Load the override snapshot and return the overlaid boot config.
     *
     * This is the single call every entry point makes, immediately before
     * `ContainerFactory::create($config)`:
     *
     * ```php
     * $config = EffectiveConfig::bootstrapAndOverlay($config);
     * $container = ContainerFactory::create($config);
     * ```
     *
     * It NEVER throws: a settings-store failure leaves the overlay empty and
     * returns `$appConfig` unchanged, so the worker boots on file defaults.
     *
     * @param array<string, mixed> $appConfig Assembled boot config (what
     *        `config/server.php` returns, plus `db_config_path` /
     *        `logger_config_path` added by the bootstrap script).
     *
     * @return array<string, mixed> `$appConfig` with every applicable override
     *         applied, or `$appConfig` verbatim when no overrides are readable.
     *
     * @since 1.5.0
     */
    public static function bootstrapAndOverlay(array $appConfig): array
    {
        $dbConfigPath = is_string($appConfig['db_config_path'] ?? null)
            ? $appConfig['db_config_path']
            : null;

        self::bootstrap(null, $dbConfigPath);

        return self::overlayAppConfig($appConfig);
    }

    /**
     * Read the `server_settings` overrides into the process-wide snapshot.
     *
     * Resets the per-file memo and bumps {@see self::generation()} so derived
     * caches invalidate. Best-effort by design — see the class docblock's
     * "Safety rules": any failure yields an empty override set rather than an
     * exception.
     *
     * @param Connection|null $db           Explicit connection (tests, or a
     *        caller that already holds one). When null the static
     *        {@see ConnectionPool} is used, initialising it from
     *        `$dbConfigPath` if it is not yet initialised.
     * @param string|null     $dbConfigPath Path to `config/database.php`, used
     *        only when `$db` is null and the pool is uninitialised.
     * @param string|null     $configDir    Directory holding `config/*.php`.
     *        Defaults to the repository's own `config/` directory.
     *
     * @since 1.5.0
     */
    public static function bootstrap(
        ?Connection $db = null,
        ?string $dbConfigPath = null,
        ?string $configDir = null,
    ): void {
        self::$configDir  = $configDir ?? self::defaultConfigDir();
        self::$overrides  = self::readOverrides($db, $dbConfigPath);
        self::$fileCache  = [];
        self::$generation++;
    }

    /**
     * Monotonic bootstrap generation; 0 means "never bootstrapped".
     *
     * Consumers with their own derived cache should store the generation
     * alongside it and rebuild when it changes.
     *
     * @since 1.5.0
     */
    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * The current override snapshot, dotted key → value.
     *
     * Empty (and the overlay therefore inert) before {@see self::bootstrap()}
     * or when the settings store was unreadable.
     *
     * @return array<string, mixed>
     *
     * @since 1.5.0
     */
    public static function overrides(): array
    {
        return self::$overrides ?? [];
    }

    /**
     * Load `config/<name>.php` and overlay its `<name>.*` overrides.
     *
     * Memoised per bootstrap generation. A missing/unreadable/non-array config
     * file yields `[]` (the callers all read with `??` defaults), and an
     * unsafe `$name` is refused outright.
     *
     * @param string $name Config file base name, no extension or path
     *                     separators (e.g. `ffmpeg`, `process`, `hwaccel`).
     *
     * @return array<array-key, mixed> Effective contents of that config file.
     *
     * @since 1.5.0
     */
    public static function file(string $name): array
    {
        if (array_key_exists($name, self::$fileCache)) {
            return self::$fileCache[$name];
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return [];
        }

        $path = self::configDir() . '/' . $name . '.php';
        /** @var mixed $loaded */
        $loaded = is_file($path) ? @include $path : null;
        $defaults = is_array($loaded) ? $loaded : [];

        return self::$fileCache[$name] = self::overlay($name, $defaults);
    }

    /**
     * Overlay the `<fileSegment>.*` overrides onto an already-loaded config
     * array.
     *
     * Use this when the caller already holds the file's array (or built it by
     * other means) and only wants the override pass.
     *
     * @param string                  $fileSegment Leading key segment naming
     *                                             the config file.
     * @param array<array-key, mixed> $defaults    Config-file defaults.
     *
     * @return array<array-key, mixed> Defaults with applicable overrides applied.
     *
     * @since 1.5.0
     */
    public static function overlay(string $fileSegment, array $defaults): array
    {
        foreach (self::overrides() as $key => $value) {
            $segments = explode('.', $key);
            if (array_shift($segments) !== $fileSegment || $segments === []) {
                continue;
            }
            $defaults = self::setExistingPath($defaults, $segments, $value);
        }

        return $defaults;
    }

    /**
     * Overlay every applicable override onto the assembled boot `$config`.
     *
     * `config/server.php` both defines its own keys AND composes sibling
     * config files under their own names (`'ffmpeg' => require
     * __DIR__ . '/ffmpeg.php'`). An override is therefore tried at BOTH
     * candidate locations, and applied wherever a matching default already
     * exists:
     *
     *  - the FULL dotted path — `ffmpeg.transcode_timeout` →
     *    `$config['ffmpeg']['transcode_timeout']`;
     *  - for `server.*` keys, the path AFTER the leading segment —
     *    `server.hls.cache_max_age` → `$config['hls']['cache_max_age']`.
     *
     * Because {@see self::setExistingPath()} refuses to create keys, a
     * candidate that does not correspond to a real default is a silent no-op —
     * so trying both can never invent a config entry.
     *
     * @param array<string, mixed> $appConfig Boot config array.
     *
     * @return array<string, mixed> Overlaid boot config.
     *
     * @since 1.5.0
     */
    public static function overlayAppConfig(array $appConfig): array
    {
        foreach (self::overrides() as $key => $value) {
            $segments = explode('.', $key);
            if (count($segments) < 2) {
                continue;
            }

            /** @var array<string, mixed> $appConfig */
            $appConfig = self::setExistingPath($appConfig, $segments, $value);

            if ($segments[0] === 'server') {
                /** @var array<string, mixed> $appConfig */
                $appConfig = self::setExistingPath($appConfig, array_slice($segments, 1), $value);
            }
        }

        return $appConfig;
    }

    /**
     * Clear the snapshot, the memo and the resolved config dir.
     *
     * Returns the class to its pre-bootstrap (inert) state, generation
     * included. Derived caches still invalidate correctly: one built during a
     * bootstrapped generation (>= 1) no longer matches generation 0, and one
     * built at generation 0 describes the same inert state it is being reset
     * to. Intended for tests.
     *
     * @since 1.5.0
     */
    public static function reset(): void
    {
        self::$overrides = null;
        self::$fileCache = [];
        self::$configDir = null;
        self::$generation = 0;
    }

    /**
     * Set `$path` inside `$target` **only if that exact path already exists**.
     *
     * The walk bails out — leaving `$target` untouched — as soon as a segment
     * is missing or an intermediate value is not an array. This is what makes
     * an unknown or malformed persisted key harmless: it addresses no existing
     * default, so nothing happens.
     *
     * @param array<array-key, mixed> $target Array to (maybe) modify.
     * @param list<string>            $path   Remaining key segments.
     * @param mixed                   $value  Value to assign at `$path`.
     *
     * @return array<array-key, mixed> `$target`, modified only on a full hit.
     */
    private static function setExistingPath(array $target, array $path, mixed $value): array
    {
        if ($path === []) {
            return $target;
        }

        $segment = $path[0];
        if (!array_key_exists($segment, $target)) {
            return $target;
        }

        if (count($path) === 1) {
            $target[$segment] = $value;
            return $target;
        }

        $child = $target[$segment];
        if (!is_array($child)) {
            return $target;
        }

        $updated = self::setExistingPath($child, array_slice($path, 1), $value);
        if ($updated === $child) {
            return $target;
        }

        $target[$segment] = $updated;

        return $target;
    }

    /**
     * Read + validate the persisted overrides. Never throws.
     *
     * @param Connection|null $db
     * @param string|null     $dbConfigPath
     *
     * @return array<string, mixed> Validated dotted key → decoded value.
     */
    private static function readOverrides(?Connection $db, ?string $dbConfigPath): array
    {
        try {
            if (!$db instanceof Connection) {
                // ConnectionPool::getConnection() on an uninitialised pool
                // WARNS rather than throwing, so the null check is explicit
                // (same precedent as the hub's AuthServicesProvider TTL
                // resolver).
                if (ConnectionPool::getInstance() === null) {
                    if (!is_string($dbConfigPath) || $dbConfigPath === '' || !is_file($dbConfigPath)) {
                        return [];
                    }
                    ConnectionPool::init($dbConfigPath);
                }
                $db = ConnectionPool::getConnection('mysql');
            }

            $raw = (new SettingsRepository($db, self::configDir()))->getAllOverrides();
        } catch (\Throwable) {
            // Fresh install (no `server_settings` table yet), database down,
            // unreadable database config: boot on the shipped file defaults
            // rather than crash-looping the worker.
            return [];
        }

        $clean = [];
        foreach ($raw as $key => $value) {
            if (self::isSafeKey($key)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Is `$key` a syntactically valid dotted setting key?
     *
     * Requires at least two segments (a bare file name addresses no value) and
     * restricts every segment to `[A-Za-z0-9_-]+`, mirroring
     * {@see SettingsRepository}'s config-path jail so no key can carry `..`,
     * `/` or an absolute path.
     *
     * @param string $key Candidate key.
     */
    private static function isSafeKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/', $key) === 1;
    }

    /**
     * The directory holding `config/*.php`.
     */
    private static function configDir(): string
    {
        return self::$configDir ??= self::defaultConfigDir();
    }

    /**
     * Default config directory: `PHLIX_CONFIG_DIR` / `PHLIX_CONFIG_PATH` when
     * an operator has defined either, else this repository's own `config/`.
     *
     * Resolved absolutely (not the relative `'config'` that
     * {@see SettingsRepository} falls back to) so the overlay does not depend
     * on the process working directory.
     */
    private static function defaultConfigDir(): string
    {
        foreach (['PHLIX_CONFIG_DIR', 'PHLIX_CONFIG_PATH'] as $constantName) {
            if (defined($constantName)) {
                /** @var mixed $dir */
                $dir = constant($constantName);
                if (is_string($dir) && $dir !== '') {
                    return $dir;
                }
            }
        }

        return dirname(__DIR__, 2) . '/config';
    }
}
