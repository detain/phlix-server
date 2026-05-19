# Server architecture

This page summarises the bootstrap path and the dependency-injection container
used by the Phlex Media Server. It is the developer-facing companion to the
top-level `CLAUDE.md` / `AGENTS.md`.

## Bootstrap & container

Since 0.10.0 the server bootstrap is centred on a PSR-11 container built by
`Phlex\Common\Container\ContainerFactory`. The factory composes four service
providers and returns a `\Psr\Container\ContainerInterface` instance. Both
`public/index.php` (web portal) and `Phlex\Server\Core\Application` resolve
their dependencies through the container instead of newing them up directly.

```text
config/server.php
       │
       ▼
ContainerFactory::create($config)
       │
       ├── CoreServicesProvider     ── DB connection, LoggerFactory, audit
       ├── AuthServicesProvider     ── JwtHandler, UserRepository, AuthManager
       ├── MediaServicesProvider    ── ItemRepository, Scanner, Watcher,
       │                              LibraryManager, MetadataManager, HLS
       └── SessionServicesProvider  ── SessionManager, PlaybackController
       │
       ▼
$container->get(AuthManager::class) // auto-wired, singleton
```

### Providers

Each provider implements `Phlex\Common\Container\ServiceProviderInterface`:

```php
public function register(ContainerBuilder $builder, array $appConfig): void;
```

The interface is marked `@internal` — third-party code should depend on the
PSR-11 `ContainerInterface` rather than the provider classes themselves. The
canonical provider stack lives in `ContainerFactory::defaultProviders()`; tests
or future plugins may append their own to the list passed to `create()`.

### Wrapping the legacy statics

`Workerman\MySQL\Connection` is bound to a factory that calls
`ConnectionPool::init()` / `ConnectionPool::getConnection('mysql')` on first
resolve. The static pool is still in place — A.1 wraps it so the connection
can be injected as a typed constructor parameter; a follow-up step replaces
the static entirely.

`LoggerFactory` and one named binding per `LogChannels` constant (for example,
`logger.auth`, `logger.media`) are exposed so providers can wire channelled
loggers via `DI\get('logger.auth')` without consumers having to know about
the factory.

### Adding a new binding

1. Pick the provider whose subsystem owns the class. If none fits, create a
   new provider in `src/Common/Container/Providers/` and append it to
   `ContainerFactory::defaultProviders()`.
2. For autowire-friendly classes, add `Foo\Bar::class => autowire()`.
3. For classes that need configuration values, use `factory(static fn () => ...)`
   and read from `$appConfig`.
4. Update the matching unit test in
   `tests/unit/Common/Container/Providers/` so the binding stays exercised
   (coverage target ≥ 85 % on `src/Common/Container/**`).

### Compiled containers (production)

Setting the env var `PHLEX_CONTAINER_COMPILE=1` enables PHP-DI's
compiled-container cache; the compiled definitions land in
`var/cache/container/` by default (override via `compile_dir` in
`config/server.php`). Compilation is disabled in development so new bindings
take effect without a manual cache clear.

> **Caveat:** PHP-DI 7's compiler rejects closures that capture variables via
> `use`. The current providers rely on closures for the DB / logger
> factories; Phase B replaces them with invokable classes so the cache works
> end-to-end. Until then, leave `PHLEX_CONTAINER_COMPILE` unset.

## Request lifecycle

`public/index.php`:

1. `include config/server.php` and inject the DB / logger config paths.
2. `ContainerFactory::create($config)` builds the container.
3. Resolve `AuthManager`, `LibraryManager`, `ItemRepository`,
   `PlaybackController` from the container.
4. Parse the request with `Phlex\Server\Http\Request::fromGlobals()`.
5. Authenticate the bearer token (if present) via `AuthManager`.
6. Route: `/api/*` goes to the JSON API placeholder; everything else is handed
   to `PageRenderer` for Smarty rendering.

`Application::fromConfigPath()` wraps steps 1–2 for callers that still pass a
config path (long-running Workerman worker, certain tests). The legacy
`Application::getInstance()` singleton is retained but `@deprecated`; resolve
services from the container instead.

## Dependencies → `detain/phlex-shared`

Since 0.11.0 (Step B.3), `phlex-server` depends on the
[`detain/phlex-shared`](https://github.com/detain/phlex-shared) Composer
package for its framework-neutral pieces:

- `Phlex\Shared\Plugin\{LifecycleInterface, Manifest, ManifestType, ManifestValidationError, EventNameMap}`
- `Phlex\Shared\Events\*` — the 12 PSR-14 event DTOs.
- `Phlex\Shared\Auth\JwtClaims` — value object capturing the Phlex JWT shape.
- `Phlex\Shared\Hub\*` — placeholder DTOs for the hub claim/heartbeat
  protocol (Phase C).

`phlex-server` keeps the host-side runtime (PSR-14 dispatcher wiring,
JSON-Schema validator, plugin loader, JWT signing, HTTP/WS layer). The
shared package is the contract surface that `phlex-server`,
`phlex-hub`, and plugin authors all import.

Legacy FQCNs (`Phlex\Plugins\Contract\LifecycleInterface`,
`Phlex\Plugins\EventNameMap`, etc.) remain available as deprecated
aliases through 0.11.x via `src/Plugins/AliasCompatShim.php` and the
3-line interface bridge at `src/Plugins/Contract/LifecycleInterface.php`.
They are removed in 0.12.0.

## See also

- `src/Common/Container/ContainerFactory.php` – the factory itself
- `src/Common/Container/Providers/` – all default providers
- `docs/reference/env-vars.md` – environment variables that influence the
  container (`PHLEX_CONTAINER_COMPILE`, `JWT_SECRET`)
- `plans/expansion/a.1-di-container.md` – the plan that introduced the
  container
- [`detain/phlex-shared`](https://github.com/detain/phlex-shared) –
  the shared Composer package consumed since 0.11.0.

---

## Namespace map

Every `Phlex\*` namespace, its key classes, and the role each plays:

| Namespace | Key classes | Role |
|----------|-------------|------|
| `Phlex\Auth\*` | `JwtHandler`, `UserRepository`, `AuthManager`, `UserProfileManager` | JWT auth (HS256, 1h access / 7d refresh), user management, profiles (≤5), parental PIN and rating filter |
| `Phlex\Media\Library\*` | `LibraryManager`, `MediaScanner`, `FolderWatcher`, `ItemRepository` | Media library scanning, filesystem watching (mtime checksum), `metadata_json` persistence |
| `Phlex\Media\Metadata\*` | `MetadataManager`, `TmdbProvider`, `TvdbProvider`, `FanartProvider`, `LocalNfoProvider` | Metadata fetching with provider priority (`tmdb→local` for movies, `tvdb→fanart→local` for series), 24 h cache |
| `Phlex\Media\Streaming\*` | `HlsStreamer`, `QualitySelector`, `StreamManager` | HLS master/variant `.m3u8` packaging, quality profiles (generic / mobile-low / mobile-high / web / tv-4k), stream selection (direct-play vs transcode) |
| `Phlex\Media\Transcoding\*` | `FfmpegRunner`, `EncodingHelper`, `TranscodeManager` | FFmpeg probe / transcode / thumbnail, CRF 23/28, libx264 / libx265, hardware acceleration |
| `Phlex\Session\*` | `SessionManager`, `PlaybackController`, `SyncPlay\*` | Device sessions, continue-watched (marks complete at 95 %), SyncPlay NTP-style time-sync (`OFFSET_SAMPLE_COUNT=5`, weighted-mean offset) |
| `Phlex\Hub\*` | `HubClient`, `RelayConsumer` | Hub claim protocol and relay heartbeat (Phase C) |
| `Phlex\Plugins\*` | `Loader`, `PluginManager`, `PluginLoader` | Plugin manifest loading, lifecycle management (install / enable / disable / uninstall) |
| `Phlex\LiveTv\*` | `ChannelManager`, `GuideManager`, `Recorder` | Live TV channels, EPG, DVR recording |
| `Phlex\Dlna\*` | `ContentDirectory`, `AvTransport`, `DlnaServer` | DLNA/DMS ContentDirectory and AVTransport services |
| `Phlex\Common\*` | `Container`, `ConnectionPool`, `QueryBuilder`, `LoggerFactory` | PSR-11 DI container, MySQL `Workerman\MySQL\Connection` pool, structured Monolog logging |
| `Phlex\Server\*` | `Core` (`Application`), `Http` (`Router`, `Controllers`), `WebSocket`, `WebPortal` | Workerman HTTP / WS entry, `{param}` routing, middleware groups, Smarty page rendering |

---

## PSR-14 event map

All dispatched PSR-14 events, their payload shapes, and the step that introduced them:

| Event name | Payload | Introduced |
|------------|---------|------------|
| `phlex.playback.started` | `{media_id, user_id, profile_id, position_ticks}` | A.2 |
| `phlex.playback.stopped` | `{media_id, user_id, position_ticks, completed}` | A.2 |
| `phlex.library.scanned` | `{library_id, item_count, duration}` | A.2 |
| `phlex.user.created` | `{user_id, email}` | A.2 |
| `phlex.scrobble.*` | `{media_id, user_id, scrobbler_type}` | A.2 |
| `phlex.webhook.*` | `{event_type, payload}` | A.2 |

> Wildcard patterns (`phlex.scrobble.*`, `phlex.webhook.*`) match all sub-events. Listeners use `EventDispatcher::getListeners($eventName)` with the wildcard to receive all variants. All payloads are `readonly` DTOs — plugins must not mutate them.

Full twelve-event catalog → [`docs/dev/event-reference.md`](event-reference.md).

---

## Test harness

### Running tests

```bash
./vendor/bin/phpunit                        # unit + integration suites
./vendor/bin/phpunit --testsuite Unit       # unit tests only
./vendor/bin/phpunit tests/unit/Auth/JwtHandlerTest.php --testdox
./vendor/bin/phpunit --coverage-text         # coverage → coverage.xml + coverage-report/
```

### Mocking the database

All DB access goes through `Workerman\MySQL\Connection`. Mock it like this:

```php
$db = $this->createMock(Workerman\MySQL\Connection::class);
$db->method('query')
   ->willReturn([['col' => 'val']]);  // SELECT result rows

$db->expects($this->once())
    ->method('query')
    ->with($this->stringContains('INSERT'), $this->anything());  // write assertion
```

### Test conventions

| Convention | Value |
|-----------|-------|
| Location | `tests/unit/{Module}/{Class}Test.php` |
| Namespace | `Phlex\Tests\Unit\{Module}` |
| Base class | `PHPUnit\Framework\TestCase` |
| BDD-style output | `./vendor/bin/phpunit --testdox` |

### Static analysis

```bash
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \;   # parse check only
```

---

## Debug recipes

### 1. Enable debug logging

```bash
PHLEX_LOG_LEVEL=debug php public/index.php
# Valid levels (least → most verbose): emergency, alert, critical, error, warning, notice, info, debug
```

Debug output lands in `.logs/phlex.log`. Filter by channel:

```bash
tail -f .logs/phlex.log | grep -i "debug\|error"
```

### 2. Xdebug

```bash
php -d xdebug.mode=debug -d xdebug.clientHost=localhost public/index.php
```

**VS Code** — `launch.json`:

```json
{
  "request": "launch",
  "pathMappings": {
    "/home/sites/phlex": "${workspaceFolder}"
  }
}
```

**PhpStorm** — set `DBGp Proxy` port `9003` and map server paths via the deployment configuration.

### 3. Tail logs in real time

```bash
# All errors
tail -f .logs/phlex.log | grep -i error

# Auth channel
tail -f .logs/auth.log

# Transcode logs
tail -f .logs/transcode/*.log

# Specific server
tail -f .logs/phlex.log | grep "192.168.1.100"
```

### 4. Verify phpstan on a single file

```bash
./vendor/bin/phpstan analyze src/Server/Http/Router.php --level=9 --no-progress
```
