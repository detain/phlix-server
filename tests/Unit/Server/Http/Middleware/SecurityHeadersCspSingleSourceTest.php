<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * S01 AC-audit residual — the DEFAULT emitter of the CSP header.
 *
 * S01 allowlisted `https://image.tmdb.org` / `https://tmdb.org` in `img-src` and
 * justified itself on a single-source claim: *"This single source of truth also
 * feeds the SPA shell CSP (SharedUiController), so one change covers both
 * entrypoints."* The acceptance criterion is that the new hosts are present in the
 * **emitted** header.
 *
 * Only one of the two emitters was pinned. `SharedUiControllerCspNonceTest` covers
 * `SharedUiController` (`/app` shell) and the
 * {@see SecurityHeaders::contentSecurityPolicy()} builder itself. Nothing covered
 * {@see SecurityHeaders::decorate()} — the middleware `HttpHandler` applies to every
 * other response (`HttpHandler.php:142` and the rate-limit reply at `:320`).
 * Replacing the builder call inside `decorate()` with a hardcoded
 * `"default-src 'self'; img-src 'self'"` left the whole Unit suite (8,465 tests)
 * GREEN, so the default response path could silently stop shipping the allowlist —
 * and, more generally, drift away from the one policy the codebase claims to have.
 *
 * These tests assert the emitted header, not the builder, so they fail on that
 * mutation instead of merely re-testing the string `contentSecurityPolicy()` returns.
 *
 * @covers \Phlix\Server\Http\Middleware\SecurityHeaders
 */
final class SecurityHeadersCspSingleSourceTest extends TestCase
{
    /**
     * The header `decorate()` puts on an ordinary response must BE the shared
     * policy — not a second, drifting copy of it.
     */
    public function testDecorateEmitsExactlyTheSharedPolicy(): void
    {
        $response = (new SecurityHeaders())->decorate(new Response());

        $this->assertArrayHasKey('Content-Security-Policy', $response->headers);
        $this->assertSame(
            SecurityHeaders::contentSecurityPolicy(),
            $response->headers['Content-Security-Policy'],
            'decorate() must emit SecurityHeaders::contentSecurityPolicy() verbatim — '
            . 'a second hardcoded policy here is how the img-src allowlist drifts off '
            . 'the default response path while the SPA shell keeps it',
        );
    }

    /**
     * S01's actual acceptance criterion, asserted on the wire value rather than on
     * the builder: both TMDB hosts present, no wildcard anywhere in the directive.
     */
    public function testTheEmittedImgSrcCarriesBothTmdbHostsAndNoWildcard(): void
    {
        $response = (new SecurityHeaders())->decorate(new Response());
        $csp = (string) ($response->headers['Content-Security-Policy'] ?? '');

        $this->assertSame(1, preg_match('/img-src ([^;]+);/', $csp, $m), 'No terminated img-src directive');
        $sources = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];

        $this->assertContains('https://image.tmdb.org', $sources);
        $this->assertContains('https://tmdb.org', $sources);

        foreach ($sources as $source) {
            $this->assertStringNotContainsString('*', $source, 'S01 forbids a wildcard in img-src');
        }
        $this->assertNotContains('https:', $sources, 'img-src must not open the whole https: scheme');
    }

    /**
     * `decorate()` must not clobber a CSP the caller already set — this is what
     * lets `SharedUiController` serve its per-request `'nonce-…'` variant. Without
     * the guard the nonce would be overwritten and the SPA's inline bootstrap
     * `<script>` would be blocked.
     */
    public function testDecorateDoesNotOverwriteACallerSuppliedPolicy(): void
    {
        $response = new Response();
        $response->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-abc'");

        (new SecurityHeaders())->decorate($response);

        $this->assertSame(
            "default-src 'self'; script-src 'self' 'nonce-abc'",
            $response->headers['Content-Security-Policy'],
        );
    }

    /**
     * S01's third acceptance bullet: *"leave a `// TODO` pointing at #47"*, so the
     * stopgap is removable when S71-S73 proxy remote artwork through our own origin.
     *
     * Deliberately COUPLED to the thing it marks rather than being a bare
     * comment-existence check: it only demands the marker while the hosts are still
     * allowlisted, so it retires itself the moment the stopgap is removed and cannot
     * become a rule that outlives its reason.
     */
    public function testTheStopgapAllowlistCarriesItsRemovalMarker(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(SecurityHeaders::class))->getFileName(),
        );

        if (!str_contains($source, 'https://image.tmdb.org')) {
            $this->markTestSkipped('TMDB hosts no longer allowlisted — the stopgap marker is not needed.');
        }

        $this->assertMatchesRegularExpression(
            '/TODO\(updates\.md #47 \/ S71-S73\)/',
            $source,
            'While the TMDB hosts stay allowlisted, SecurityHeaders must keep the TODO '
            . 'that says when to remove them (S01 acceptance criterion)',
        );
    }
}
