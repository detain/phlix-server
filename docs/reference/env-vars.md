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
| `PHLEX_PLUGINS_REQUIRE_SIGNATURE`     | `0`     | When truthy, the plugin loader refuses to install unsigned plugins and refuses signatures missing from the trusted-key allowlist. Default off, which means unsigned plugins install with a warning on the `plugins` log channel. See `Phlex\Plugins\Signature\SignatureVerifier`. |
| `PHLEX_PLUGINS_COMPOSER_TIMEOUT`      | `120`   | Hard timeout (seconds) on the per-plugin `composer install --no-dev` subprocess. See `Phlex\Plugins\Installer\ComposerRunner`. |

## Auth

| Variable      | Default                       | Description |
| ------------- | ----------------------------- | ----------- |
| `JWT_SECRET`  | `default-secret-change-me`    | HMAC secret used to sign / verify JWT access and refresh tokens. The default is intentionally insecure so a missing env var fails closed in production deployments. Read by `Phlex\Common\Container\Providers\AuthServicesProvider`. |

## Hub / Pairing

| Variable                        | Default | Description |
| ------------------------------- | ------- | ----------- |
| `PHLEX_HUB_URL`                 | _unset_ | Base URL of the Phlex Hub to pair with (e.g. `https://hub.example.com`). When set, the server initiates pairing automatically on startup if enrolled. See `Phlex\Hub\HubClient` and `config/hub.php`. |
| `PHLEX_HUB_JWKS_URL`            | _unset_ | URL of the hub's JWKS endpoint for validating hub-issued JWTs. Typically set automatically during enrollment. See `Phlex\Hub\HubJwtValidator` and `config/hub.php`. |
| `PHLEX_HUB_ENROLLMENT_TOKEN`    | _unset_ | When set, overrides the enrollment token read from `config/hub-enrollment.json`. Mostly useful for automation / container orchestration. See `Phlex\Hub\HubClient::loadEnrollment()`. |
| `PHLEX_HUB_HEARTBEAT_INTERVAL`  | `60`    | Interval in seconds between hub heartbeat calls. Must be between 30 and 3600. See `Phlex\Hub\HubClient::startHeartbeatLoop()` and `config/hub.php`. |
| `PHLEX_SUBDOMAIN_AUTO_CLAIM`    | `1`     | When truthy (`1`, `true`, `yes`, `on`) automatically claims a *.phlex.media subdomain from the hub after enrollment. See `Phlex\Hub\SubdomainClient` and `config/hub.php`. |
| `PHLEX_TLS_ENABLED`            | `1`     | When truthy enables TLS/HTTPS for the server's public hostname. Requires a subdomain to be allocated. See `config/hub.php`. |
| `PHLEX_DOMAIN`                 | `phlex.media` | The base domain for server subdomains (e.g. `abc12345.phlex.media`). See `config/hub.php`. |

## Relay tunnel

| Variable                       | Default                            | Description |
| ------------------------------ | ---------------------------------- | ----------- |
| `PHLEX_RELAY_ENABLED`          | `0`                                | When truthy (`1`, `true`, `yes`, `on`) enables the persistent WSS relay tunnel to the hub. Requires the server to be enrolled. See `Phlex\Hub\RelayConfig` and `config/relay.php`. |
| `PHLEX_RELAY_HUB_URL`          | `wss://hub.example.com/api/v1/servers/{id}/relay` | WebSocket URL of the hub relay endpoint. `{id}` is replaced with the server's UUID from enrollment. See `Phlex\Hub\RelayConsumer` and `config/relay.php`. |
| `PHLEX_RELAY_TUNNEL_HOSTNAME`  | _empty_                            | Public hostname assigned to this server's relay tunnel (e.g. `my-server.phlex.media`). See `Phlex\Hub\RelayConfig` and `config/relay.php`. |
| `PHLEX_RELAY_RECONNECT_DELAY`  | `5`                                | Seconds to wait before attempting to reconnect after the relay tunnel is disconnected. See `Phlex\Hub\RelayConfig` and `config/relay.php`. |
| `PHLEX_RELAY_PING_INTERVAL`    | `30`                               | Seconds between keep-alive ping frames sent over the relay tunnel. See `Phlex\Hub\RelayConfig` and `config/relay.php`. |
| `PHLEX_RELAY_PING_TIMEOUT`     | `10`                               | Seconds to wait for a pong response before considering the relay connection dead. See `Phlex\Hub\RelayConfig` and `config/relay.php`. |

## Port forwarding / remote access

| Variable                     | Default       | Description |
| ---------------------------- | ------------- | ----------- |
| `PHLEX_PORT_FORWARD_AUTO`    | `1`          | When truthy (`1`, `true`, `yes`, `on`) enables automatic port forwarding via UPnP-IGD on startup. See `Phlex\Network\PortForwardService` and `config/port-forward.php`. |
| `PHLEX_EXTERNAL_PORT`        | `32400`      | Port to use for automatic port forwarding. Both external and internal ports use this value by default. See `Phlex\Network\PortForwardService` and `config/port-forward.php`. |
| `PHLEX_EXTERNAL_HTTP_PORT`   | `8080`       | External HTTP port for the web portal when accessed remotely. See `config/port-forward.php`. |
| `PHLEX_EXTERNAL_HTTPS_PORT`  | `8443`       | External HTTPS port for the web portal when accessed remotely. See `config/port-forward.php`. |
| `PHLEX_UPNP_ENABLED`          | `1`          | When truthy enables UPnP-IGD port mapping attempts. When falsy, only STUN-based external IP detection is used. See `Phlex\Network\UpnpIgdClient` and `config/port-forward.php`. |
| `PHLEX_STUN_SERVER`          | `stun.l.google.com` | STUN server hostname for discovering the server's public IP address. See `Phlex\Network\StunClient` and `config/port-forward.php`. |
| `PHLEX_STUN_PORT`            | `19302`       | STUN server port. See `Phlex\Network\StunClient` and `config/port-forward.php`. |

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
