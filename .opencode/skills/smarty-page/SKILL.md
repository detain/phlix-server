---
name: smarty-page
description: Adds a new Smarty template under `public/templates/{section}/*.tpl` (extending `layouts/main.tpl` via `{extends}`/`{block name="main"}`) and a matching `renderXxx(Request $request): Response` method in `src/Server/WebPortal/PageRenderer.php` that builds `new \Smarty()`, calls `setTemplateDir($this->templateDir)`, assigns data, fetches the template, and returns `(new Response())->html(...)`. Use when user says 'add page', 'new template', 'render view', 'add a Smarty view', or modifies anything under `public/templates/` or `PageRenderer.php`. Do NOT use for JSON API endpoints (those live in `WebPortalRouter` and return `(new Response())->json(...)` — use the http-controller / api-endpoint skill instead), and do NOT use for layout partials in `public/templates/partials/` (those are `{include}`-only fragments with no PageRenderer method).
---

# smarty-page

Adds a Smarty-rendered HTML page to the Phlex web portal. Every page requires two artifacts that must stay in sync: a `.tpl` file under `public/templates/{section}/` and a `renderXxx(Request $request): Response` method in `src/Server/WebPortal/PageRenderer.php`.

## Critical

- **HTML pages only.** If the response is JSON, stop — this is the wrong skill. Add a route in `src/Server/WebPortal/WebPortalRouter.php` instead.
- **Render method MUST return `Response`** built via `(new Response())->html($html)`. Never `echo`, never write `header()`, never return a string.
- **Smarty is instantiated per-render** as `new \Smarty()` with the leading backslash (root-namespaced). Do not add a `use Smarty;` line — existing methods in `PageRenderer.php` do not.
- **Every page template extends `layouts/main.tpl`** via `{extends file="layouts/main.tpl"}` and wraps its body in `{block name="main"}...{/block}`. The base layout already provides the sidebar nav, so do not re-emit `<html>`, `<head>`, or `<aside class="sidebar">`.
- **Assign `current_page`** as the first `assign()` call. The sidebar in `layouts/main.tpl` uses it to highlight the active nav (`{if $current_page == 'home'}active{/if}`). Skipping this leaves no nav item highlighted.
- **404 path**: when a route parameter resolves to nothing, return `(new Response())->status(404)->html('<h1>... not found</h1>');` BEFORE constructing the Smarty instance. See `renderLibrary()` at `src/Server/WebPortal/PageRenderer.php:148-154` for the exact pattern.
- **Wiring**: the new method is invoked from a route registration in `WebPortalRouter.php`. Adding the render method alone does not make the page reachable.

## Instructions

1. **Pick the page name and section.** Method becomes `render<Name>` (PascalCase). Template path becomes `public/templates/<section>/<view>.tpl`. Match existing convention: `renderHome` → `home/index.tpl`, `renderLibrary` → `library/index.tpl`, `renderLogin` → `auth/login.tpl`. Use `<section>/index.tpl` for the section's landing page and `<section>/<detail>.tpl` for sub-views (mirrors `library/index.tpl` + `library/detail.tpl`). Verify the chosen path is not already taken with `ls public/templates/<section>/` before proceeding.

2. **Create the template file** at `public/templates/<section>/<view>.tpl`. Use this exact skeleton (copied from `public/templates/home/index.tpl`):
   ```smarty
   {extends file="layouts/main.tpl"}

   {block name="title"}<Page Name> - Phlex{/block}

   {block name="main"}
   <div class="<section>-page">
       <!-- page content -->
   </div>
   {/block}
   ```
   Escape user-supplied strings with Smarty's `|escape` modifier and use `|default:'...'` for optional fields (e.g. `{$user.display_name|default:'Guest'}`). For media card lists, reuse `{include file="partials/media_card.tpl" item=$item}` rather than re-rendering card markup. Verify the template renders by running `php -l public/templates/<section>/<view>.tpl` won't work (Smarty is not PHP) — instead grep for unbalanced blocks: `grep -c '{block' public/templates/<section>/<view>.tpl` must equal `grep -c '{/block}' ...`.

3. **Add the render method to `src/Server/WebPortal/PageRenderer.php`.** Append it after the last existing `render*` method, keeping the file alphabetical-by-domain ordering (home → library → login). Use this exact shape (modeled on `renderLibrary` at lines 148-168):
   ```php
   public function render<Name>(Request $request<, array $params optional>): Response
   {
       // 1. Resolve params / load data via injected services ($this->libraryManager,
       //    $this->itemRepository, $this->playbackController). Do NOT new-up services here.

       // 2. 404 early if a required resource is missing:
       // if (!$resource) {
       //     return (new Response())->status(404)->html('<h1>X not found</h1>');
       // }

       $template = new \Smarty();
       $template->setTemplateDir($this->templateDir);

       $template->assign('current_page', '<section>');
       // additional $template->assign(...) calls

       $html = $template->fetch('<section>/<view>.tpl');

       return (new Response())->html($html);
   }
   ```
   Match the docblock style used elsewhere in the file — include `@param`, `@return`, `@template_variables` (list every assigned key), and `@example Template: <section>/<view>.tpl`. If the route has URL params, add the `array<string, string> $params` second arg exactly like `renderLibrary`. Verify with `php -l src/Server/WebPortal/PageRenderer.php` — must print `No syntax errors detected`.

4. **Register the route in `src/Server/WebPortal/WebPortalRouter.php`.** Web portal HTML routes are wired into the same `Router` instance used by JSON endpoints. Find the section where existing `PageRenderer` methods are registered and add the route alongside them. The handler closure receives `Request $request` (and a `$params` array if the route has placeholders) and must call `$this->pageRenderer->render<Name>($request<, $params>)`. Verify the route was wired by searching: `grep -n 'render<Name>' src/Server/WebPortal/WebPortalRouter.php` must return exactly one line.

5. **Pass data through assigned services only.** `PageRenderer`'s constructor injects `LibraryManager`, `ItemRepository`, and `PlaybackController`. If the new page needs another service, add it as a constructor parameter and a private typed property (follow the pattern of `private LibraryManager $libraryManager;` at line 33-40), then update the call site that constructs `PageRenderer` (search: `grep -rn 'new PageRenderer' src/ public/`). Do not introduce service locators, globals, or `require_once` inside the render method.

6. **Sidebar highlighting.** If the new page should appear in the sidebar, edit `public/templates/layouts/main.tpl` to add a nav `<a>` whose `{if $current_page == '<section>'}active{/if}` matches the value assigned in step 3. If the page is a sub-view (detail page), set `current_page` to the parent section so the parent nav stays highlighted (e.g. `library/detail.tpl` should still assign `'library'`).

7. **Smoke-test the rendered output.** With the dev server running, `curl -s -o /tmp/page.html -w '%{http_code}\n' http://localhost:<port>/<route>` must return `200`, and `grep -c 'class="sidebar"' /tmp/page.html` must return `1` (proves the layout extended correctly). For 404 paths, `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:<port>/<bad-route>` must return `404`.

8. **Add a unit test** at `tests/unit/Server/WebPortal/PageRendererTest.php` (create the directory if absent — mirrors `tests/unit/Auth/`, `tests/unit/Session/`, etc.). Test should construct `PageRenderer` with stubbed dependencies, call `render<Name>()`, and assert the returned `Response` has status `200` (or `404` for the missing-resource path) and that the HTML body contains a section-specific marker (e.g. the `<div class="<section>-page">` from step 2). Run `vendor/bin/phpunit tests/unit/Server/WebPortal/PageRendererTest.php` — must report `OK`.

## Examples

**User says:** "Add a settings page for the web portal."

**Actions taken:**
1. Create `public/templates/settings/index.tpl`:
   ```smarty
   {extends file="layouts/main.tpl"}
   {block name="title"}Settings - Phlex{/block}
   {block name="main"}
   <div class="settings-page">
       <h1>Settings</h1>
       <section class="settings-section">
           <h2>Account</h2>
           <p>Signed in as {$user.display_name|default:'Guest'|escape}</p>
       </section>
   </div>
   {/block}
   ```
2. Append `renderSettings(Request $request): Response` to `src/Server/WebPortal/PageRenderer.php`:
   ```php
   public function renderSettings(Request $request): Response
   {
       $template = new \Smarty();
       $template->setTemplateDir($this->templateDir);
       $template->assign('current_page', 'settings');
       $template->assign('user', ['display_name' => 'User']);
       $html = $template->fetch('settings/index.tpl');
       return (new Response())->html($html);
   }
   ```
3. Register `GET /settings` in `WebPortalRouter.php` pointing at `$this->pageRenderer->renderSettings($request)`.
4. The sidebar in `layouts/main.tpl` already has `<a href="/settings" class="nav-item {if $current_page == 'settings'}active{/if}">` — no edit needed; the nav now highlights when the page loads.

**Result:** `curl http://localhost:<port>/settings` returns 200 with the sidebar showing "Settings" highlighted.

## Common Issues

- **`Smarty\Exception: Unable to load template file 'X/Y.tpl'`** — `setTemplateDir($this->templateDir)` was called but the file is in the wrong place. Run `ls -la $(php -r "require 'vendor/autoload.php'; echo realpath(__DIR__.'/public/templates');")/<section>/<view>.tpl`. Path is relative to `$this->templateDir`, not the project root.
- **Page renders without sidebar / no styling** — the template forgot `{extends file="layouts/main.tpl"}` or wrapped its content in `{block name="body"}` instead of `{block name="main"}`. `body` is owned by `layouts/base.tpl`; portal pages override `main`. Compare against `public/templates/home/index.tpl:5`.
- **Sidebar shows no active nav item** — `$template->assign('current_page', '<section>')` was omitted or the value doesn't match the `{if $current_page == '...'}` in `layouts/main.tpl:13-28`. Values currently recognized: `home`, `library`, `search`, `settings`.
- **`Call to undefined method Phlex\Server\Http\Response::html()`** — wrong namespace imported. The top of `PageRenderer.php` uses `use Phlex\Server\Http\Response;` (line 8). Do not import `Symfony\...\Response` or PSR-7 `ResponseInterface`.
- **`Smarty\Exception: Syntax error in template ... unbalanced block`** — `{block name="main"}` has no matching `{/block}`, or a `{foreach}`/`{if}` is unclosed. Quick check: `awk '/\{block|\{foreach|\{if/{o++} /\{\/block|\{\/foreach|\{\/if/{c++} END{print o, c}' public/templates/<section>/<view>.tpl` — open and close counts must match.
- **Route 404s even though `renderXxx` exists** — step 4 was skipped. `grep -n 'render<Name>' src/Server/WebPortal/WebPortalRouter.php` returns nothing. Add the route registration; restart the dev server so the router rebuilds.
- **`Cannot instantiate Smarty: class not found`** — Smarty must be loaded via Composer autoload. Run `composer require smarty/smarty` if missing, then `composer dump-autoload`. `vendor/smarty/smarty/` should exist.
- **HTML shows literal `{$user.display_name}`** — template assignment missing for that variable. Add `$template->assign('user', [...]);` before `fetch()`. Smarty leaves unresolved tags as literal text rather than erroring.