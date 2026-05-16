---
name: http-controller
description: Creates HTTP controller classes in src/Server/Http/Controllers/ following the project's Request/Response pattern with chained (new Response())->status()->json() calls, constructor-injected services, and $params array from {id} route matches. Registers the route in src/Server/Http/Router.php (API) or src/Server/WebPortal/WebPortalRouter.php (web portal). Use when user says 'add endpoint', 'new route', 'new controller', 'create API endpoint', 'add HTTP handler', or adds files to src/Server/Http/Controllers/. Do NOT use for WebSocket events (use websocket-event skill), static page rendering with Smarty templates (use smarty-page skill), or DLNA/UPnP protocol handlers (different stack in src/Dlna/).
---

# HTTP Controller

## Critical

- Controllers live ONLY in `src/Server/Http/Controllers/` — never inline route logic in `Router.php`.
- Every handler method MUST return a `Response` object built with the chained API: `return (new Response())->status(200)->json($data);`. Never `echo`, never return arrays or strings directly.
- Dependencies are constructor-injected. Do NOT use service locators, `new` inside methods, or static accessors for services.
- `$params` is the second handler argument and contains route matches (e.g. `{id}` → `$params['id']`). Always validate `$params['id']` before passing to a service.
- API routes register in `src/Server/Http/Router.php`. Web portal (HTML) routes register in `src/Server/WebPortal/WebPortalRouter.php`. Picking the wrong router will 404.
- Every new controller needs a matching unit test in `tests/unit/Server/Http/Controllers/` before the task is complete.

## Instructions

1. **Identify scope.** Decide whether this is an API endpoint (JSON, mounted under `Router.php`) or a web portal route (HTML, mounted under `WebPortalRouter.php`). API is the default for new endpoints. Verify by grepping the existing router file for similar routes before proceeding.

2. **Pick a name and path.** Controllers are PascalCase and end in `Controller` (e.g. `LibraryController`, `PlaybackController`). The file path is `src/Server/Http/Controllers/<Name>Controller.php`. Namespace is `Jellyfin\\Server\\Http\\Controllers` (or the project's existing controller namespace — confirm by reading any existing controller in that directory first). Verify the file does not already exist before proceeding to the next step.

3. **Read one existing controller as the template.** Open the most recently modified file in `src/Server/Http/Controllers/` and copy its exact structure: namespace, use statements, constructor signature style, method signature `(Request $request, array $params): Response`, and Response chaining. Your new controller must match that file's conventions line-for-line on structure. This step's output (the imports + constructor pattern) is reused in Step 4.

4. **Create the controller class.** Using the template from Step 3, write the controller with:
   - One `use` line per imported class (no grouped imports).
   - Constructor accepts the service(s) it needs as typed properties: `public function __construct(private readonly LibraryService $library) {}`.
   - One public method per route handler. Signature: `public function show(Request $request, array $params): Response`. Common method names: `index`, `show`, `store`, `update`, `destroy`, `stream` — pick the one that matches the HTTP verb + intent.
   - Body pattern:
     ```php
     $id = $params['id'] ?? null;
     if ($id === null) {
         return (new Response())->status(400)->json(['error' => 'id required']);
     }
     $item = $this->library->find($id);
     if ($item === null) {
         return (new Response())->status(404)->json(['error' => 'not found']);
     }
     return (new Response())->status(200)->json($item->toArray());
     ```
   - For non-JSON responses (streams, redirects), use the matching Response method (`->stream($resource)`, `->redirect($url)`) — check `src/Server/Http/Response.php` for available chainable methods. Verify the controller parses with `php -l src/Server/Http/Controllers/<Name>Controller.php` before proceeding.

5. **Register the route.** Open the correct router (Step 1):
   - API: `src/Server/Http/Router.php`
   - Web portal: `src/Server/WebPortal/WebPortalRouter.php`
   Add the route inside the existing registration block, matching the surrounding style. Typical form:
   ```php
   $this->get('/api/library/{id}', [LibraryController::class, 'show']);
   $this->post('/api/library', [LibraryController::class, 'store']);
   ```
   Add a `use` statement for the controller at the top of the router file. Verify by grepping the router for the new path: it must appear exactly once.

6. **Wire dependency injection.** If the controller needs a service that is not already auto-wired, register the binding in the DI configuration (look for `config/services.php`, `config/container.php`, or wherever existing controllers' services are bound — search with `grep -rn 'LibraryService::class' config/ src/`). Skip this step only if the service is already a constructor-injectable class with no setup.

7. **Add the unit test.** Create `tests/unit/Server/Http/Controllers/<Name>ControllerTest.php` extending `PHPUnit\\Framework\\TestCase`. Mock the injected service, instantiate the controller, call the method with a stub `Request` and `$params` array, and assert the returned `Response`'s status and JSON body. Match the test style of the most recent test in `tests/unit/Server/Http/Controllers/` (or `tests/unit/` if that subdir is empty). Run: `vendor/bin/phpunit tests/unit/Server/Http/Controllers/<Name>ControllerTest.php` — must be green before the task is complete.

8. **Smoke-test the route.** Boot the server (look in `README.md` or `composer.json` scripts for the start command — commonly `php -S localhost:8096 -t public/` or `composer serve`). Hit the new endpoint with `curl -i http://localhost:8096/api/<path>` and confirm the status code and JSON body match expectations. If 404, re-check Step 5 — the router didn't pick up the route.

## Examples

**User says:** "Add an endpoint that returns a library item by id."

**Actions taken:**
1. Confirm API scope → register in `Router.php`.
2. Read `src/Server/Http/Controllers/SessionController.php` as the template.
3. Create `src/Server/Http/Controllers/LibraryController.php`:
   ```php
   <?php
   declare(strict_types=1);

   namespace Jellyfin\\Server\\Http\\Controllers;

   use Jellyfin\\Media\\Library\\LibraryService;
   use Jellyfin\\Server\\Http\\Request;
   use Jellyfin\\Server\\Http\\Response;

   final class LibraryController
   {
       public function __construct(private readonly LibraryService $library) {}

       public function show(Request $request, array $params): Response
       {
           $id = $params['id'] ?? null;
           if ($id === null) {
               return (new Response())->status(400)->json(['error' => 'id required']);
           }
           $item = $this->library->find($id);
           if ($item === null) {
               return (new Response())->status(404)->json(['error' => 'not found']);
           }
           return (new Response())->status(200)->json($item->toArray());
       }
   }
   ```
4. In `src/Server/Http/Router.php`, add `use Jellyfin\\Server\\Http\\Controllers\\LibraryController;` and `$this->get('/api/library/{id}', [LibraryController::class, 'show']);`.
5. Add `tests/unit/Server/Http/Controllers/LibraryControllerTest.php` with cases for: missing id → 400, unknown id → 404, valid id → 200 + payload.
6. Run `vendor/bin/phpunit tests/unit/Server/Http/Controllers/LibraryControllerTest.php` — green.
7. `curl -i http://localhost:8096/api/library/abc123` returns the item JSON.

**Result:** `GET /api/library/{id}` returns the library item or a typed error response, fully tested, matching every other controller in the project.

## Common Issues

- **`404 Not Found` from curl but the controller class exists.** The route was registered in the wrong router. API routes belong in `src/Server/Http/Router.php`. If you put it in `WebPortalRouter.php`, it will only resolve under the web portal mount. Move the registration and re-test.

- **`Class "LibraryController" not found` when the router boots.** Missing `use` statement at the top of the router file, or the class namespace doesn't match the directory. Run `composer dump-autoload` then verify the namespace declaration in your controller matches PSR-4 mapping in `composer.json`.

- **`Argument #1 ($library) must be of type LibraryService, null given`.** The DI container isn't wiring the service. Check the container config (Step 6) and ensure the service is registered. Confirm with: `grep -rn 'LibraryService' config/ src/Server/` — there must be a binding outside the controller itself.

- **`Call to undefined method Response::json()`.** You imported the wrong `Response` class (likely a stdlib or third-party one). The correct import is `Jellyfin\\Server\\Http\\Response` (or whatever the existing controllers use — verify by `grep -h 'use.*Response' src/Server/Http/Controllers/*.php | sort -u`).

- **`$params['id']` is always null even though the URL has the id segment.** The route pattern in the router uses a different placeholder name than what you're reading. If the route is `/api/library/{itemId}`, read `$params['itemId']`, not `$params['id']`. Names must match exactly.

- **Test passes but real curl request returns 500.** The test mocked the service, but the real DI binding throws on construction. Reproduce locally without mocks by booting the server and tailing `logs/` (or wherever `config/` points logging) for the stack trace. Fix the binding, do not silence the test.

- **`Headers already sent` warning in production response.** Something `echo`'d or `print`'d before the `Response` was returned. Search the controller and any service it calls for stray output: `grep -rn 'echo\\|print\\|var_dump' src/Server/Http/Controllers/ src/<service-dir>/`.