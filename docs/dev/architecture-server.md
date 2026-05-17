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
