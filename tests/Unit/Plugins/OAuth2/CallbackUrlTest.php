<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use Phlix\Plugins\OAuth2\CallbackUrl;
use PHPUnit\Framework\TestCase;

/**
 * S48 review r1 Finding 1 (HIGH) — the OAuth2/OIDC `redirect_uri` must be an
 * ABSOLUTE URL; a path-only value can never match a provider's registered
 * callback and kills the flow with `redirect_uri_mismatch`.
 *
 * @covers \Phlix\Plugins\OAuth2\CallbackUrl
 */
final class CallbackUrlTest extends TestCase
{
    /**
     * @dataProvider absoluteUrls
     */
    public function test_accepts_absolute_http_urls(string $url): void
    {
        $this->assertTrue(CallbackUrl::isAbsolute($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function absoluteUrls(): array
    {
        return [
            'https' => ['https://phlix.example/auth/github/callback'],
            'https with port' => ['https://phlix.example:8443/auth/github/callback'],
            'http localhost' => ['http://localhost:8096/auth/oidc/callback'],
            'ipv4' => ['https://10.0.0.5/auth/github/callback'],
        ];
    }

    /**
     * @dataProvider rejectedUrls
     */
    public function test_rejects_non_absolute_or_unsafe_values(string $url): void
    {
        $this->assertFalse(CallbackUrl::isAbsolute($url));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'empty' => [''],
            'relative path' => ['/auth/github/callback'],
            'protocol relative' => ['//phlix.example/auth/github/callback'],
            'no host' => ['https:///auth/github/callback'],
            'userinfo' => ['https://user@evil.example/auth/github/callback'],
            'javascript' => ['javascript:alert(1)'],
            'crlf' => ["https://phlix.example/cb\r\nX: 1"],
            'space' => ['https://phlix example/cb'],
        ];
    }

    public function test_configured_absolute_value_wins_over_the_request_host(): void
    {
        $this->assertSame(
            'https://configured.example/auth/github/callback',
            CallbackUrl::resolve(
                'https://configured.example/auth/github/callback',
                'request-host.example',
                null,
                '/auth/github/callback',
                '',
            ),
        );
    }

    public function test_relative_configured_value_falls_through_to_the_request_host(): void
    {
        $this->assertSame(
            'https://request-host.example/auth/github/callback',
            CallbackUrl::resolve(
                '/auth/github/callback',
                'request-host.example',
                null,
                '/auth/github/callback',
                'request-host.example',
            ),
        );
    }

    public function test_host_with_port_is_preserved(): void
    {
        // The allowlist carries the port too (review r3 finding 3), so the operator
        // configured `phlix.example:8443` here.
        $this->assertSame(
            'https://phlix.example:8443/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example:8443', null, '/auth/oidc/callback', 'phlix.example:8443'),
        );
    }

    public function test_forwarded_proto_selects_the_scheme(): void
    {
        $this->assertSame(
            'http://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'http', '/auth/oidc/callback', 'phlix.example'),
        );
        // A proxy chain may append hops — the first one wins.
        $this->assertSame(
            'https://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'https, http', '/auth/oidc/callback', 'phlix.example'),
        );
        // Garbage is ignored (defaults to https).
        $this->assertSame(
            'https://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'gopher', '/auth/oidc/callback', 'phlix.example'),
        );
    }

    /**
     * An allowlist IS configured here, so these assert the HOST-SHAPE rules (and
     * not merely the fail-closed rule the empty-allowlist tests below cover).
     *
     * @dataProvider unusableHosts
     */
    public function test_returns_null_when_no_absolute_url_can_be_built(?string $host): void
    {
        $this->assertNull(CallbackUrl::resolve('', $host, null, '/auth/github/callback', 'phlix.example'));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableHosts(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'header injection' => ["phlix.example\r\nX-Injected: 1"],
            'path in host' => ['phlix.example/evil'],
            'bad port' => ['phlix.example:notaport'],
            'out of range port' => ['phlix.example:99999'],
        ];
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-1 (MED) — the Host-derived form needs a host ALLOWLIST.
    //
    // A wildcard-registered OIDC IdP (Keycloak et al) WILL deliver a victim's
    // `code` to an attacker-supplied Host, and the browser-binding correlation
    // cookie cannot defend it (the attacker runs the authorize leg). So a forged
    // Host must never become a redirect_uri.
    // -----------------------------------------------------------------------

    /**
     * @dataProvider mismatchedHosts
     */
    public function test_a_host_that_is_not_the_configured_domain_derives_nothing(string $host): void
    {
        $this->assertNull(
            CallbackUrl::resolve('', $host, null, '/auth/oidc/callback', 'phlix.example'),
            'a Host outside the configured domain must NOT produce a derived redirect_uri',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mismatchedHosts(): array
    {
        return [
            'foreign host' => ['evil.example'],
            'foreign host with port' => ['evil.example:8443'],
            'sub-domain of the attacker' => ['phlix.example.evil.example'],
            'sub-domain of the real domain' => ['sso.phlix.example'],
            'suffix trick' => ['notphlix.example'],
            'ipv4 literal' => ['10.0.0.5'],
        ];
    }

    /**
     * @dataProvider matchingHosts
     */
    public function test_the_configured_domain_still_derives(string $host, string $expected): void
    {
        $this->assertSame(
            $expected,
            CallbackUrl::resolve('', $host, null, '/auth/oidc/callback', 'phlix.example'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function matchingHosts(): array
    {
        return [
            'exact' => ['phlix.example', 'https://phlix.example/auth/oidc/callback'],
            'case insensitive' => ['PHLIX.Example', 'https://PHLIX.Example/auth/oidc/callback'],
            // The DEFAULT port for the scheme is not a different origin — it is
            // normalised away rather than leaking into the derived URL (the provider
            // has the default-port form registered).
            'default https port' => ['phlix.example:443', 'https://phlix.example/auth/oidc/callback'],
        ];
    }

    // -----------------------------------------------------------------------
    // Review r3 finding 3 (LOW) — the allowlist pins the whole ORIGIN, port
    // included. Before this, `PHLIX_DOMAIN=media.example.com` + `Host:
    // media.example.com:1337` derived
    // `redirect_uri=https://media.example.com:1337/auth/github/callback`.
    // -----------------------------------------------------------------------

    /**
     * @dataProvider portPinning
     */
    public function test_the_port_is_part_of_the_allowlisted_origin(
        string $host,
        string $allowed,
        ?string $expected,
    ): void {
        $this->assertSame(
            $expected,
            CallbackUrl::resolve('', $host, null, '/auth/github/callback', $allowed),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function portPinning(): array
    {
        return [
            'unported domain refuses a ported Host' => [
                'media.example.com:1337',
                'media.example.com',
                null,
            ],
            'unported domain accepts the bare Host' => [
                'media.example.com',
                'media.example.com',
                'https://media.example.com/auth/github/callback',
            ],
            'unported domain tolerates the default https port' => [
                'media.example.com:443',
                'media.example.com',
                'https://media.example.com/auth/github/callback',
            ],
            'ported domain accepts that exact port' => [
                'media.example.com:8443',
                'media.example.com:8443',
                'https://media.example.com:8443/auth/github/callback',
            ],
            'ported domain refuses another port' => [
                'media.example.com:1337',
                'media.example.com:8443',
                null,
            ],
            'ported domain refuses a bare Host' => [
                'media.example.com',
                'media.example.com:8443',
                null,
            ],
        ];
    }

    public function test_the_default_http_port_is_normalised_under_http(): void
    {
        $this->assertSame(
            'http://media.example.com/auth/github/callback',
            CallbackUrl::resolve('', 'media.example.com:80', 'http', '/auth/github/callback', 'media.example.com'),
        );
        // …but :80 is NOT the default under https, so it is a distinct origin.
        $this->assertNull(
            CallbackUrl::resolve('', 'media.example.com:80', 'https', '/auth/github/callback', 'media.example.com'),
        );
    }

    // -----------------------------------------------------------------------
    // S48 TestEngineer — ADVERSARIAL default-port matrix.
    //
    // `stripDefaultPort()` exists so a client that DOES send the scheme's default
    // port in `Host` (RFC 9110 permits it, and several HTTP libraries and some
    // proxies do it) is still accepted. It must strip the default for the RESOLVED
    // scheme and NOTHING else: :443 is only default under https, :80 only under
    // http. Getting the cross pairs wrong would either 503 a legitimate client or
    // silently accept a genuinely different origin.
    // -----------------------------------------------------------------------

    /**
     * @dataProvider defaultPortMatrix
     */
    public function test_a_default_port_is_default_only_for_its_own_scheme(
        string $host,
        string $forwardedProto,
        string $allowed,
        ?string $expected,
    ): void {
        $this->assertSame(
            $expected,
            CallbackUrl::resolve('', $host, $forwardedProto, '/auth/github/callback', $allowed),
        );
    }

    /**
     * All four scheme/port pairs, in BOTH directions (default port on the request
     * side and on the configured side).
     *
     * The ruling behind each row: a URL's origin is (scheme, host, port) with the
     * scheme's default port elided. So `https://h:443` ≡ `https://h`, and
     * `http://h:80` ≡ `http://h` — but `https://h:80` and `http://h:443` are
     * DISTINCT origins that must not match an unported allowlist, because the
     * `redirect_uri` an operator registered with the provider is the elided form
     * and a provider compares it literally.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function defaultPortMatrix(): array
    {
        return [
            // --- default port on the REQUEST side ---
            ':443 over https is the same origin (ACCEPT)' => [
                'media.example.com:443',
                'https',
                'media.example.com',
                'https://media.example.com/auth/github/callback',
            ],
            ':80 over http is the same origin (ACCEPT)' => [
                'media.example.com:80',
                'http',
                'media.example.com',
                'http://media.example.com/auth/github/callback',
            ],
            ':80 over https is a DIFFERENT origin (REFUSE)' => [
                'media.example.com:80',
                'https',
                'media.example.com',
                null,
            ],
            ':443 over http is a DIFFERENT origin (REFUSE)' => [
                'media.example.com:443',
                'http',
                'media.example.com',
                null,
            ],
            // --- default port on the CONFIGURED (PHLIX_DOMAIN) side ---
            'PHLIX_DOMAIN with :443 accepts a bare Host over https' => [
                'media.example.com',
                'https',
                'media.example.com:443',
                'https://media.example.com/auth/github/callback',
            ],
            'PHLIX_DOMAIN with :80 accepts a bare Host over http' => [
                'media.example.com',
                'http',
                'media.example.com:80',
                'http://media.example.com/auth/github/callback',
            ],
            'PHLIX_DOMAIN with :443 refuses a bare Host over http' => [
                'media.example.com',
                'http',
                'media.example.com:443',
                null,
            ],
            'PHLIX_DOMAIN with :80 refuses a bare Host over https' => [
                'media.example.com',
                'https',
                'media.example.com:80',
                null,
            ],
            // --- both sides carry the SAME default port ---
            'both sides :443 over https (ACCEPT)' => [
                'media.example.com:443',
                'https',
                'media.example.com:443',
                'https://media.example.com/auth/github/callback',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // S48 TestEngineer — PRODUCTION REALITY CHECK.
    //
    // The one live install is `PHLIX_DOMAIN=intertainer.phlix.interserver.net`
    // (portless) with phlix-server on 127.0.0.1:8096 behind HAProxy. Verified
    // read-only against `/etc/haproxy/haproxy.cfg` on that box: `frontend fe_https`
    // binds :443, sets `X-Forwarded-Proto: https`, routes on
    // `acl is_phlix_server_host hdr(host) -i intertainer.phlix.interserver.net`
    // (an EXACT match) to `backend be_phlix_server -> server phlix 127.0.0.1:8096`,
    // and there is NO Host rewriting anywhere in the config. The backend `server`
    // line is a TCP target, not a Host override, so the `Host` the app sees is the
    // one the browser sent — and HAProxy's exact-match ACL means only the portless
    // authority (or its `:443` equivalent, which HAProxy normalises) is ever routed
    // to phlix-server at all.
    //
    // These assertions pin that: the deployed configuration must keep deriving the
    // `redirect_uri` an operator registers with GitHub/the IdP.
    // -----------------------------------------------------------------------

    public function test_the_production_haproxy_authority_still_derives_the_registered_callback(): void
    {
        $domain = 'intertainer.phlix.interserver.net';
        $expected = 'https://' . $domain . '/auth/github/callback';

        // What a browser sends through HAProxy: portless Host + X-Forwarded-Proto.
        $this->assertSame(
            $expected,
            CallbackUrl::resolve('', $domain, 'https', '/auth/github/callback', $domain),
            'the live deployment must keep deriving its registered callback URL',
        );
        // A client that includes the default port (and HAProxy forwards it) must be
        // accepted too, and must still derive the PORTLESS registered form.
        $this->assertSame(
            $expected,
            CallbackUrl::resolve('', $domain . ':443', 'https', '/auth/github/callback', $domain),
            'a Host carrying the default https port must not 503 the login',
        );
        // Host case is not significant.
        $this->assertSame(
            'https://Intertainer.Phlix.Interserver.NET/auth/github/callback',
            CallbackUrl::resolve('', 'Intertainer.Phlix.Interserver.NET', 'https', '/auth/github/callback', $domain),
        );
    }

    /**
     * The one BEHAVIOUR DELTA of the port pinning on the live box, asserted so it is
     * a documented decision rather than a surprise: phlix-server also listens
     * directly on `0.0.0.0:8096`, so a client that BYPASSES HAProxy sends
     * `Host: intertainer.phlix.interserver.net:8096`. That now 503s instead of
     * deriving `https://intertainer.phlix.interserver.net:8096/auth/github/callback`.
     *
     * Nothing is lost: the derived-with-port value could never have completed the
     * flow, because the callback registered with GitHub / the IdP is the :443 form,
     * so the provider answered `redirect_uri_mismatch`. The failure just moves
     * earlier and says what to fix (and the `redirect_uri` setting remains the
     * escape hatch for an operator who really does serve auth on :8096).
     */
    public function test_reaching_the_app_directly_on_its_listen_port_now_fails_closed(): void
    {
        $domain = 'intertainer.phlix.interserver.net';

        $this->assertNull(
            CallbackUrl::resolve('', $domain . ':8096', null, '/auth/github/callback', $domain),
            'the app listen port is a different origin from the registered :443 one',
        );
        $this->assertNull(
            CallbackUrl::resolve('', $domain . ':8096', 'http', '/auth/github/callback', $domain),
        );
        // The escape hatch still covers that operator.
        $this->assertSame(
            'https://' . $domain . ':8096/auth/github/callback',
            CallbackUrl::resolve(
                'https://' . $domain . ':8096/auth/github/callback',
                $domain . ':8096',
                null,
                '/auth/github/callback',
                $domain,
            ),
        );
    }

    /**
     * The allowlist must NOT break the explicit configuration path: an operator
     * whose provider setting names another host keeps working (that value is the
     * one registered with the provider).
     */
    public function test_the_allowlist_does_not_affect_a_configured_absolute_value(): void
    {
        $this->assertSame(
            'https://sso.example.org/auth/oidc/callback',
            CallbackUrl::resolve(
                'https://sso.example.org/auth/oidc/callback',
                'evil.example',
                null,
                '/auth/oidc/callback',
                'phlix.example',
            ),
        );
    }

    // -----------------------------------------------------------------------
    // Review r3 — FAIL CLOSED with no configured domain.
    //
    // r2's first cut treated an unset PHLIX_DOMAIN as "no allowlist" and kept
    // deriving from any Host, which left the NEW-1 hole fully open on every box
    // that never ran `install.sh --domain`. No configured hostname now means NO
    // derivation at all; the caller answers an actionable 503. This regresses no
    // working deployment: the pre-S48 relative redirect_uri already made these
    // flows fail against every real provider (r1 finding 1), so there is no
    // population of working end-to-end logins to break.
    // -----------------------------------------------------------------------

    /**
     * @dataProvider anyValidHost
     */
    public function test_no_configured_domain_derives_nothing(string $host): void
    {
        $this->assertNull(
            CallbackUrl::resolve('', $host, null, '/auth/oidc/callback', ''),
            'with no configured public hostname NOTHING may be derived from a client-supplied Host',
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function anyValidHost(): array
    {
        return [
            'plain dns name' => ['whatever.example'],
            'with port' => ['whatever.example:8443'],
            'localhost' => ['localhost:8096'],
            'ipv4 literal' => ['10.0.0.5'],
            'attacker host' => ['evil.example'],
        ];
    }

    /**
     * …and the escape hatch: the EXPLICIT `redirect_uri` setting keeps first
     * priority and still works with PHLIX_DOMAIN unset, so an operator is never
     * locked out — that is what makes fail-closed affordable.
     */
    public function test_a_configured_absolute_value_still_works_without_a_configured_domain(): void
    {
        $this->assertSame(
            'https://media.example.org:8443/auth/oidc/callback',
            CallbackUrl::resolve(
                'https://media.example.org:8443/auth/oidc/callback',
                'whatever.example',
                null,
                '/auth/oidc/callback',
                '',
            ),
        );
    }

    /**
     * `configuredHost()` reads PHLIX_DOMAIN (the env `config/hub.php` derives
     * `hub.domain` from) and treats a missing/garbage value as "no hostname
     * configured" — which now means no derivation at all, never an allowlist that
     * can never match.
     */
    public function test_configured_host_reads_phlix_domain(): void
    {
        $original = getenv('PHLIX_DOMAIN');
        try {
            putenv('PHLIX_DOMAIN=Media.Example.ORG');
            $this->assertSame('media.example.org', CallbackUrl::configuredHost());

            // r3 finding 3 — a configured port is KEPT: it is part of the origin the
            // allowlist pins.
            putenv('PHLIX_DOMAIN=media.example.org:8443');
            $this->assertSame('media.example.org:8443', CallbackUrl::configuredHost());

            putenv('PHLIX_DOMAIN=  ');
            $this->assertSame('', CallbackUrl::configuredHost());

            putenv('PHLIX_DOMAIN=https://media.example.org/');
            $this->assertSame('', CallbackUrl::configuredHost(), 'garbage = nothing may be derived');

            putenv('PHLIX_DOMAIN');
            $this->assertSame('', CallbackUrl::configuredHost());
        } finally {
            if (is_string($original) && $original !== '') {
                putenv('PHLIX_DOMAIN=' . $original);
            } else {
                putenv('PHLIX_DOMAIN');
            }
        }
    }

    // -----------------------------------------------------------------------
    // Review r2 NEW-8 — the state-carried callback_url is re-validated on replay.
    // -----------------------------------------------------------------------

    public function test_replay_requires_an_absolute_url(): void
    {
        $this->assertFalse(CallbackUrl::isReplayable('/auth/oidc/callback', '', ''));
        $this->assertFalse(CallbackUrl::isReplayable('', '', ''));
        $this->assertFalse(CallbackUrl::isReplayable("https://phlix.example/cb\r\nX: 1", '', ''));
    }

    public function test_replay_honours_the_host_allowlist(): void
    {
        $this->assertTrue(
            CallbackUrl::isReplayable('https://phlix.example/auth/oidc/callback', '', 'phlix.example'),
        );
        $this->assertFalse(
            CallbackUrl::isReplayable('https://evil.example/auth/oidc/callback', '', 'phlix.example'),
        );
        // The operator-configured value is always replayable, whatever its host.
        $this->assertTrue(CallbackUrl::isReplayable(
            'https://sso.example.org/auth/oidc/callback',
            'https://sso.example.org/auth/oidc/callback',
            'phlix.example',
        ));
    }

    /**
     * Review r3 — the replay path fails closed too: with no configured hostname the
     * ONLY replayable value is the configured setting, mirroring what resolve() can
     * produce. (An in-flight state row carrying a derived URL therefore falls back
     * to a fresh resolve → the actionable 503, for at most one 600 s TTL.)
     */
    public function test_replay_without_a_configured_domain_accepts_only_the_configured_value(): void
    {
        $this->assertFalse(
            CallbackUrl::isReplayable('https://anything.example/cb', '', ''),
            'an absolute-but-unvouched-for URL must not be replayed as redirect_uri',
        );
        $this->assertTrue(CallbackUrl::isReplayable(
            'https://media.example.org/auth/oidc/callback',
            'https://media.example.org/auth/oidc/callback',
            '',
        ));
    }

    /**
     * Review r3 finding 3, replay leg — the state-carried callback URL is checked
     * against the whole allowlisted ORIGIN, so a URL on another port of the
     * operator's hostname is no longer replayable as the token-exchange
     * `redirect_uri`.
     */
    public function test_replay_pins_the_port_too(): void
    {
        $this->assertTrue(CallbackUrl::isReplayable(
            'https://phlix.example:8443/auth/oidc/callback',
            '',
            'phlix.example:8443',
        ));
        $this->assertFalse(
            CallbackUrl::isReplayable('https://phlix.example:1337/auth/oidc/callback', '', 'phlix.example'),
            'another port on the operator hostname is a different origin',
        );
        $this->assertFalse(
            CallbackUrl::isReplayable('https://phlix.example/auth/oidc/callback', '', 'phlix.example:8443'),
            'a ported allowlist must not accept the default-port origin',
        );
        // The default port for the scheme is the same origin.
        $this->assertTrue(
            CallbackUrl::isReplayable('https://phlix.example:443/auth/oidc/callback', '', 'phlix.example'),
        );
    }

    /**
     * Review r3 finding 4 — an IPv6-literal `PHLIX_DOMAIN` must not silently disable
     * the NEW-8 replay re-validation. `Host`/`PHLIX_DOMAIN` carry the brackets and
     * the port in ONE string while `parse_url()` splits them, so both sides are
     * normalised (brackets stripped, default port dropped) before comparing.
     */
    public function test_an_ipv6_literal_domain_derives_and_replays(): void
    {
        $derived = CallbackUrl::resolve('', '[::1]:8096', null, '/auth/oidc/callback', '[::1]:8096');
        $this->assertSame('https://[::1]:8096/auth/oidc/callback', $derived);
        $this->assertIsString($derived);
        $this->assertTrue(
            CallbackUrl::isReplayable($derived, '', '[::1]:8096'),
            'the replay check must be live for an IPv6 literal, not dead-and-fail-safe',
        );
        // Unported IPv6 literal, both legs.
        $this->assertSame(
            'https://[::1]/auth/oidc/callback',
            CallbackUrl::resolve('', '[::1]', null, '/auth/oidc/callback', '[::1]'),
        );
        $this->assertTrue(CallbackUrl::isReplayable('https://[::1]/auth/oidc/callback', '', '[::1]'));
        // …and the port/host are still pinned for IPv6.
        $this->assertNull(CallbackUrl::resolve('', '[::1]:9999', null, '/auth/oidc/callback', '[::1]:8096'));
        $this->assertFalse(
            CallbackUrl::isReplayable('https://[::1]:9999/auth/oidc/callback', '', '[::1]:8096'),
        );
        $this->assertFalse(
            CallbackUrl::isReplayable('https://[2001:db8::1]/auth/oidc/callback', '', '[::1]'),
        );
    }

    /**
     * S48 TestEngineer — THE UPGRADE WINDOW, stated explicitly.
     *
     * `isReplayable()` got stricter in fix r4 (authority, not bare host). A state
     * row that was minted under the OLD bare-host rule can therefore carry a
     * `callback_url` this build no longer accepts — e.g. a login that started on
     * `https://phlix.example:8096/…` seconds before the restart while
     * `PHLIX_DOMAIN=phlix.example`.
     *
     * What actually happens to that in-flight login is asserted here, because "it
     * fails safe" is a claim, not an observation:
     *
     *  - `isReplayable()` returns FALSE, so `replayCallbackUrl()` yields null;
     *  - the controller falls back to a FRESH `resolve()`, which for the same
     *    ported `Host` also returns null → `503 callback_url_not_configured`;
     *  - the state was already consumed one-shot, so the user simply retries and
     *    the retry (through HAProxy, portless) succeeds.
     *
     * The blast radius is one bounded window: the ≤600 s state TTL, only for a
     * login started on a non-registered origin, only across the deploy itself.
     * A row minted on the CANONICAL origin — every login that could actually have
     * completed — is still replayable, which is the assertion that matters.
     */
    public function test_a_state_row_minted_under_the_old_bare_host_rule_is_no_longer_replayable(): void
    {
        // Legacy row from the pre-r4 bare-host rule: a port that PHLIX_DOMAIN does
        // not name. Accepted before, refused now.
        $this->assertFalse(
            CallbackUrl::isReplayable('https://phlix.example:8096/auth/github/callback', '', 'phlix.example'),
            'a legacy ported callback_url must not be replayed as the token-exchange redirect_uri',
        );
        // …and the fresh-resolve fallback for that same request also refuses, so
        // the caller answers the actionable 503 rather than sending a value the
        // provider would reject.
        $this->assertNull(
            CallbackUrl::resolve('', 'phlix.example:8096', null, '/auth/github/callback', 'phlix.example'),
        );

        // The rows that matter — minted on the canonical origin — are unaffected by
        // the upgrade, so no completable login is broken.
        $this->assertTrue(
            CallbackUrl::isReplayable('https://phlix.example/auth/github/callback', '', 'phlix.example'),
            'an in-flight login on the configured origin must survive the upgrade',
        );
        $this->assertTrue(
            CallbackUrl::isReplayable('https://phlix.example:443/auth/github/callback', '', 'phlix.example'),
            'nor may an explicit default port break one',
        );
        // A legacy row whose URL is byte-identical to the configured `redirect_uri`
        // setting also still replays, on the first branch.
        $this->assertTrue(CallbackUrl::isReplayable(
            'https://sso.example.org:8443/auth/github/callback',
            'https://sso.example.org:8443/auth/github/callback',
            'phlix.example',
        ));
    }

    /**
     * The refusal is LOGGED with the presented Host, which is attacker-controlled, so
     * the field is reduced to printable ASCII and bounded. This is log HYGIENE, not
     * a log-forging fix: Monolog's default `LineFormatter` already JSON-escapes
     * context values (review r3, finding 2) — the helper just refuses to depend on
     * that.
     */
    public function test_host_is_sanitised_before_it_reaches_a_log_line(): void
    {
        $this->assertSame('(none)', CallbackUrl::sanitizeHostForLog(null));
        $this->assertSame('(none)', CallbackUrl::sanitizeHostForLog(''));
        $this->assertSame('(none)', CallbackUrl::sanitizeHostForLog("\r\n\t"));
        $this->assertSame('(unprintable)', CallbackUrl::sanitizeHostForLog("\x01\x7F"));
        $this->assertSame('evil.example', CallbackUrl::sanitizeHostForLog('evil.example'));
        $this->assertSame(
            'evil.exampleINJECTED:forged',
            CallbackUrl::sanitizeHostForLog("evil.example\r\nINJECTED: forged"),
        );
        $this->assertSame(128, strlen(CallbackUrl::sanitizeHostForLog(str_repeat('a', 500))));
    }

    /**
     * Review r3 finding 2, coverage half — the client-supplied `sid` (read out of the
     * base64-JSON `state` with no shape validation) is the same class of input as the
     * `Host` header and now goes through the same helper, so a log field stays one
     * short readable token whatever the client sent.
     */
    public function test_any_attacker_supplied_log_field_is_bounded(): void
    {
        $this->assertSame('(none)', CallbackUrl::sanitizeForLog(null));
        $this->assertSame('(none)', CallbackUrl::sanitizeForLog(" \t "));
        $this->assertSame('(unprintable)', CallbackUrl::sanitizeForLog("\x00\x1F\x7F"));
        $this->assertSame('sid-abc123', CallbackUrl::sanitizeForLog('sid-abc123'));
        $this->assertSame(
            'sidINJECTED:forged',
            CallbackUrl::sanitizeForLog("sid\r\nINJECTED: forged"),
        );
        $this->assertSame(128, strlen(CallbackUrl::sanitizeForLog(str_repeat('s', 4096))));
        // Every byte >= 0x80 goes too, so the 128-byte cap can never split a
        // multi-byte character.
        $this->assertSame('ac', CallbackUrl::sanitizeForLog("a\u{00E9}c"));
        // sanitizeHostForLog() is a thin alias of the same helper.
        $this->assertSame(
            CallbackUrl::sanitizeForLog("evil.example\r\nX: 1"),
            CallbackUrl::sanitizeHostForLog("evil.example\r\nX: 1"),
        );
    }
}
