<?php

/**
 * Phlix media server test: coroutine-socket construction guards (S434).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use Phlix\Network\CoroutineSocketConstructionRefused;
use Phlix\Network\CoroutineSocketFault;
use Phlix\Network\CoroutineSocketGuard;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * S434 [S434SOCKETGUARDX9P4] — the segfault-prevention guards on every
 * `Swoole\Coroutine\Socket` construction in `src/Network/`.
 *
 * ## THE LAW THIS FILE ENFORCES
 *
 * S207 measured (2026-08-03, swoole 6.2.1 / PHP 8.3.6) that constructing a
 * coroutine socket from a faulting state SIGSEGVs INSIDE `new`, THROUGH an
 * enclosing `catch (\Throwable)` — a signal is not throwable, so containment
 * cannot fix it; only PREVENTION can. {@see CoroutineSocketGuard} is that
 * prevention: it classifies the two measured entry states (invalid arguments;
 * a failing `socket(2)` under descriptor exhaustion) before any `new` is
 * reached, and refuses them as a typed
 * {@see CoroutineSocketConstructionRefused}.
 *
 * ## ⚠ NEVER TRIGGER A REAL SEGFAULT HERE
 *
 * No test in this file constructs through the RAW path with faulting
 * arguments — the guard decision is pinned instead. That is deliberate and
 * honest: on the August build the faulting arm below would have killed the
 * PHPUnit process (exit 139), not failed a test; every arm here ends in a
 * refusal decision made BEFORE any `new`, so the suite can pin the behaviour
 * of a hazard it can never demonstrate in-process.
 *
 * ## REPRO RECORD, MEASURED 2026-09-05 (the AC's "recorded reproduction or
 * ## honest non-reproduction on the PROD build")
 *
 * The PROD build per code is `docker/Dockerfile.base` = `ARG PHP_VERSION=8.3`
 * (measured 8.3.33) + `SWOOLE_REF=v6.2.1`, alpine — NOT the 8.5 the plan prose
 * claimed; code wins. Both S207 arms were run as child processes against the
 * actual published image `ghcr.io/detain/phlix-base:latest` pulled today:
 *
 *   - `new \Swoole\Coroutine\Socket(AF_INET, 99999, 0)` inside
 *     `Swoole\Coroutine\run()`      -> `caught: Swoole\Coroutine\Socket\Exception`, exit 0.
 *   - live loop, fds exhausted INSIDE the running loop (EMFILE), then construct
 *     -> `caught: ...: new Socket() failed. Error: No file descriptors available [24]`, exit 0.
 *
 * Same arms on this box (PHP 8.3.6, swoole 6.2.1 built 2026-08-17 — a build
 * made AFTER the filing): also catchable, exit 0. HONEST NON-REPRODUCTION on
 * both current builds. The guard stands anyway: the fault was measured once,
 * it lived inside the vendor binary, and a future build re-acquiring it is
 * outside this estate's control — refusal costs two syscalls before a path
 * that then does a network round trip.
 *
 * ## RED PROOF TARGETS
 *
 * `testCreateRefusesTheInvalidSocketTypeWithoutReachingTheConstructor` and
 * `testTheGuardChokepointIsTheOnlyRawConstructionInSrcAndFiveSitesRouteThroughIt`
 * are the planted-drift pins: neutering the guard (restoring a raw `new` at any
 * site, or deleting `preflight`) reddens them BY NAME.
 */
final class CoroutineSocketGuardTest extends TestCase
{
    use RunsInCoroutine;

    /** Survival token — code-resident, never in any .md file. */
    private const TOKEN = 'S434SOCKETGUARDX9P4';

    private const GUARD_FILE = 'CoroutineSocketGuard.php';

    private const NETWORK_DIR = __DIR__ . '/../../../src/Network';
    private const SRC_DIR = __DIR__ . '/../../../src';

    /**
     * The five guarded construction sites this step found and wired, grouped by
     * file — re-measured every run; a sixth site, a moved site, or a site that
     * stops routing through the guard reddens the census test with this list in
     * its diff (S431's lesson: pin denominators are recomputed, never prose).
     *
     * @var array<string,int>
     */
    private const EXPECTED_GUARDED_SITES = [
        'NatPmpClient.php' => 1,
        'PortForwardService.php' => 1,
        'StunClient.php' => 2, // the seam AND the direct probeViaCoroutineSocket() site
        'UpnpIgdClient.php' => 1,
    ];

    public function testTheTokenIsWhereThisFileSaysItIs(): void
    {
        $this->assertSame('S434SOCKETGUARDX9P4', self::TOKEN, 'S434 [' . self::TOKEN . ']');
    }

    // ---------------------------------------------------------------- decisions

    public function testPreflightAcceptsTheShapesThisEstateConstructs(): void
    {
        $dgram = $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, SOCK_DGRAM, 0));
        $stream = $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, SOCK_STREAM, 0));

        $this->assertNull($dgram, 'S434 [' . self::TOKEN . ']: AF_INET/SOCK_DGRAM is what four seams construct — it must pass.');
        $this->assertNull($stream, 'S434 [' . self::TOKEN . ']: AF_INET/SOCK_STREAM is what the STUN probe constructs — it must pass.');
    }

    public function testPreflightRefusesTheInvalidSocketTypeThatS207MeasuredFaulting(): void
    {
        $refusal = $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, 99999, 0));

        $this->assertInstanceOf(
            CoroutineSocketConstructionRefused::class,
            $refusal,
            'S434 [' . self::TOKEN . ']: socket type 99999 is the exact argument S207 measured faulting the worker '
            . 'inside `new` on the 2026-08-03 build. It must be refused as a typed verdict BEFORE construction.'
        );
        $this->assertSame(
            CoroutineSocketFault::InvalidArguments,
            $refusal->fault,
            'S434 [' . self::TOKEN . ']: the refusal must name WHICH precondition failed (S169 typed-outcome shape).'
        );
    }

    public function testCreateRefusesTheInvalidSocketTypeWithoutReachingTheConstructor(): void
    {
        // If preflight were neutered, this call would reach `new` and raise the
        // vendor Swoole\Coroutine\Socket\Exception (today's builds) — a DIFFERENT
        // class, so this test reddens either way. On the August build it would
        // have killed the process; that is exactly what the refusal prevents.
        $outcome = $this->runInCoroutine(static function (): array {
            try {
                $sock = CoroutineSocketGuard::create(AF_INET, 99999, 0);
                $sock->close();
                return ['constructed', null];
            } catch (CoroutineSocketConstructionRefused $e) {
                return ['refused', $e->fault];
            } catch (\Throwable $e) {
                return ['vendor-throwable', $e::class];
            }
        });

        $this->assertSame(
            ['refused', CoroutineSocketFault::InvalidArguments],
            $outcome,
            'S434 [' . self::TOKEN . ']: create() must refuse the faulting shape with the typed exception; '
            . 'the guard decision, not a demo of the signal, is what this suite can pin.'
        );
    }

    public function testPreflightRefusesNonAdmittedDomainsAndProtocol(): void
    {
        $cases = [
            'AF_UNIX domain' => [AF_UNIX, SOCK_DGRAM, 0],
            'bogus protocol' => [AF_INET, SOCK_DGRAM, 6],
            'SOCK_RAW type' => [AF_INET, SOCK_RAW, 0],
        ];

        foreach ($cases as $label => [$domain, $type, $protocol]) {
            $refusal = $this->runInCoroutine(
                static fn () => CoroutineSocketGuard::preflight($domain, $type, $protocol)
            );
            $this->assertInstanceOf(
                CoroutineSocketConstructionRefused::class,
                $refusal,
                'S434 [' . self::TOKEN . ']: ' . $label . ' is outside the admission list and must never reach `new`.'
            );
            $this->assertSame(CoroutineSocketFault::InvalidArguments, $refusal->fault);
        }
    }

    public function testHeadroomBelowTheMarginIsRefusedAsDescriptorExhaustion(): void
    {
        $refusal = CoroutineSocketGuard::withMeasurementProbe(
            static fn (): int => CoroutineSocketGuard::MIN_FREE_DESCRIPTORS - 1,
            fn () => $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, SOCK_DGRAM, 0))
        );

        $this->assertInstanceOf(CoroutineSocketConstructionRefused::class, $refusal);
        $this->assertSame(
            CoroutineSocketFault::DescriptorExhaustion,
            $refusal->fault,
            'S434 [' . self::TOKEN . ']: free descriptors one below the margin is the second entry state S207 '
            . 'measured faulting (socket(2) EMFILE inside `new`) — it must be refused pre-construction.'
        );
    }

    public function testHeadroomAtExactlyTheMarginIsAdmitted(): void
    {
        $refusal = CoroutineSocketGuard::withMeasurementProbe(
            static fn (): int => CoroutineSocketGuard::MIN_FREE_DESCRIPTORS,
            fn () => $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, SOCK_DGRAM, 0))
        );

        $this->assertNull($refusal, 'S434 [' . self::TOKEN . ']: the margin boundary is inclusive; refusing at '
            . CoroutineSocketGuard::MIN_FREE_DESCRIPTORS . ' would turn a healthy worker into fallback-only.');
    }

    public function testUnmeasurableHeadroomFailsClosed(): void
    {
        $refusal = CoroutineSocketGuard::withMeasurementProbe(
            static fn (): ?int => null,
            fn () => $this->runInCoroutine(fn () => CoroutineSocketGuard::preflight(AF_INET, SOCK_DGRAM, 0))
        );

        $this->assertInstanceOf(CoroutineSocketConstructionRefused::class, $refusal);
        $this->assertSame(
            CoroutineSocketFault::UnmeasurableHeadroom,
            $refusal->fault,
            'S434 [' . self::TOKEN . ']: a guard that cannot measure must not claim safety — fail closed and let '
            . 'the caller degrade to its blocking fallback (a gate that cannot measure must not report success).'
        );
    }

    public function testPreflightRefusesOutsideACoroutineContext(): void
    {
        // Main stack: getCid() is -1 (measured, see RunsInCoroutine). Every site
        // forks on inCoroutine() before reaching the guard; this pins that even a
        // caller that forgot cannot construct.
        $refusal = CoroutineSocketGuard::preflight(AF_INET, SOCK_DGRAM, 0);

        $this->assertInstanceOf(CoroutineSocketConstructionRefused::class, $refusal);
        $this->assertSame(CoroutineSocketFault::NotInCoroutine, $refusal->fault);
    }

    public function testTheRealMeasurementReportsHealthyHeadroomOnThisBox(): void
    {
        // Independent re-measurement via /proc/self/limits (the guard's PRIMARY
        // source on this estate is posix_getrlimit — measured 2026-09-05, both
        // Debian and prod-alpine expose RLIMIT_NOFILE as the "soft openfiles"
        // key — so comparing against the second source proves the two agree).
        $limits = file_get_contents('/proc/self/limits');
        if ($limits === false || preg_match('/^Max open files\s+(\S+)/mi', $limits, $m) !== 1) {
            $this->markTestSkipped('/proc/self/limits is not parseable on this box.');
        }
        $soft = strcasecmp($m[1], 'unlimited') === 0 ? PHP_INT_MAX : (int) $m[1];

        $headroom = CoroutineSocketGuard::headroom();
        $this->assertIsInt($headroom, 'S434 [' . self::TOKEN . ']: /proc-based measurement must work on Linux (prod + CI + this box).');

        $open = count(glob('/proc/self/fd/*') ?: []);
        $this->assertEqualsWithDelta($soft - $open, $headroom, 8,
            'The guard must agree with an independent re-measurement within a couple of transient descriptors.');
        $this->assertGreaterThanOrEqual(CoroutineSocketGuard::MIN_FREE_DESCRIPTORS, $headroom,
            'A healthy test worker must not be refused — if this fails, the machine, not the guard, changed.');
    }

    public function testCreateInsideACoroutineStillConstructsARealSocketOnHealthyState(): void
    {
        // The healthy path must be BEHAVIOUR-UNCHANGED: preflight passes and the
        // guard hands back a genuine Swoole\Coroutine\Socket, closed here so the
        // suite does not leak descriptors.
        $class = $this->runInCoroutine(static function (): string {
            $sock = CoroutineSocketGuard::create(AF_INET, SOCK_DGRAM, 0);
            try {
                return $sock::class;
            } finally {
                $sock->close();
            }
        });

        $this->assertSame(\Swoole\Coroutine\Socket::class, $class);
    }

    // ------------------------------------------------------------------- shape

    public function testTheFaultEnumIsTotalAndPinned(): void
    {
        $cases = CoroutineSocketFault::cases();
        $this->assertCount(4, $cases,
            'S434 [' . self::TOKEN . ']: the fault taxonomy is pinned. A new refusal reason must be added HERE '
            . 'deliberately, and every test that expects a specific fault reviews itself against it.');
        $this->assertTrue(CoroutineSocketFault::InvalidArguments->isRuntimeCondition() === false);
        $this->assertTrue(CoroutineSocketFault::DescriptorExhaustion->isRuntimeCondition());
    }

    public function testTheGuardDecidesAndNeverCatches(): void
    {
        // Prevention law: the guard contains NO catch. A `catch` here would mean
        // someone reintroduced the containment strategy this step exists to replace.
        $source = file_get_contents(self::NETWORK_DIR . '/' . self::GUARD_FILE);
        $this->assertIsString($source);

        $tokens = token_get_all($source);
        foreach ($tokens as $token) {
            $this->assertFalse(
                is_array($token) && $token[0] === T_CATCH,
                'S434 [' . self::TOKEN . ']: CoroutineSocketGuard must never catch — a SIGSEGV is not throwable, '
                . 'so its whole contract is refusing BEFORE construction.'
            );
        }
    }

    // ------------------------------------------------------------------ census

    public function testTheGuardChokepointIsTheOnlyRawConstructionInSrcAndFiveSitesRouteThroughIt(): void
    {
        $raw = self::scanSrcForRawConstructions();
        $routed = self::scanSrcForGuardCallSites();

        $outside = array_values(array_filter(
            $raw,
            static fn (string $site): bool => !str_contains($site, self::GUARD_FILE . ':')
        ));
        $inside = array_values(array_filter(
            $raw,
            static fn (string $site): bool => str_contains($site, self::GUARD_FILE . ':')
        ));

        $this->assertSame([], $outside,
            'S434 [' . self::TOKEN . ']: raw `new \Swoole\Coroutine\Socket(...)` found in src/ OUTSIDE the guard '
            . 'chokepoint. S207 measured this construction faulting the worker uncatchably; every construction '
            . 'must route through CoroutineSocketGuard::create(). Sites: ' . implode(', ', $outside));

        $this->assertCount(1, $inside,
            'S434 [' . self::TOKEN . ']: the guard must own EXACTLY ONE raw construction — the chokepoint itself. '
            . 'Found: ' . implode(', ', $inside));

        $grouped = [];
        foreach ($routed as $site) {
            $file = basename(explode(':', $site)[0]);
            $grouped[$file] = ($grouped[$file] ?? 0) + 1;
        }
        ksort($grouped);
        $expected = self::EXPECTED_GUARDED_SITES;
        ksort($expected);

        $this->assertSame($expected, $grouped,
            'S434 [' . self::TOKEN . ']: the guarded-site denominator moved. Sites now: '
            . implode(', ', $routed) . '. Update EXPECTED_GUARDED_SITES ONLY if the change is intended.');
    }

    public function testEveryNetworkSeamStillDelegatesToTheGuard(): void
    {
        $routed = self::scanSrcForGuardCallSitesPerFile();
        foreach (array_keys(self::EXPECTED_GUARDED_SITES) as $file) {
            $this->assertArrayHasKey($file, $routed,
                'S434 [' . self::TOKEN . ']: ' . $file . ' lost its CoroutineSocketGuard::create() call site.');
        }
        // And the four classes still expose the S197 override seam the containment
        // tests plant failures through — the guard did not remove the seam.
        foreach (
            [
                \Phlix\Network\NatPmpClient::class,
                \Phlix\Network\PortForwardService::class,
                \Phlix\Network\StunClient::class,
                \Phlix\Network\UpnpIgdClient::class,
            ] as $class
        ) {
            $method = (new ReflectionClass($class))->getMethod('createCoroutineSocket');
            $this->assertTrue($method->isProtected(), $class . ' must keep the protected seam (S197 contract).');
        }
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Token-level scan of every PHP file under src/ for `new` of the
     * fully/qualified class
     * `Swoole\Coroutine\Socket`. Tokenised, not grepped: the estate's docblocks
     * (including this step's) quote the hazard in prose, and a text match would
     * count prose as code. Returns `relative/path.php:line` sites.
     *
     * @return list<string>
     */
    private static function scanSrcForRawConstructions(): array
    {
        $sites = [];
        foreach (self::phpFiles() as $file) {
            $tokens = token_get_all(file_get_contents($file));
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $token = $tokens[$i];
                if (!is_array($token) || $token[0] !== T_NEW) {
                    continue;
                }
                $name = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $name .= $t[1];
                    }
                    break;
                }
                if (str_replace('\\', '', strtolower($name)) === 'swoolecoroutinesocket') {
                    $sites[] = self::relative($file) . ':' . (is_array($token) ? $token[2] : 0) . ':*';
                }
            }
        }

        return $sites;
    }

    /**
     * Token-level scan of src/ for `CoroutineSocketGuard::create(` call sites.
     * Comments cannot match: the estate's docblocks quote the hazard in prose.
     *
     * @return list<string>
     */
    private static function scanSrcForGuardCallSites(): array
    {
        $sites = [];
        foreach (self::phpFiles() as $file) {
            $tokens = token_get_all(file_get_contents($file));
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                $token = $tokens[$i];
                if (is_array($token) && $token[0] === T_STRING && $token[1] === 'CoroutineSocketGuard') {
                    // Next meaningful token pair must be T_DOUBLE_COLON + T_STRING 'create'.
                    for ($j = $i + 1; $j < $n; $j++) {
                        $t = $tokens[$j];
                        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        if (!(is_array($t) && $t[0] === T_DOUBLE_COLON)) {
                            break;
                        }
                        for ($k = $j + 1; $k < $n; $k++) {
                            $u = $tokens[$k];
                            if (is_array($u) && in_array($u[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                continue;
                            }
                            if (is_array($u) && $u[0] === T_STRING && $u[1] === 'create') {
                                $sites[] = self::relative($file) . ':' . $u[2];
                            }
                            break;
                        }
                        break;
                    }
                }
            }
        }

        return $sites;
    }

    /**
     * @return array<string,int>
     */
    private static function scanSrcForGuardCallSitesPerFile(): array
    {
        $grouped = [];
        foreach (self::scanSrcForGuardCallSites() as $site) {
            $file = basename(explode(':', $site)[0]);
            $grouped[$file] = ($grouped[$file] ?? 0) + 1;
        }

        return $grouped;
    }

    /**
     * @return list<string> Every *.php under src/, sorted.
     */
    private static function phpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SRC_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function relative(string $absolute): string
    {
        $root = realpath(self::SRC_DIR . '/..') . '/';

        return str_replace($root, '', $absolute);
    }
}
