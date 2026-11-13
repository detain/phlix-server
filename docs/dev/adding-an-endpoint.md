# Adding an HTTP endpoint to phlix-server

The real loop is: **Controller -> route registration -> middleware -> openapi.yaml -> tests**.
Skipping the OpenAPI step turns the suite red by design.

## 1. Controller

Add a class (or a method on an existing one) under `src/Server/Http/Controllers/`.
Handlers take a `Request` and return a `Response`. Resolve collaborators from the PSR-11
container rather than constructing them inline.

## 2. Register the route

Routes are registered on `Phlix\Server\Http\Router` (`get`, `post`, `put`, `delete`, `group`)
from `src/Server/Core/Application.php`, in `loadRoutes()` / `loadApiRoutes()` or one of the
focused `load*Routes()` helpers there (webhooks, backup, services, and so on). Existing
registrations in those methods are the exemplar: copy the nearest sibling.
Portal/UI routes live in `Phlix\Server\WebPortal\WebPortalRouter`. The served surface is the
union of both routers; `Application` wins on overlap (`HttpHandler` tries it first).

## 3. Middleware

Pass middleware as the `$middleware` array of `group()` (`Router::get/post/put/delete`
accept only `($path, $handler)`), e.g. `[new \Phlix\Server\Http\Middleware\AuthMiddleware()]`. A route with no
middleware is anonymous: if a handler must be gated, gate it explicitly. Chain order matters
and is checked.

## 4. openapi.yaml

Add the operation to `openapi.yaml` at the repo root, with its success codes, and set
`x-phlix-middleware` to the real chain by class short name, in order (`[]` for none,
`[AuthMiddleware]` for the auth chain).

## 5. Tests

`tests/Unit/Server/Contracts/OpenapiSpecCurrencyTest.php` recomposes the live routers from
the production container and asserts the spec describes EXACTLY the served operations
(no phantom, no undocumented) and that every `x-phlix-middleware` equals the real chain.
Run it after any route change. Also add a unit test for the controller and, where the route
shape matters, follow `ApplicationRouterWirePathGuardTest` in `tests/Unit/Server/Core/`.
Run PHPUnit and PHPStan with the repo's usual commands (see the README and `composer.json`).

## Docs links

README links to the phlix-docs site (`https://detain.github.io/phlix-docs/...`) track the
docs site's current pages, not a pinned ref; they can drift. If a link 404s, fix the README.
