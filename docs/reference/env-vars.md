# Environment variables

Reference list of every environment variable read by the Phlex Media Server,
its default, and a one-line description. Each entry links back to the file
that consumes it.

## Container & bootstrap

| Variable                   | Default | Description |
| -------------------------- | ------- | ----------- |
| `PHLEX_CONTAINER_COMPILE`  | _unset_ | When truthy (`1`, `true`, `yes`, `on`) enables PHP-DI's compiled-container cache in `var/cache/container/`. Disabled by default for dev parity. See `Phlex\Common\Container\ContainerFactory`. |

## Events

| Variable               | Default | Description |
| ---------------------- | ------- | ----------- |
| `PHLEX_DEBUG_EVENTS`   | `0`     | When truthy (`1`, `true`, `yes`, `on`) wraps the PSR-14 dispatcher in Tukio's `DebugEventDispatcher`, which logs every dispatched event class at debug level on the `events` channel (`.logs/events.log`). Useful for tracing plugin behaviour; leave off in production. See `Phlex\Common\Events\EventDispatcherFactory` and `docs/dev/event-reference.md`. |

## Plugins

| Variable                              | Default | Description |
| ------------------------------------- | ------- | ----------- |
| `PHLEX_PLUGINS_ALLOW_HTTP`            | `0`     | When truthy (`1`, `true`, `yes`, `on`) lets the plugin loader accept plain `http://` source URLs. Default off — HTTPS or `file://` only. See `Phlex\Plugins\Installer\HttpInstaller`. |
| `PHLEX_PLUGINS_ALLOW_UNSIGNED`        | `1`     | When truthy, unsigned plugins are accepted with a warning on the `plugins` log channel. Set to `0` together with `PHLEX_PLUGINS_REQUIRE_SIGNATURE=1` to enforce strict signing. |
| `PHLEX_PLUGINS_REQUIRE_SIGNATURE`     | `0`     | When truthy, the plugin loader refuses to install unsigned plugins and refuses signatures missing from the trusted-key allowlist. See `Phlex\Plugins\Signature\SignatureVerifier`. |
| `PHLEX_PLUGINS_COMPOSER_TIMEOUT`      | `120`   | Hard timeout (seconds) on the per-plugin `composer install --no-dev` subprocess. See `Phlex\Plugins\Installer\ComposerRunner`. |

## Auth

| Variable      | Default                       | Description |
| ------------- | ----------------------------- | ----------- |
| `JWT_SECRET`  | `default-secret-change-me`    | HMAC secret used to sign / verify JWT access and refresh tokens. The default is intentionally insecure so a missing env var fails closed in production deployments. Read by `Phlex\Common\Container\Providers\AuthServicesProvider`. |

## Database (test only)

These are consumed by `phpunit.xml` only and have no effect on production.

| Variable      | Default        | Description |
| ------------- | -------------- | ----------- |
| `APP_ENV`     | `testing`      | Marks the runtime as a test environment. |
| `DB_HOST`     | `127.0.0.1`    | MySQL host used by integration tests. |
| `DB_PORT`     | `3306`         | MySQL port used by integration tests. |
| `DB_DATABASE` | `phlex_test`   | MySQL database name used by integration tests. |
| `DB_USER`     | `root`         | MySQL username used by integration tests. |
| `DB_PASSWORD` | _empty_        | MySQL password used by integration tests. |

> The production database credentials live in `config/database.php`
> (which reads `DB_PASSWORD` from the environment via `getenv('DB_PASSWORD')`).
