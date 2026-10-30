<?php

/**
 * Phlix media server component: Deploy.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Pins the README's restart-semantics prose for TRUSTED_PROXIES to the shipped
 * code (M-SERVER-45).
 *
 * The defect this guard was born from: the README's "What requires a restart"
 * section listed `TRUSTED_PROXIES` in BOTH camps at once — frozen at boot "by
 * `RateLimitProfiles` pre-flight in `start.php`" in the restart bullets, and
 * "rebuilt per request" in the runtime paragraph. A knob cannot live in two
 * camps, and the freezing claim was fiction on top of it: `RateLimitProfiles`
 * reads no proxy env at all. Both revision attempts of a fix before this one
 * closed with commits that do not exist (phantom closeouts), so the law is now
 * carried by this pin rather than by prose alone — the house README-claim
 * style of `BackupSubsystemReadmeTest` / `SystemdReloadSignalTest`: read the
 * README as text, cross-check every load-bearing claim against the source that
 * must back it.
 *
 * The code truth this pin encodes (verified at the shipping commit):
 *
 * - `TrustedProxyResolver::configuredProxies()` reads `getenv('TRUSTED_PROXIES')`
 *   on every call and the constructor snapshots it into the instance.
 * - The REST path (`Request::getTrustedClientIp()`) builds a fresh resolver on
 *   every invocation ⇒ a changed value applies at the next request ("live").
 * - The WS-connect path (`WebSocketServer::$trustedProxyResolver`) memoizes one
 *   resolver per worker process ⇒ there the value is restart-scoped — the same
 *   class as `PHLIX_SSRF_ALLOW_CIDRS` / `PHLIX_LIBRARY_ROOTS`.
 * - `RATE_LIMIT_*` genuinely IS boot-frozen, but by the config layer
 *   (`config/server.php` at config load + per-worker PHP-DI profile
 *   construction + the `WS_CONNECT` warm in `start.php`), never by a
 *   `RateLimitProfiles` "pre-flight", and it never froze the proxy env.
 */
final class TrustedProxiesReadmeRestartClassTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/README.md');
    }

    private static function source(string $relativePath): string
    {
        return (string) file_get_contents(self::repoRoot() . '/' . $relativePath);
    }

    /**
     * Collapse whitespace so the assertions speak prose, not line wrapping.
     */
    private static function prose(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * The full "### What requires a restart" section, from its heading to the
     * next level-2 heading (or EOF).
     */
    private static function restartSection(): string
    {
        $readme = self::readme();
        $start = strpos($readme, "### What requires a restart\n");
        self::assertIsInt($start, 'the README must keep its "What requires a restart" section');

        $end = strpos($readme, "\n## ", $start + 1);

        return $end === false
            ? substr($readme, $start)
            : substr($readme, $start, $end - $start);
    }

    /**
     * Camp split inside the section: the restart bullets (everything from the
     * heading up to the runtime paragraph) vs the runtime camp (from the
     * "Everything else" paragraph onward).
     *
     * @return array{0: string, 1: string}
     */
    private static function restartCamps(): array
    {
        $section = self::restartSection();
        $split = strpos($section, 'Everything else');
        self::assertIsInt($split, 'the restart section must keep its runtime ("Everything else") camp');

        return [substr($section, 0, $split), substr($section, $split)];
    }

    public function testTrustedProxiesIsNamedInExactlyOneCamp(): void
    {
        [$frozenCamp, $runtimeCamp] = self::restartCamps();

        $this->assertStringNotContainsString(
            'TRUSTED_PROXIES',
            $frozenCamp,
            'TRUSTED_PROXIES must not appear in the boot-frozen camp — the dual-camp'
            . ' listing was the M-SERVER-45 defect (a knob cannot require a restart and not).'
        );
        $this->assertStringContainsString(
            'TRUSTED_PROXIES',
            $runtimeCamp,
            'TRUSTED_PROXIES belongs to the runtime camp (the REST path reads the env per request).'
        );
    }

    public function testTheFrozenRateLimitProfilesMechanismFictionIsBanned(): void
    {
        [$frozenCamp] = self::restartCamps();

        $this->assertStringNotContainsString(
            'frozen by `RateLimitProfiles` pre-flight',
            self::prose($frozenCamp),
            'the RateLimitProfiles freezing claim was fiction: that class reads no proxy env'
            . ' (see testRateLimitProfilesReadsNoProxyEnv) and never froze TRUSTED_PROXIES.'
        );
    }

    public function testTheRateLimitBulletStatesTheRealFreezingMechanism(): void
    {
        [$frozenCamp] = self::restartCamps();
        $prose = self::prose($frozenCamp);

        $this->assertStringContainsString('RATE_LIMIT_*', $prose);
        $this->assertStringContainsString(
            'config/server.php',
            $prose,
            'RATE_LIMIT_* is boot-frozen by the config layer; the bullet must name that mechanism.'
        );
        $this->assertStringContainsString(
            'at config load',
            $prose,
            'the freezing point (config load / per-worker construction) must be explicit.'
        );
    }

    public function testTheRuntimeCampQualifiesTheRestAndWsSplit(): void
    {
        [, $runtimeCamp] = self::restartCamps();
        $prose = self::prose($runtimeCamp);

        $this->assertMatchesRegularExpression(
            '/TRUSTED_PROXIES\b.{0,400}?getTrustedClientIp\(\)/s',
            $prose,
            'the runtime camp must attribute the per-request REST read to Request::getTrustedClientIp().'
        );
        $this->assertMatchesRegularExpression(
            '/TRUSTED_PROXIES\b.{0,600}?WebSocketServer.{0,120}?worker boot/s',
            $prose,
            'the runtime camp must state the WS-connect per-worker memo (change applies at next worker boot).'
        );
    }

    public function testTheEnvTableRowKeepsLegendVocabularyAndStatesTheSplit(): void
    {
        $row = null;
        foreach (explode("\n", self::readme()) as $line) {
            if (str_starts_with($line, '| `TRUSTED_PROXIES` |')) {
                $row = $line;
                break;
            }
        }
        $this->assertIsString($row, 'the env census must keep its TRUSTED_PROXIES row.');

        $columns = array_map('trim', explode('|', $row));
        // [0] = '' before the first pipe, then Variable|Default|Secret?|Restart|Consumed by.
        $this->assertCount(7, $columns, 'the TRUSTED_PROXIES row must match the five-column env table.');
        $restartColumn = $columns[4];
        $this->assertContains(
            $restartColumn,
            ['yes', 'per-proc', 'live', 'n/a'],
            'the Restart column is legend vocabulary (the Environment variables intro defines exactly four values).'
        );

        $prose = self::prose($row);
        $this->assertStringContainsString(
            'every request',
            $prose,
            'the row must name the live REST read (legend "live" is only honest with this disclosure).'
        );
        $this->assertStringContainsString(
            'worker boot',
            $prose,
            'the row must disclose the WS-connect per-worker memo.'
        );
    }

    public function testRateLimitProfilesReadsNoProxyEnv(): void
    {
        $source = self::source('src/Common/RateLimit/RateLimitProfiles.php');

        $this->assertStringNotContainsString('TRUSTED_PROXIES', $source);
        $this->assertStringNotContainsString(
            'TrustedProxyResolver',
            $source,
            'the README law holds only while the frozen-camp prose matches the class:'
            . ' RateLimitProfiles must never grow a proxy read without a README re-class.'
        );
    }

    public function testTheRestPathBuildsAFreshResolverPerCall(): void
    {
        $source = self::source('src/Server/Http/Request.php');

        $this->assertStringContainsString(
            '$resolver ??= new TrustedProxyResolver();',
            $source,
            'getTrustedClientIp() sources the default resolver per invocation — this is the'
            . ' per-request (live) read the README runtime camp pins.'
        );
        $this->assertStringNotContainsString(
            'private ?TrustedProxyResolver',
            $source,
            'Request must not memoize a resolver instance property — that would move'
            . ' TRUSTED_PROXIES to a per-process camp and make this pin (and the README) false.'
        );
    }

    public function testTheWsConnectPathMemoizesOneResolverPerWorker(): void
    {
        $source = self::source('src/Server/WebSocket/WebSocketServer.php');

        $this->assertStringContainsString(
            'private ?TrustedProxyResolver $trustedProxyResolver = null;',
            $source,
            'the WS server keeps a per-worker resolver property — the worker-boot scoping the README discloses.'
        );
        $this->assertStringContainsString(
            '$this->trustedProxyResolver ??= new TrustedProxyResolver();',
            $source,
            'and constructs it once per worker with ??= (env snapshotted at first WS use).'
        );
    }

    public function testTheResolverReadsTheEnvAtEveryCallAndSnapshotsItPerInstance(): void
    {
        $source = self::source('src/Common/Http/TrustedProxyResolver.php');

        $this->assertStringContainsString(
            "getenv('TRUSTED_PROXIES')",
            $source,
            'configuredProxies() reads the env per call — the mechanism both camps describe.'
        );
        $this->assertStringContainsString(
            '$trustedProxies ?? self::configuredProxies()',
            $source,
            'the constructor snapshots the list per instance — why instance lifetime decides the restart class.'
        );
    }
}
