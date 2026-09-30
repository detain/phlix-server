<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * L-7 (security scan) — the "caller wins" guards in SecurityHeaders::decorate()
 * must be CASE-INSENSITIVE.
 *
 * ## The defect this pins shut
 *
 * `Response::header()` stores the field under the exact case it was called with,
 * while HTTP field names are case-insensitive (RFC 9110 SS4.2). Pre-fix the
 * guards were plain `isset($headers['Content-Security-Policy'])` lookups, so a
 * caller that wrote `header('content-security-policy', ...)` was invisible to
 * them and `decorate()` emitted a SECOND header key carrying the default policy.
 * Browsers do not pick a winner between duplicate CSPs — they enforce the
 * UNION of all delivered policies (CSP Level 3 SS8.1), and a server emitting
 * two same-named fields is a splitting hazard on top of that. Either way the
 * caller's deliberate policy stops being the single authority, which is exactly
 * the contract the guard exists to keep (the SPA shell's per-request
 * nonce-CSP rides on it).
 *
 * The fix mirrors `Response::asHeadReply()`'s `strcasecmp` scan. These tests
 * pre-set each guarded field in a non-canonical case and pin that decorate()
 * neither adds nor replaces anything — proving recognition, not just absence of
 * a crash.
 */
final class SecurityHeadersCaseInsensitiveGuardTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function keysMatching(Response $response, string $name): array
    {
        $matches = [];
        foreach (array_keys($response->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                $matches[] = $key;
            }
        }

        return $matches;
    }

    public function testLowercaseCspFromCallerIsRecognizedAndNotDuplicated(): void
    {
        $response = new Response();
        $response->header('content-security-policy', "default-src 'self'; script-src 'self' 'nonce-xyz'");

        (new SecurityHeaders())->decorate($response);

        $this->assertSame(
            ['content-security-policy'],
            $this->keysMatching($response, 'Content-Security-Policy'),
            'L-7: a lowercase caller-set CSP must be recognized by the guard — decorate() '
            . 'must not emit a second Content-Security-Policy key beside it (browsers '
            . 'enforce the union of duplicate policies)',
        );
        $this->assertSame(
            "default-src 'self'; script-src 'self' 'nonce-xyz'",
            $response->headers['content-security-policy'],
        );
    }

    public function testScreamingSnakeFrameOptionsIsRecognizedAndNotDuplicated(): void
    {
        $response = new Response();
        $response->header('X-FRAME-OPTIONS', 'DENY');

        (new SecurityHeaders())->decorate($response);

        $this->assertSame(['X-FRAME-OPTIONS'], $this->keysMatching($response, 'X-Frame-Options'));
        $this->assertSame('DENY', $response->headers['X-FRAME-OPTIONS']);
    }

    public function testLowercaseNosniffAndHstsAreRecognizedAndNotDuplicated(): void
    {
        $response = new Response();
        $response->header('x-content-type-options', 'nosniff');
        $response->header('strict-transport-security', 'max-age=63072000; preload');

        (new SecurityHeaders())->decorate($response);

        $this->assertSame(['x-content-type-options'], $this->keysMatching($response, 'X-Content-Type-Options'));
        $this->assertSame('nosniff', $response->headers['x-content-type-options']);
        $this->assertSame(['strict-transport-security'], $this->keysMatching($response, 'Strict-Transport-Security'));
        $this->assertSame('max-age=63072000; preload', $response->headers['strict-transport-security']);
    }

    /**
     * The complement: with no caller-set fields, decorate() still emits every
     * security header once — the guard loosening must not silence defaults.
     */
    public function testBareResponseStillReceivesTheFullHeaderSet(): void
    {
        $response = (new SecurityHeaders())->decorate(new Response());

        $this->assertSame(['X-Content-Type-Options'], $this->keysMatching($response, 'X-Content-Type-Options'));
        $this->assertSame(['X-Frame-Options'], $this->keysMatching($response, 'X-Frame-Options'));
        $this->assertSame(['Strict-Transport-Security'], $this->keysMatching($response, 'Strict-Transport-Security'));
        $this->assertSame(['Content-Security-Policy'], $this->keysMatching($response, 'Content-Security-Policy'));
    }
}
