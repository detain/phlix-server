# Environment variables

Reference list of every environment variable read by the Phlex Media Server,
its default, and a one-line description. Each entry links back to the file
that consumes it.

## Container & bootstrap

| Variable                   | Default | Description |
| -------------------------- | ------- | ----------- |
| `PHLEX_CONTAINER_COMPILE`  | _unset_ | When truthy (`1`, `true`, `yes`, `on`) enables PHP-DI's compiled-container cache in `var/cache/container/`. Disabled by default for dev parity. See `Phlex\Common\Container\ContainerFactory`. |

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
