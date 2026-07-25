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
        $this->assertSame(
            'https://phlix.example:8443/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example:8443', null, '/auth/oidc/callback', 'phlix.example'),
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
            'with port' => ['phlix.example:8443', 'https://phlix.example:8443/auth/oidc/callback'],
        ];
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

            putenv('PHLIX_DOMAIN=media.example.org:8443');
            $this->assertSame('media.example.org', CallbackUrl::configuredHost());

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
     * The refusal is LOGGED with the presented Host, which is attacker-controlled —
     * so a CR/LF (which would otherwise forge whole log lines) must not survive.
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
}
