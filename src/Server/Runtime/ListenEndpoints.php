<?php

/**
 * Phlix media server component: Server runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Runtime;

use RuntimeException;

/**
 * Resolves the socket names and process flags for the two listening workers
 * `start.php` forks (HTTP on :8096, SyncPlay WebSocket on :8097).
 *
 * Before this existed (R14-06, 2026-10-14 review family), `start.php`
 * hardcoded `new Worker('http://0.0.0.0:8096')` with `count = 14` and
 * `new Worker('websocket://0.0.0.0:8097')` with `count = 1`, while
 * `config/server.php` shipped `server.host`/`server.port`,
 * `websocket.host`/`websocket.port`, `worker.count`, `process.reloadable`
 * and `process.reuse_port` that NOTHING binding a socket ever read. Editing
 * them was a config illusion: the DLNA advertisement even read
 * `server.port` while the real bind ignored it, so changing the port there
 * made the server announce a port it never listened on.
 *
 * {@see http()} and {@see webSocket()} now resolve each endpoint with the
 * precedence `env > config > shipped default`, so every key is honored and
 * `start.php` writes the resolved values back into `$config` before forking
 * — readers like the DLNA advertised port and the `WebSocketServer` status
 * metadata therefore always describe the socket that actually accepts.
 *
 * Defaults are byte-identical to the old hardcodes (`0.0.0.0`, 8096,
 * count 14 / `0.0.0.0`, 8097): an install that sets neither env nor config
 * binds exactly what it bound before.
 *
 * Garbage never wins silently. A non-numeric port, an out-of-range port, an
 * unresolvable host literal (spaces, slashes, bare unbracketed IPv6) or a
 * non-positive/non-numeric worker count throws a {@see RuntimeException}
 * whose message names the offending key — failing the boot loudly beats
 * serving the media library on a port the operator did not choose. Only
 * `worker.count => 'auto'` is a legal special value: it means "keep the
 * shipped pool size" (14), documented at the config site.
 *
 * The env seams are `PHLIX_HTTP_HOST`, `PHLIX_HTTP_PORT`,
 * `PHLIX_HTTP_WORKER_COUNT`, `PHLIX_WS_HOST` and `PHLIX_WS_PORT`; empty
 * strings are treated as unset (container runtimes set `-e FOO=` more
 * often than anyone admits).
 *
 * This logic lives in a class (not inline in `start.php`) for the same
 * reason {@see \Phlix\Server\Integrations\Relay\RelayTunnelBoot} documents:
 * `start.php` runs outside PHPUnit and could never be pinned inline, so the
 * decisions belong where {@see \Phlix\Tests\Unit\Server\Runtime\ListenEndpointsTest}
 * can drive them.
 *
 * @package Phlix\Server\Runtime
 * @since   1.4.0
 */
final class ListenEndpoints
{
    /** Env override for the HTTP listen host (R14-06). @since 1.4.0 */
    public const ENV_HTTP_HOST = 'PHLIX_HTTP_HOST';

    /** Env override for the HTTP listen port (R14-06). @since 1.4.0 */
    public const ENV_HTTP_PORT = 'PHLIX_HTTP_PORT';

    /** Env override for the HTTP worker pool size (R14-06). @since 1.4.0 */
    public const ENV_HTTP_WORKER_COUNT = 'PHLIX_HTTP_WORKER_COUNT';

    /** Env override for the WebSocket listen host (R14-06). @since 1.4.0 */
    public const ENV_WS_HOST = 'PHLIX_WS_HOST';

    /** Env override for the WebSocket listen port (R14-06). @since 1.4.0 */
    public const ENV_WS_PORT = 'PHLIX_WS_PORT';

    /** Shipped HTTP bind host — identical to the pre-R14-06 hardcode. @since 1.4.0 */
    public const DEFAULT_HTTP_HOST = '0.0.0.0';

    /** Shipped HTTP bind port — identical to the pre-R14-06 hardcode. @since 1.4.0 */
    public const DEFAULT_HTTP_PORT = 8096;

    /**
     * Shipped HTTP worker pool size — identical to the pre-R14-06 hardcode,
     * and the value `worker.count => 'auto'` resolves to.
     *
     * @since 1.4.0
     */
    public const DEFAULT_HTTP_WORKER_COUNT = 14;

    /** Shipped WebSocket bind host. @since 1.4.0 */
    public const DEFAULT_WS_HOST = '0.0.0.0';

    /** Shipped WebSocket bind port. @since 1.4.0 */
    public const DEFAULT_WS_PORT = 8097;

    /**
     * Never instantiate — this is a pure resolver.
     */
    private function __construct()
    {
    }

    /**
     * Resolve the HTTP listening endpoint.
     *
     * @param array<string, mixed> $config   The `config/server.php` array.
     * @param string|null          $envHost  Raw `PHLIX_HTTP_HOST` value (null/false/'' = unset).
     * @param string|null          $envPort  Raw `PHLIX_HTTP_PORT` value.
     * @param string|null          $envCount Raw `PHLIX_HTTP_WORKER_COUNT` value.
     *
     * @return array{host: string, port: int, workerCount: int, socketName: string}
     *
     * @throws RuntimeException On an unparseable host/port/count value.
     *
     * @since 1.4.0
     */
    public static function http(
        array $config,
        ?string $envHost = null,
        ?string $envPort = null,
        ?string $envCount = null
    ): array {
        $server = is_array($config['server'] ?? null) ? $config['server'] : [];
        $worker = is_array($config['worker'] ?? null) ? $config['worker'] : [];

        $host = self::resolveHost($envHost, $server['host'] ?? null, self::DEFAULT_HTTP_HOST, self::ENV_HTTP_HOST);
        $port = self::resolvePort($envPort, $server['port'] ?? null, self::DEFAULT_HTTP_PORT, self::ENV_HTTP_PORT);
        $count = self::resolveWorkerCount($envCount, $worker['count'] ?? null);

        return [
            'host' => $host,
            'port' => $port,
            'workerCount' => $count,
            'socketName' => 'http://' . $host . ':' . $port,
        ];
    }

    /**
     * Resolve the SyncPlay WebSocket listening endpoint.
     *
     * The WS worker's `count` is deliberately NOT configurable: exactly ONE
     * authoritative `SyncPlayManager` may own the room state (the start.php
     * §4a comment is the law) — a second process would fork the truth.
     *
     * @param array<string, mixed> $config  The `config/server.php` array.
     * @param string|null          $envHost Raw `PHLIX_WS_HOST` value.
     * @param string|null          $envPort Raw `PHLIX_WS_PORT` value.
     *
     * @return array{host: string, port: int, socketName: string}
     *
     * @throws RuntimeException On an unparseable host/port value.
     *
     * @since 1.4.0
     */
    public static function webSocket(
        array $config,
        ?string $envHost = null,
        ?string $envPort = null
    ): array {
        $websocket = is_array($config['websocket'] ?? null) ? $config['websocket'] : [];

        $host = self::resolveHost($envHost, $websocket['host'] ?? null, self::DEFAULT_WS_HOST, self::ENV_WS_HOST);
        $port = self::resolvePort($envPort, $websocket['port'] ?? null, self::DEFAULT_WS_PORT, self::ENV_WS_PORT);

        return [
            'host' => $host,
            'port' => $port,
            'socketName' => 'websocket://' . $host . ':' . $port,
        ];
    }

    /**
     * `process.reloadable` → `Worker::$reloadable` (Workerman ships `true`).
     *
     * @param array<string, mixed> $config The `config/server.php` array.
     *
     * @throws RuntimeException When the key exists but is not a bool.
     *
     * @since 1.4.0
     */
    public static function reloadable(array $config): bool
    {
        return self::resolveFlag($config, 'reloadable', true);
    }

    /**
     * `process.reuse_port` → `Worker::$reusePort` (Workerman ships `false`).
     *
     * The key used to ship `true` while nothing applied it, so every real
     * process ran with Workerman's `false`. Wiring the key without changing
     * the shipped value would have silently switched the listeners to
     * `SO_REUSEPORT` — per-worker sockets and Workerman's own documented
     * "reload becomes restart" caveat. The shipped value is therefore now
     * the honest `false`, and `true` is an opt-in the operator chooses
     * knowingly.
     *
     * @param array<string, mixed> $config The `config/server.php` array.
     *
     * @throws RuntimeException When the key exists but is not a bool.
     *
     * @since 1.4.0
     */
    public static function reusePort(array $config): bool
    {
        return self::resolveFlag($config, 'reuse_port', false);
    }

    /**
     * Read an env var the way this resolver expects: unset, empty string,
     * and the literal `false` all mean "not provided".
     *
     * @since 1.4.0
     */
    public static function envValue(string $name): ?string
    {
        $raw = getenv($name);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }

    /**
     * Env-wins host literal: trim non-empty env, else a non-empty string
     * config value, else the shipped default; then parse.
     *
     * @param string|null $envValue     Nullable raw env string.
     * @param mixed       $configValue  Raw config value (anything — non-strings fall back).
     * @param string      $default      Shipped default host.
     * @param string      $envName      Env key, for error messages.
     */
    private static function resolveHost(?string $envValue, mixed $configValue, string $default, string $envName): string
    {
        $candidate = $envValue;
        if ($candidate === null && is_string($configValue) && trim($configValue) !== '') {
            $candidate = trim($configValue);
        }
        if ($candidate === null) {
            return $default;
        }

        if (str_contains($candidate, ' ') || str_contains($candidate, '/')) {
            throw new RuntimeException(sprintf(
                'ListenEndpoints: listen host "%s" is not a host literal (no spaces or slashes allowed) '
                . '(checked %s / config server.host|websocket.host).',
                $candidate,
                $envName
            ));
        }
        if (str_starts_with($candidate, '[')) {
            if (!str_ends_with($candidate, ']')) {
                throw new RuntimeException(sprintf(
                    'ListenEndpoints: IPv6 listen host "%s" opens a bracket but never closes it.',
                    $candidate
                ));
            }

            return $candidate;
        }
        if (str_contains($candidate, ':')) {
            throw new RuntimeException(sprintf(
                'ListenEndpoints: listen host "%s" looks like a bare IPv6 address — bracket it (e.g. [::1]).',
                $candidate
            ));
        }

        return $candidate;
    }

    /**
     * Env-wins TCP port: strict numeric 1..65535, else the shipped default.
     *
     * @param string|null $envValue     Nullable raw env string.
     * @param mixed       $configValue  Raw config value (int or string accepted).
     * @param int         $default      Shipped default port.
     * @param string      $envName      Env key, for error messages.
     */
    private static function resolvePort(?string $envValue, mixed $configValue, int $default, string $envName): int
    {
        $candidate = $envValue;
        if ($candidate === null && $configValue !== null) {
            if (is_int($configValue)) {
                $candidate = (string) $configValue;
            } elseif (is_string($configValue)) {
                $candidate = $configValue;
            } else {
                throw new RuntimeException(sprintf(
                    'ListenEndpoints: port config value must be int or string, got %s '
                    . '(checked %s / the matching config key).',
                    get_debug_type($configValue),
                    $envName
                ));
            }
        }
        if ($candidate === null) {
            return $default;
        }

        if (!preg_match('/^\d+$/', $candidate)) {
            throw new RuntimeException(sprintf(
                'ListenEndpoints: port "%s" is not numeric (checked %s / the matching config key).',
                $candidate,
                $envName
            ));
        }
        $port = (int) $candidate;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException(sprintf(
                'ListenEndpoints: port %d is outside 1..65535 (checked %s / the matching config key).',
                $port,
                $envName
            ));
        }

        return $port;
    }

    /**
     * Env-wins HTTP worker pool size. `worker.count => 'auto'` (the shipped
     * value) resolves to {@see self::DEFAULT_HTTP_WORKER_COUNT} — Workerman
     * has no auto-scaling pool; 'auto' has always meant "the shipped 14".
     *
     * @param string|null $envValue     Nullable raw env string.
     * @param mixed       $configValue  Raw config value.
     *
     * @throws RuntimeException On zero, negative, or otherwise unparseable counts.
     */
    private static function resolveWorkerCount(?string $envValue, mixed $configValue): int
    {
        if ($envValue !== null) {
            if (!preg_match('/^\d+$/', $envValue)) {
                throw new RuntimeException(sprintf(
                    'ListenEndpoints: %s="%s" is not a positive integer.',
                    self::ENV_HTTP_WORKER_COUNT,
                    $envValue
                ));
            }
            $count = (int) $envValue;
            if ($count < 1) {
                throw new RuntimeException(sprintf(
                    'ListenEndpoints: %s must be >= 1, got %d.',
                    self::ENV_HTTP_WORKER_COUNT,
                    $count
                ));
            }

            return $count;
        }

        if (is_int($configValue) && $configValue >= 1) {
            return $configValue;
        }
        if (is_string($configValue) && strtolower(trim($configValue)) === 'auto') {
            return self::DEFAULT_HTTP_WORKER_COUNT;
        }
        if ($configValue === null) {
            return self::DEFAULT_HTTP_WORKER_COUNT;
        }

        throw new RuntimeException(sprintf(
            'ListenEndpoints: worker.count must be a positive int or "auto", got %s.',
            get_debug_type($configValue)
        ));
    }

    /**
     * Read `process.<key>` as a strict bool; missing → Workerman's shipped
     * default so wiring the key changes nothing until it is set.
     *
     * @param array<string, mixed> $config    The `config/server.php` array.
     * @param string               $key       Key under `process`.
     * @param bool                 $default   Workerman's shipped default.
     *
     * @throws RuntimeException When the key exists but is not a bool.
     */
    private static function resolveFlag(array $config, string $key, bool $default): bool
    {
        $process = is_array($config['process'] ?? null) ? $config['process'] : [];
        if (!array_key_exists($key, $process)) {
            return $default;
        }
        $value = $process[$key];
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf(
                'ListenEndpoints: process.%s must be a bool, got %s.',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }
}
