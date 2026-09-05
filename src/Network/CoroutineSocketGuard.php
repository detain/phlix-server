<?php

/**
 * Phlix media server component: Network.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Network;

/**
 * THE construction chokepoint for `Swoole\Coroutine\Socket` in `src/` — S434.
 *
 * ## THE HAZARD, AND WHY A CANNOT FIX IT
 *
 * S207 measured (2026-08-03, swoole 6.2.1 / PHP 8.3.6): constructing a coroutine
 * socket from a faulting state SIGSEGVs INSIDE `new \Swoole\Coroutine\Socket(...)`
 * — through an enclosing `catch (\Throwable)`. A signal is not a throwable; the
 * widened S197 catches cannot contain it, and neither can any handler. The two
 * measured entry states were (1) an invalid socket type (`99999` — the kernel
 * `socket(2)` call fails with EINVAL) and (2) descriptor exhaustion (a genuine
 * `socket(2)` EMFILE, provoked with FFI `setrlimit(RLIMIT_NOFILE, 16)`).
 *
 * Re-measured 2026-09-05 on the PROD base image (`ghcr.io/detain/phlix-base:latest`,
 * PHP 8.3.33-alpine, swoole 6.2.1) and on this box (PHP 8.3.6, swoole 6.2.1 built
 * 2026-08-17): BOTH arms now raise a catchable `Swoole\Coroutine\Socket\Exception`
 * instead of faulting — the August build's fault did not reproduce on these builds.
 * That is a reason to keep this class cheap, not a reason to skip it: the fault was
 * measured once, it lived inside the vendor binary, and nothing in the estate can
 * pin when a future build re-acquires it. Prevention costs two syscalls on a path
 * that then makes a network round trip.
 *
 * ## THE CONTRACT
 *
 * {@see self::create()} is the ONLY place in `src/` allowed to say
 * `new \Swoole\Coroutine\Socket(...)`;
 * `CoroutineSocketGuardTest::testEveryCoroutineSocketConstructionInSrcRoutesThroughThisGuard()`
 * re-tokenizes the tree and reddens otherwise. Before constructing it verifies each
 * measured entry state and refuses it by name — a typed
 * {@see CoroutineSocketConstructionRefused}, which every guarded site already
 * contains via its `catch (\Throwable)` and degrades to its blocking fallback.
 *
 * ## WHY CHECK-THEN-CONSTRUCT IS SOUND HERE
 *
 * Coroutine scheduling is cooperative: between the headroom measurement and the
 * `socket(2)` inside `new` there is no `co::yield` point, no user code, and
 * `RLIMIT_NOFILE` is per-process, so no sibling coroutine of THIS worker can
 * consume the margin between verdict and construction. (Other workers are other
 * processes with their own descriptor tables.) The margin below absorbs the
 * measurement's own descriptor and any same-stretch allocations the C layer makes.
 *
 * @package Phlix\Network
 * @since 0.32.0
 */
final class CoroutineSocketGuard
{
    /**
     * Descriptors that must be provably free at check time for one more `socket(2)`
     * to be attempted. 1 would be sound by the atomicity argument above; 8 is the
     * belt-and-braces margin for the measurement itself (opening `/proc/self/fd`
     * holds one descriptor while counting) and for any allocation the swoole
     * constructor performs beyond the single socket fd.
     */
    public const MIN_FREE_DESCRIPTORS = 8;

    /**
     * Test seam for the headroom measurement — set ONLY through
     * {@see self::withMeasurementProbe()}, which restores in a `finally`.
     *
     * @var (callable(): ?int)|null
     */
    private static $measurementProbe = null;

    private function __construct()
    {
    }

    /**
     * Constructs a coroutine socket AFTER proving the faulting state is not entered.
     *
     * @param int $domain    Socket domain, e.g. AF_INET.
     * @param int $type      Socket type, SOCK_STREAM or SOCK_DGRAM.
     * @param int $protocol  Socket protocol; this estate only ever passes 0.
     *
     * @throws CoroutineSocketConstructionRefused A precondition failed; the
     *         construction was NOT attempted and the worker is alive. Every
     *         call site catches this via its `catch (\Throwable)` and degrades.
     */
    public static function create(int $domain, int $type, int $protocol = 0): \Swoole\Coroutine\Socket
    {
        $refusal = self::preflight($domain, $type, $protocol);
        if ($refusal !== null) {
            throw $refusal;
        }

        // The ONLY `new \Swoole\Coroutine\Socket` in src/ (pinned by the census test).
        return new \Swoole\Coroutine\Socket($domain, $type, $protocol);
    }

    /**
     * The prevention decision as a pure function: null means "safe to construct",
     * non-null is the refusal to raise. Checked in cost order — a context read,
     * then argument membership, then the two syscalls.
     *
     * @return CoroutineSocketConstructionRefused|null Verdict; never throws.
     */
    public static function preflight(int $domain, int $type, int $protocol = 0): ?CoroutineSocketConstructionRefused
    {
        if (!class_exists(\Swoole\Coroutine::class) || \Swoole\Coroutine::getCid() <= 0) {
            return self::refusal(
                CoroutineSocketFault::NotInCoroutine,
                'a coroutine-socket construction was attempted outside a live coroutine context',
                ['domain' => $domain, 'type' => $type, 'protocol' => $protocol]
            );
        }

        $argumentFault = self::invalidArgument($domain, $type, $protocol);
        if ($argumentFault !== null) {
            return self::refusal(
                CoroutineSocketFault::InvalidArguments,
                sprintf(
                    'refused to construct Swoole\\Coroutine\\Socket(%d, %d, %d): %s — this is the '
                    . 'argument shape S207 measured faulting inside `new` on the 2026-08-03 build',
                    $domain,
                    $type,
                    $protocol,
                    $argumentFault
                ),
                ['domain' => $domain, 'type' => $type, 'protocol' => $protocol]
            );
        }

        $free = self::headroom();
        if ($free === null) {
            return self::refusal(
                CoroutineSocketFault::UnmeasurableHeadroom,
                'descriptor headroom is unmeasurable on this platform, and construction fails closed',
                ['domain' => $domain, 'type' => $type, 'protocol' => $protocol]
            );
        }
        if ($free < self::MIN_FREE_DESCRIPTORS) {
            return self::refusal(
                CoroutineSocketFault::DescriptorExhaustion,
                sprintf(
                    'only %d descriptor(s) free below soft RLIMIT_NOFILE; %d required — a failing '
                    . 'socket(2) here is what S207 measured faulting inside `new`',
                    $free,
                    self::MIN_FREE_DESCRIPTORS
                ),
                ['free' => $free, 'required' => self::MIN_FREE_DESCRIPTORS]
            );
        }

        return null;
    }

    /**
     * Free descriptors before the soft limit, or null when unmeasurable. Routes
     * through the test probe when one is scoped.
     */
    public static function headroom(): ?int
    {
        $probe = self::$measurementProbe;
        if ($probe !== null) {
            return $probe();
        }

        return self::measureHeadroom();
    }

    /**
     * Runs $body with $probe supplying the headroom measurement, restoring the
     * previous probe (which may itself be a probe — nestable) in a `finally`.
     *
     * @param callable(): ?int $probe Returns free-descriptor count, or null for unmeasurable.
     * @param callable(): mixed $body  Body to run while the probe is active.
     *
     * @return mixed Whatever $body returned.
     */
    public static function withMeasurementProbe(callable $probe, callable $body): mixed
    {
        $previous = self::$measurementProbe;
        self::$measurementProbe = $probe;

        try {
            return $body();
        } finally {
            self::$measurementProbe = $previous;
        }
    }

    /**
     * The argument admission list, stated as membership rather than a deny list:
     * only what the estate constructs is constructible. Returns a human-readable
     * reason, or null when all three arguments are admissible.
     */
    private static function invalidArgument(int $domain, int $type, int $protocol): ?string
    {
        $domains = self::definedValues(['AF_INET', 'AF_INET6']);
        if ($domains === [] || !in_array($domain, $domains, true)) {
            return sprintf('domain %d is not one of the admitted %s', $domain, self::describe($domains));
        }

        $types = self::definedValues(['SOCK_STREAM', 'SOCK_DGRAM']);
        if ($types === [] || !in_array($type, $types, true)) {
            return sprintf('type %d is not one of the admitted %s', $type, self::describe($types));
        }

        if ($protocol !== 0) {
            return sprintf('protocol %d is not 0', $protocol);
        }

        return null;
    }

    /**
     * @param list<string> $names Constant names possibly provided by ext-sockets.
     *
     * @return list<int> Defined values, in name order; empty when the extension is absent.
     */
    private static function definedValues(array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            if (defined($name)) {
                $value = constant($name);
                if (is_int($value)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * @param list<int> $values
     */
    private static function describe(array $values): string
    {
        return $values === [] ? '(constants absent)' : implode('/', array_map('strval', $values));
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function refusal(
        CoroutineSocketFault $fault,
        string $message,
        array $context
    ): CoroutineSocketConstructionRefused {
        return new CoroutineSocketConstructionRefused($fault, $message, $context);
    }

    /**
     * Soft RLIMIT_NOFILE minus currently-open descriptors; null when either half
     * cannot be measured on this platform.
     */
    private static function measureHeadroom(): ?int
    {
        $soft = self::softDescriptorLimit();
        $open = self::countOpenDescriptors();
        if ($soft === null || $open === null) {
            return null;
        }

        return $soft - $open;
    }

    /**
     * Soft RLIMIT_NOFILE as an int, PHP_INT_MAX when the kernel says unlimited,
     * null when neither `posix_getrlimit()` nor `/proc/self/limits` answers.
     * (Both sources are present on the prod alpine image and on CI — measured
     * 2026-09-05.)
     */
    private static function softDescriptorLimit(): ?int
    {
        if (function_exists('posix_getrlimit')) {
            $limits = @posix_getrlimit();
            // Measured 2026-09-05: both this box (PHP 8.3.6/Debian) and the prod
            // base image (PHP 8.3.33/alpine) expose RLIMIT_NOFILE as "soft openfiles" —
            // posix_getrlimit() returns a fixed key set and "soft nofile" is not in it
            // (Psalm InvalidArrayOffset). Builds without the key fall through to the
            // /proc/self/limits parse below.
            $raw = is_array($limits) ? ($limits['soft openfiles'] ?? null) : null;
            // Measured string("unlimited"|"1048576") on both platforms; Psalm's stub
            // types the shape as int, so both scalars are admitted and normalized.
            if (is_int($raw) || is_string($raw)) {
                $soft = trim((string) $raw);
                if (strcasecmp($soft, 'unlimited') === 0) {
                    return PHP_INT_MAX;
                }
                if (ctype_digit($soft)) {
                    return (int) $soft;
                }
            }
        }

        $contents = @file_get_contents('/proc/self/limits');
        if (is_string($contents) && preg_match('/^Max open files\s+(\S+)/mi', $contents, $m) === 1) {
            if (strcasecmp($m[1], 'unlimited') === 0) {
                return PHP_INT_MAX;
            }
            if (ctype_digit($m[1])) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Currently-open descriptors, including the one this count itself opens (the
     * margin absorbs it). Null when `/proc/self/fd` is not listable.
     */
    private static function countOpenDescriptors(): ?int
    {
        if (!is_dir('/proc/self/fd')) {
            return null;
        }

        $entries = @scandir('/proc/self/fd');
        if (!is_array($entries)) {
            return null;
        }

        // scandir lists '.' and '..'; the rest are one entry per open descriptor.
        return max(0, count($entries) - 2);
    }
}
