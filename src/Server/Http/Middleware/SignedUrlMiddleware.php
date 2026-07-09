<?php

/**
 * Phlix media server component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Middleware;

use Phlix\Auth\SignedUrl;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Gates the byte-serving / streaming routes that a media element, e-reader, or
 * native player requests WITHOUT an `Authorization: Bearer` header.
 *
 * These routes (`/hls/**`, `/dash/**`, book read/cover/download, audiobook
 * read/stream, photo thumbnail/full, OPDS feeds) cannot use the JSON
 * {@see AuthMiddleware} because the requesting `<video>`/`<img>`/`<audio>` or
 * e-reader supplies no header. They were previously world-readable to anyone who
 * knew the (UUID) id. This middleware requires proof of an authenticated session
 * via ANY of three mechanisms, tried in order:
 *
 *  1. **Existing session** — `$request->userId` is already populated from a
 *     Bearer header or the `phlix_session` cookie by the HTTP entry point (see
 *     `public/index.php` and {@see \Phlix\Server\Workerman\HttpHandler}). This is
 *     what makes the in-browser player keep working untouched: hls.js attaches
 *     the Bearer token to every segment XHR via `xhrSetup`, and same-origin
 *     `<img>`/`<video>` send the session cookie automatically.
 *  2. **Signed URL** — a `?exp&sig` token minted by the now-gated JSON detail
 *     endpoints and verified against the request path by {@see SignedUrl}. This
 *     covers cookieless/headerless contexts: native players, casting, and
 *     cross-origin embeds.
 *  3. **HTTP Basic** (opt-in via {@see self::forOpds()}) — for OPDS e-reader
 *     clients, which authenticate with `Authorization: Basic` and re-send it on
 *     every request. On failure a `WWW-Authenticate: Basic` challenge is emitted
 *     so the reader prompts for credentials.
 *
 * Returning `null` continues routing; returning a {@see Response} short-circuits
 * the chain (per {@see \Phlix\Server\Http\Router::runMiddleware()} semantics).
 *
 * Note on CSRF: every gated route is a read-only GET that serves bytes back to
 * the requester; a cross-site `<img>`/`<video>` embed can trigger a fetch with
 * the victim's cookie but cannot READ the cross-origin bytes, and still needs the
 * unguessable UUID — so accepting the cookie here introduces no CSRF exposure
 * (same rationale as {@see AuthMiddleware}).
 *
 * @package Phlix\Server\Http\Middleware
 * @since 0.44.0
 */
final class SignedUrlMiddleware
{
    /** Validates Basic credentials → user id, or null. Set only for OPDS. */
    private readonly ?\Closure $basicValidator;

    /**
     * @param bool          $allowBasic     Accept HTTP Basic credentials (OPDS only).
     * @param string        $realm          Realm shown in the Basic auth challenge.
     * @param \Closure|null $basicValidator `fn(string $user, string $pass): ?string` returning
     *                                      the user id on success; required when $allowBasic.
     * @param SignedUrl|null $signer        Signature verifier; defaults to {@see SignedUrl::fromEnv()}.
     */
    public function __construct(
        private readonly bool $allowBasic = false,
        private readonly string $realm = 'Phlix',
        ?\Closure $basicValidator = null,
        private readonly ?SignedUrl $signer = null,
    ) {
        $this->basicValidator = $basicValidator;
    }

    /**
     * Convenience factory for the OPDS feeds: enables HTTP Basic with the
     * supplied credential validator and an OPDS realm.
     *
     * @param \Closure $basicValidator `fn(string $user, string $pass): ?string`.
     */
    public static function forOpds(\Closure $basicValidator, ?SignedUrl $signer = null): self
    {
        return new self(true, 'Phlix OPDS', $basicValidator, $signer);
    }

    /**
     * @param Request $request Incoming request; `$request->userId` is pre-filled
     *                         by the entry point when a session is present.
     */
    public function __invoke(Request $request): ?Response
    {
        // 1) Already-authenticated session (Bearer header or session cookie).
        $userId = $request->userId;
        if ($userId !== null && $userId !== '') {
            RequestContext::setUserId($userId);

            return null;
        }

        // 2) Signed URL token.
        $signer = $this->signer ?? SignedUrl::fromEnv();
        if ($signer->verify($request->path, $request->queryString('exp'), $request->queryString('sig'))) {
            return null;
        }

        // 3) HTTP Basic (OPDS e-readers).
        if ($this->allowBasic && $this->basicValidator !== null) {
            $credentials = self::parseBasicCredentials($request->getHeader('Authorization'));
            if ($credentials !== null) {
                $resolved = ($this->basicValidator)($credentials[0], $credentials[1]);
                if (is_string($resolved) && $resolved !== '') {
                    $request->userId = $resolved;
                    RequestContext::setUserId($resolved);

                    return null;
                }
            }

            // Prompt the reader to (re)send credentials.
            return (new Response())
                ->status(401)
                ->header('WWW-Authenticate', 'Basic realm="' . $this->realm . '", charset="UTF-8"')
                ->json(['error' => 'Unauthorized', 'code' => 'auth.required']);
        }

        return (new Response())
            ->status(401)
            ->json(['error' => 'Unauthorized', 'code' => 'auth.required']);
    }

    /**
     * Parses an `Authorization: Basic <base64(user:pass)>` header.
     *
     * @return array{0: string, 1: string}|null `[username, password]`, or null
     *                                           when the header is absent or malformed.
     */
    private static function parseBasicCredentials(?string $authorizationHeader): ?array
    {
        if (
            $authorizationHeader === null
            || preg_match('/^\s*Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $authorizationHeader, $m) !== 1
        ) {
            return null;
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false) {
            return null;
        }

        $separator = strpos($decoded, ':');
        if ($separator === false) {
            return null;
        }

        return [substr($decoded, 0, $separator), substr($decoded, $separator + 1)];
    }
}
