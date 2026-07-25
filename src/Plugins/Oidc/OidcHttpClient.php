<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Phlix\Plugins\OAuth2\OAuth2HttpClient;

/**
 * Non-blocking HTTP client for the OIDC provider flow.
 *
 * OIDC performs four outbound HTTP calls, all of which used to block the
 * resident Workerman worker with `file_get_contents()` / `curl_exec()`:
 *
 *  - discovery document  ({@see DiscoveryDocument::fetchDiscoveryDocument()})
 *  - token exchange      ({@see OidcProvider::exchangeCode()})
 *  - userinfo            ({@see OidcProvider::authenticateWithAccessToken()})
 *  - JWKS                ({@see IdTokenValidator::fetchJwks()})
 *
 * The implementation — the async {@see \Workerman\Http\Client} cooperative wait
 * plus the bounded, TLS-verifying blocking-cURL fallback for CLI/no-coroutine
 * contexts and for https-under-the-Swoole-loop ({@see \Phlix\Common\Http\EventLoopTls})
 * — now lives ONCE in {@see OAuth2HttpClient}. This subclass exists only so the
 * OIDC classes can keep their `OidcHttpClient` DI type (S48 review r1, Finding 6:
 * the two files were byte-identical apart from docblocks, and a future timeout /
 * cURL-option fix would otherwise land in only one of them).
 *
 * Not `final`: the concrete class is injected into the OIDC classes so tests can
 * substitute a mock and exercise the flow with no real network.
 *
 * @package Phlix\Plugins\Oidc
 * @since 0.99.0
 */
class OidcHttpClient extends OAuth2HttpClient
{
}
