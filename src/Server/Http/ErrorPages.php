<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

/**
 * The server-rendered fallback pages: 404, last-resort 500, missing-bundle 503.
 *
 * Until server minors M-SERVER-87 (batch W-min103) these were bare fragments
 * — `'<h1>404 - Page not found</h1>'` at five HttpHandler dispatch sites, a
 * `'<h1>500 Internal Server Error</h1>'` in the last-resort catch, and a
 * 503 on SharedUiController's missing-bundle arms whose only copy was an
 * operator command line. A browser showed them with default CSS on a blank
 * page, and the 503 told an end *user* to run `npm run build`.
 *
 * Every page is now a complete document in the shape the repo already
 * carried as its fix-pattern, `TraktOAuthController::notConfiguredPage()`:
 * doctype, `lang`, charset, viewport, a legible styled layout that follows
 * the visitor's light/dark preference, real copy, and a home affordance.
 * The documents are STATIC — nothing echoes the requested path or any
 * exception text onto the page (the pre-existing 500 law, kept: detail
 * belongs to the HTTP error log, never the wire).
 *
 * S113 stays load-bearing: `serverErrorHtml()` exists so the raw
 * `catch (Throwable)` arm can keep computing its own `Content-Length` and
 * keep answering `HEAD` with a bodyless reply. Changing the body never
 * reopens that seam — the five 404 arms and the 503 arms return ordinary
 * `Response` objects, which the head-only flag already governs.
 *
 * Deliberately NOT included: a request id for support correlation. The
 * 500 arm runs after every other context may have failed; minting an id
 * there means a new header/correlation story across the estate, and the
 * HTTP error log already carries exception class, message, file and line.
 * Disclosed in the batch CHANGELOG as the ask's one unshipped element.
 */
final class ErrorPages
{
    /**
     * Shared chrome for every fallback page (same family as the Trakt
     * "not configured" page, plus dark-mode support — these pages are
     * exactly what a user of a half-broken server sees, and browsers are
     * dark-mode-everything now).
     */
    private const CHROME = <<<'CSS'
  body { font-family: system-ui, sans-serif; max-width: 36rem; margin: 4rem auto;
         padding: 0 1.5rem; line-height: 1.6; color: #1a1a1a; background: #fff; }
  h1 { font-size: 1.6rem; margin: 0.25rem 0 0; }
  .kicker { margin: 0; color: #b81d24; font-weight: 600; font-size: 0.8rem;
            letter-spacing: 0.08em; text-transform: uppercase; }
  p { margin: 1rem 0; }
  a { color: #b81d24; }
  .home { margin-top: 2rem; display: inline-block; }
  details { margin-top: 2rem; font-size: 0.85rem; color: #555; }
  code { background: #f3f3f3; padding: 0.1rem 0.35rem; border-radius: 4px; }
  ol { padding-left: 1.25rem; }
  @media (prefers-color-scheme: dark) {
    body { color: #e8e8e8; background: #111; }
    code { background: #222; }
    a, .kicker { color: #e0555d; }
    details { color: #999; }
  }
CSS;

    /** The unrouted-page 404, as the app `Response` the dispatch arms return. */
    public static function notFound(): Response
    {
        return (new Response())->status(404)->html(self::notFoundHtml());
    }

    /** The 404 document body, callable on its own for byte-level assertions. */
    public static function notFoundHtml(): string
    {
        $body = <<<'HTML'
<p class="kicker">404</p>
<h1>Page not found</h1>
<p>
  This server has no page at that address. If you followed a link, it is out
  of date &mdash; if you typed it, give the address one more look.
</p>
<p>Everything Phlix shows &mdash; libraries, players, admin &mdash; lives in the web portal.</p>
<a class="home" href="/app">Open the Phlix web portal</a>
HTML;

        return self::document('Page not found', $body);
    }

    /**
     * The last-resort 500 body for HttpHandler's raw `catch (Throwable)` arm.
     *
     * Copy stays generic on purpose: the exception itself goes only to the
     * HTTP error log (pre-existing law from the majors SRV-05 work — no raw
     * exception text on the wire).
     */
    public static function serverErrorHtml(): string
    {
        $body = <<<'HTML'
<p class="kicker">500</p>
<h1>Something went wrong</h1>
<p>
  The server hit an unexpected error while handling this request. Your retry
  may well succeed &mdash; if it does not, the exact failure was written to
  the server&rsquo;s HTTP error log, so an administrator can see what broke.
</p>
<a class="home" href="/app">Back to the Phlix web portal</a>
HTML;

        return self::document('Server error', $body);
    }

    /** Missing/unbuilt web-portal bundle (SharedUiController, ViteAssets leg). */
    public static function uiNotBuilt(): Response
    {
        $body = <<<'HTML'
<p class="kicker">503</p>
<h1>The web portal is not ready yet</h1>
<p>
  This server&rsquo;s web UI bundle has not been installed yet. Visitors:
  please check back shortly &mdash; the rest of the server is unaffected.
</p>
<details>
  <summary>Server administrator: how to fix this</summary>
  <ol>
    <li>
      Install the prebuilt bundle from a release package or repository checkout
      (the committed <code>public/assets/app/</code> directory is the runtime
      artifact).
    </li>
    <li>
      Or build it from source in a development checkout:
      <code>cd web-ui &amp;&amp; npm install &amp;&amp; npm run build</code>, then
      commit or deploy the generated <code>public/assets/app/</code> output.
    </li>
  </ol>
</details>
HTML;

        return (new Response())->status(503)->html(self::document('Web portal not ready', $body));
    }

    /** Bundle manifest passed but the shell file could not be read. */
    public static function uiUnreadable(): Response
    {
        $body = <<<'HTML'
<p class="kicker">503</p>
<h1>The web portal could not be opened</h1>
<p>
  The installed web UI files could not be read &mdash; they may have been
  removed mid-request or their permissions changed. An administrator should
  re-run the bundle install described in the server&rsquo;s release process.
</p>
<a class="home" href="/app">Try again</a>
HTML;

        return (new Response())->status(503)->html(self::document('Web portal unreadable', $body));
    }

    /**
     * Wrap a page body in the shared document chrome.
     *
     * @param string $titleFragment appears in the browser tab, as "&hellip; &mdash; Phlix"
     * @param string $bodyHtml      the pre-authored static section markup
     */
    private static function document(string $titleFragment, string $bodyHtml): string
    {
        return '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . "<head>\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex">' . "\n"
            . '<title>' . $titleFragment . " &mdash; Phlix</title>\n"
            . "<style>\n" . self::CHROME . "</style>\n"
            . "</head>\n<body>\n"
            . $bodyHtml . "\n"
            . "</body>\n</html>\n";
    }
}
