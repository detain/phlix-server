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
            ),
        );
    }

    public function test_relative_configured_value_falls_through_to_the_request_host(): void
    {
        $this->assertSame(
            'https://request-host.example/auth/github/callback',
            CallbackUrl::resolve('/auth/github/callback', 'request-host.example', null, '/auth/github/callback'),
        );
    }

    public function test_host_with_port_is_preserved(): void
    {
        $this->assertSame(
            'https://phlix.example:8443/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example:8443', null, '/auth/oidc/callback'),
        );
    }

    public function test_forwarded_proto_selects_the_scheme(): void
    {
        $this->assertSame(
            'http://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'http', '/auth/oidc/callback'),
        );
        // A proxy chain may append hops — the first one wins.
        $this->assertSame(
            'https://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'https, http', '/auth/oidc/callback'),
        );
        // Garbage is ignored (defaults to https).
        $this->assertSame(
            'https://phlix.example/auth/oidc/callback',
            CallbackUrl::resolve('', 'phlix.example', 'gopher', '/auth/oidc/callback'),
        );
    }

    /**
     * @dataProvider unusableHosts
     */
    public function test_returns_null_when_no_absolute_url_can_be_built(?string $host): void
    {
        $this->assertNull(CallbackUrl::resolve('', $host, null, '/auth/github/callback'));
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
}
