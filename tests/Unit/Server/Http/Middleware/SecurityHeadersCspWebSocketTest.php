<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Consolidated review C1: the watch-party WebSocket on :8097 is cross-PORT
 * relative to the SPA's HTTP origin, and CSP `'self'` matches
 * scheme+host+port — so the server's own portal silently refused every
 * SyncPlay socket. The cure: the SPA shell may pass a validated request host
 * and `connect-src` grows `ws://<host> wss://<host>` (portless ON PURPOSE —
 * the HTTP worker cannot see the WS worker's env-resolved port). These tests
 * pin the law from both ends: the allowance exists when a host is given, and
 * the historical bare string is byte-identical when it is not (the default
 * `decorate()` path and every Host-less construction must not shift).
 */
final class SecurityHeadersCspWebSocketTest extends TestCase
{
    public function test_bare_call_emits_the_historical_connect_src_verbatim(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();

        self::assertStringContainsString("connect-src 'self';", $csp);
        self::assertStringNotContainsString('ws://', $csp);
        self::assertStringNotContainsString('wss://', $csp);
    }

    public function test_null_and_empty_web_socket_hosts_are_byte_identical_to_bare(): void
    {
        self::assertSame(
            SecurityHeaders::contentSecurityPolicy(),
            SecurityHeaders::contentSecurityPolicy(null, null),
            'a NULL host must not move the string'
        );
        self::assertSame(
            SecurityHeaders::contentSecurityPolicy(),
            SecurityHeaders::contentSecurityPolicy(null, ''),
            'an empty host must not move the string'
        );
    }

    public function test_validated_host_grows_portless_ws_and_wss_sources(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy(null, 'phlix.example.com');

        self::assertStringContainsString(
            "connect-src 'self' ws://phlix.example.com wss://phlix.example.com;",
            $csp,
            'the allowance must be portless (any port on the validated host) '
            . 'and terminate the directive exactly like before'
        );
    }

    public function test_nonce_and_web_socket_host_compose(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy('NONCE123', 'tv.lan');

        self::assertStringContainsString("script-src 'self' 'nonce-NONCE123';", $csp);
        self::assertStringContainsString("connect-src 'self' ws://tv.lan wss://tv.lan;", $csp);
    }

    /**
     * @return array<string,array{0:?string,1:?string}> header => expected host
     */
    public static function provide_host_headers(): array
    {
        return [
            'plain hostname' => ['phlix.local', 'phlix.local'],
            'port stripped' => ['phlix.local:8096', 'phlix.local'],
            'case folded' => ['Phlix.LOCAL', 'phlix.local'],
            'surrounding space' => [" \tphlix.local \t", 'phlix.local'],
            'ipv4' => ['192.168.1.50', '192.168.1.50'],
            'ipv6 bracketed' => ['[::1]', '[::1]'],
            'ipv6 bracketed with port' => ['[2001:db8::1]:8097', '[2001:db8::1]'],
            'null header' => [null, null],
            'empty header' => ['', null],
            'scheme smuggling attempt' => ['evil.example.com/#', null],
            'path injection' => ['ok.example.com/ x', null],
            'underscore host (not a legal DNS label start/end)' => ['a_b.example.com', null],
            'wildcard/blank Host' => ['*', null],
            'long-enough to reject' => [str_repeat('a', 250) . '.com', null],
            'crlf in header value' => ["a\r\nb.example.com", null],
            'bare port' => [':8097', null],
        ];
    }

    /**
     * @dataProvider provide_host_headers
     */
    public function test_host_header_sanitizer(?string $header, ?string $expected): void
    {
        self::assertSame($expected, SecurityHeaders::webSocketHostFromHeader($header));
    }

    public function test_sanitized_rejects_never_widen_the_policy(): void
    {
        // The composition law: any host that fails the sanitizer flows into
        // the builder as NULL and the response ships the bare historical CSP.
        $rejected = SecurityHeaders::webSocketHostFromHeader('bad host/');
        self::assertNull($rejected);
        self::assertSame(
            SecurityHeaders::contentSecurityPolicy(),
            SecurityHeaders::contentSecurityPolicy(null, $rejected)
        );
    }
}
