<?php

/**
 * Phlix media server component: Deploy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the shipped nginx template's location blocks (M-SERVER-25).
 *
 * The template at reverse-proxy/nginx/phlix.conf shipped a
 * `location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$` block carrying
 * `expires`/`add_header`/`access_log` and NEITHER `proxy_pass` NOR `root`/`alias`/`try_files`,
 * while the server block defined no root anywhere. nginx resolved every match against its
 * compiled-in default html root, so behind install-nginx.sh every hashed SPA chunk
 * (/assets/app/assets/index-<hash>.js, .css, .woff2) and every legacy public/assets static
 * 404ed at the proxy without ever reaching the :8096 worker — the web client booted an HTML
 * shell and then failed on every asset fetch (blank page). `nginx -t` — the only gate
 * install-nginx.sh runs — cannot see this: the block is syntactically valid, it is simply
 * dead. The sibling Caddy template never carried the fiction (blanket reverse_proxy,
 * upstream headers flow through).
 *
 * The cure is deletion: static/asset traffic proxies through `location /` and the caching
 * intent is satisfied UPSTREAM, correctly scoped — HttpHandler::serveStatic() tags only
 * realpath-jailed public/assets/app/** with `Cache-Control: public, max-age=31536000,
 * immutable` (src/Server/Workerman/HttpHandler.php). An edge regex on the broad extension
 * set would instead pin hashless server-generated images to a year of `immutable`, and —
 * being a regex location — would outrank the /media/ and /api/ prefixes forever after.
 *
 * These tests parse the REAL shipped artifact (SystemdReloadSignalTest-style CON-53 pins —
 * nothing here is a fixture that can drift from what deploys) and stay coupled to the
 * upstream authority so the two halves of the story cannot silently diverge again.
 */
final class NginxAssetLocationTest extends TestCase
{
    /** Shipped nginx template — the exact file install-nginx.sh installs. */
    private const TEMPLATE = 'reverse-proxy/nginx/phlix.conf';

    /** Upstream authority whose per-path immutable tag justifies the edge-block deletion. */
    private const UPSTREAM_AUTHORITY = 'src/Server/Workerman/HttpHandler.php';

    /** The exact header value HttpHandler::serveStatic() sets for jailed /assets/app/** files. */
    private const UPSTREAM_CACHE_TAG = "'public, max-age=31536000, immutable'";

    /** Extensions the dead M25 regex captured (kept verbatim so the pin names the real set). */
    private const DEAD_REGEX_EXTENSIONS = 'jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot';

    /**
     * Directives that make a location body actually ANSWER a request. A body with none of
     * these falls through to nginx's compiled-in default html root — files that, in this
     * stack, never exist on the proxy host (the bundle lives in the server's public/ and
     * only the Workerman worker knows it).
     */
    private const ACTIONABLE_DIRECTIVES = [
        'proxy_pass',
        'root ',
        'alias ',
        'try_files',
        'return ',
        'deny ',
        'internal',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $path = self::repoRoot() . '/' . $relative;
        $contents = file_get_contents($path);
        self::assertIsString($contents, "the shipped artifact {$relative} must be readable");

        return $contents;
    }

    /**
     * nginx comments run from # to end of line (this template uses no # inside quoted
     * values — the WS-comment block and the /api/ CORS example both stay inert). Stripping
     * them first is what keeps a commented-out "location /ws" mention from parsing as live
     * configuration.
     */
    private static function stripComments(string $config): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => preg_replace('/#.*$/', '', $line) ?? $line,
            explode("\n", $config)
        ));
    }

    /**
     * Walk the template into [location-argument => body] pairs with a brace-balance scan.
     * Every location in this template is a direct child of a server block; the walker
     * fails loudly on nesting rather than mis-parsing it, so a future restructure cannot
     * quietly hollow the pins out.
     *
     * @return array<string,string>
     */
    private static function parseLocations(string $config): array
    {
        $locations = [];
        $offset = 0;
        while (preg_match('/location\s+([^{;]*)\{/', $config, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $argument = trim($match[1][0]);
            $bodyStart = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            $cursor = $bodyStart;
            $length = strlen($config);
            while ($cursor < $length && $depth > 0) {
                $char = $config[$cursor];
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                } elseif ($char === 'l' && substr($config, $cursor, strlen('location')) === 'location') {
                    self::fail(
                        'the parser assumes flat location blocks (every location here is a direct '
                        . 'child of a server block); nested locations need a real parser before '
                        . 'the shape pins can trust them'
                    );
                }
                $cursor++;
            }
            self::assertSame(0, $depth, 'unbalanced braces in ' . self::TEMPLATE . ' at location "' . $argument . '"');
            $locations[$argument] = substr($config, $bodyStart, $cursor - $bodyStart);
            $offset = $cursor;
        }

        self::assertNotEmpty(
            $locations,
            'the location walker found nothing — neither the template nor the parser should change shape silently'
        );

        return $locations;
    }

    public function testEveryLocationBlockIsActionable(): void
    {
        $config = self::stripComments(self::read(self::TEMPLATE));

        foreach (self::parseLocations($config) as $argument => $body) {
            $isActionable = false;
            foreach (self::ACTIONABLE_DIRECTIVES as $directive) {
                if (str_contains($body, $directive)) {
                    $isActionable = true;
                    break;
                }
            }

            self::assertTrue(
                $isActionable,
                sprintf(
                    'location %s in %s answers nothing: its body carries no proxy_pass, root, alias, '
                    . 'try_files, return, deny or internal, so nginx resolves matches against the '
                    . 'compiled-in default html root — files that never exist on the proxy host in this '
                    . 'stack. This is M-SERVER-25: the shipped asset-extension regex location 404ed every '
                    . 'SPA chunk behind install-nginx.sh while `nginx -t` stayed green, because a dead '
                    . 'location is still valid syntax.',
                    $argument,
                    self::TEMPLATE
                )
            );
        }
    }

    public function testTheAssetExtensionRegexIsNotBackWithoutAnUpstream(): void
    {
        $config = self::stripComments(self::read(self::TEMPLATE));

        foreach (self::parseLocations($config) as $argument => $body) {
            // A case-insensitive regex location whose pattern is an extension list — the
            // M25 shape: `~* \.(jpg|jpeg|...)$`. Legitimate today; it MUST proxy (or root
            // to files the host owns), never sit on expires/add_header alone.
            if (
                !str_starts_with($argument, '~*')
                || !str_contains($argument, '\\.(')
                || !str_ends_with($argument, ')$')
            ) {
                continue;
            }

            self::assertStringContainsString(
                'proxy_pass',
                $body,
                sprintf(
                    'the asset-extension regex location (%s) is back WITHOUT proxy_pass — the exact '
                    . 'M-SERVER-25 defect. Serve them from the upstream (location / already does), or '
                    . 'proxy here explicitly; do NOT repeat the expires-only dead block.',
                    $argument
                )
            );
        }
    }

    public function testTheAssetRegexDoesNotSwallowTheM25ExtensionSet(): void
    {
        $config = self::stripComments(self::read(self::TEMPLATE));

        foreach (array_keys(self::parseLocations($config)) as $argument) {
            self::assertStringNotContainsString(
                self::DEAD_REGEX_EXTENSIONS,
                $argument,
                'the M25 dead regex (the verbatim ' . self::DEAD_REGEX_EXTENSIONS . ' set) must not return: '
                . 'it outranks the /media/ and /api/ prefix locations and, without an upstream, 404s '
                . 'everything it captures. If a hashed-assets-only edge block is ever wanted, scope it to '
                . 'the /assets/app/ prefix instead of a global extension regex.'
            );
        }
    }

    public function testTheUpstreamStillTagsHashedAssetsImmutable(): void
    {
        // The deletion's justification is the UPSTREAM tag. If serveStatic stops emitting it,
        // hashed chunks lose their year of immutable caching silently — this pin forces the
        // two halves of the story to move together.
        $handler = self::read(self::UPSTREAM_AUTHORITY);

        self::assertStringContainsString(
            'function serveStatic',
            $handler,
            self::UPSTREAM_AUTHORITY . ' must still own static/asset serving (serveStatic) — '
            . 'the nginx template relies on it for every /assets/** request'
        );
        self::assertStringContainsString(
            self::UPSTREAM_CACHE_TAG,
            $handler,
            'the upstream immutable tag for realpath-jailed /assets/app/** is the authority that '
            . 'justifies the edge asset-block deletion (M-SERVER-25); if this header changes, the '
            . 'nginx/Caddy cache story must be revisited in the same commit'
        );
    }
}
