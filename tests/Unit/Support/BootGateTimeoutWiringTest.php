<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * S210 — the docker boot gates are BOUNDED and their slowness is LOUD.
 *
 * ## The defect this pins shut (measured, not imagined)
 *
 * `docker-boot-gate` carried no `timeout-minutes` at all, so the GitHub default
 * (360 min) applied. On 2026-08-04 PR #623 the intel and nvidia legs sat in
 * "Boot and smoke-test the image" past 34 minutes with no log line naming what
 * they were waiting on, and a human cancelled them. The R1 mining of 500
 * docker.yml runs (2026-07-22..2026-09-09) showed the real shape: Ubuntu
 * mirror bandwidth storms degrade the in-build `apt-get` fetches on master
 * pushes AND PR runs alike (worst successful leg anywhere: nvidia PR, 4530s
 * on 2026-09-05; same-night master legs 2113s/2818s) — an uncached build
 * needs minutes of silence to look identical to a hang either way.
 *
 * The fix is two-sided, and each side is one deletable line from silent
 * (the exact shape {@see AssertionEscapeGuardWiringTest} documents): delete
 * the `timeout-minutes:` key and the gate silently returns to a 6-hour
 * default; delete the heartbeat and a stall silently returns to invisible.
 * This test makes both deletions red in `vendor/bin/phpunit`.
 *
 * ## What the bound is, and why relaxing it is the landmine
 *
 * 90 minutes = 1.19x the worst SUCCESSFUL leg in five weeks of history
 * (4530s), 9.8x the master p95 (552s). A tighter 15-20 min bound would have
 * reddened 11 legs that measurably completed — manufacturing the false-red
 * pressure under which gates get loosened. This test therefore pins the
 * EXACT value 90: a quiet edit to 360 (restoring the default) or to 15
 * (importing the false-reds) is a test failure demanding a written argument,
 * not a two-character diff. The pin is enforced against mutated copies below
 * (negative control), so it cannot rot into vacuity.
 *
 * ## What the heartbeat must never become
 *
 * The heartbeat logs liveness with NO verdict ids: DockerEntrypointTest pins
 * the exact 20-id check set, and `tests/Unit/Docker` machinery counts pass/
 * fail call sites. If someone routes heartbeats through pass()/fail(), the
 * name set changes and either that test or `testTheHeartbeatRecordsNoVerdicts`
 * below goes red.
 */
final class BootGateTimeoutWiringTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const WORKFLOW = self::REPO . '/.github/workflows/docker.yml';

    private const SMOKE_SCRIPT = self::REPO . '/scripts/docker-boot-smoke.sh';

    /** S210 survival token — must appear as an executable no-op line in the script. */
    private const S210_TOKEN = 'S210BOOTTIMEOUTX8H3';

    /** The stated AC bound. See docblock: justified from measured history, not taste. */
    private const EXPECTED_TIMEOUT_MINUTES = 90;

    /** @return array<string, mixed> */
    private static function workflowFrom(string $path): array
    {
        $parsed = Yaml::parseFile($path);

        self::assertIsArray($parsed, "docker.yml did not parse to a mapping: {$path}");

        return $parsed;
    }

    /** @param array<string, mixed> $workflow */
    private static function bootGateJobOf(array $workflow): array
    {
        $jobs = $workflow['jobs'] ?? null;
        self::assertIsArray($jobs, 'docker.yml has no jobs mapping');
        $job = $jobs['docker-boot-gate'] ?? null;
        self::assertIsArray($job, 'docker-boot-gate job vanished from docker.yml');

        return $job;
    }

    public function testTheBootGateJobCarriesTheStatedTimeoutBound(): void
    {
        $job = self::bootGateJobOf(self::workflowFrom(self::WORKFLOW));

        self::assertSame(
            self::EXPECTED_TIMEOUT_MINUTES,
            $job['timeout-minutes'] ?? null,
            'docker-boot-gate must carry timeout-minutes: ' . self::EXPECTED_TIMEOUT_MINUTES
                . ' (90 = 1.19x the worst measured successful leg, 4530s; see the comment in'
                . ' docker.yml). Changing the bound requires restating that evidence.'
        );
    }

    public function testTheBoundValueIsActuallyInspectedNotJustPresent(): void
    {
        // Negative control (a "nothing matched" defence needs its own guard):
        // both loosening mutations must produce a workflow this pin REJECTS.
        $dir = sys_get_temp_dir() . '/s210-bound-' . posix_getpid();
        if (!is_dir($dir)) {
            self::assertNotFalse(mkdir($dir, 0o700, true), "cannot create {$dir}");
        }

        try {
            foreach ([360, 15] as $mutant) {
                $raw = (string) file_get_contents(self::WORKFLOW);
                $mutated = str_replace(
                    'timeout-minutes: ' . self::EXPECTED_TIMEOUT_MINUTES,
                    'timeout-minutes: ' . $mutant,
                    $raw
                );
                self::assertNotSame($raw, $mutated, 'timeout line not findable to mutate');
                $file = $dir . "/docker-{$mutant}.yml";
                file_put_contents($file, $mutated);

                $job = self::bootGateJobOf(self::workflowFrom($file));
                self::assertNotSame(
                    self::EXPECTED_TIMEOUT_MINUTES,
                    $job['timeout-minutes'] ?? null,
                    "mutant timeout-minutes: {$mutant} passed the pin — the pin is vacuous"
                );
            }
        } finally {
            foreach ((array) glob($dir . '/*.yml') as $f) {
                if (is_string($f)) {
                    unlink($f);
                }
            }
            @rmdir($dir);
        }
    }

    public function testAddingTheTimeoutDidNotRenameOrDropAnyBootLeg(): void
    {
        $workflow = self::workflowFrom(self::WORKFLOW);
        $job = self::bootGateJobOf($workflow);

        // merge-under-lock compares check NAME sets; the names come from this
        // template + these three matrix legs. timeout-minutes is a job
        // attribute — the name machinery must be byte-identical in spirit.
        self::assertSame('Boot gate (${{ matrix.name }})', $job['name'] ?? null);

        $names = $job['strategy']['matrix']['include'] ?? null;
        self::assertIsArray($names, 'boot matrix include list vanished');

        $legNames = [];
        foreach ($names as $leg) {
            self::assertIsArray($leg);
            $legNames[] = $leg['name'] ?? null;
        }
        sort($legNames);
        self::assertSame(['alpine', 'intel', 'nvidia'], $legNames);
    }

    public function testTheSmokeScriptCarriesTheSurvivalTokenAsAnExecutableLine(): void
    {
        $raw = (string) file_get_contents(self::SMOKE_SCRIPT);

        // Whole-line `#` comments are stripped by both this repo's directive
        // parser and the premerge `--token-in` tooling, so the token must live
        // on an executable no-op line — `: TOKEN` — never in a comment.
        self::assertMatchesRegularExpression(
            '/^: ' . preg_quote(self::S210_TOKEN, '/') . '$/m',
            $raw,
            'S210 token must be an executable `: ` no-op line in the smoke script'
        );
    }

    public function testThePhaseBannerRecordsIntoTheHeartbeatsPhaseFile(): void
    {
        $raw = (string) file_get_contents(self::SMOKE_SCRIPT);

        self::assertMatchesRegularExpression(
            '/say\(\)\s*\{(?:[^{}]|\{[^{}]*\})*PHASE_FILE(?:[^{}]|\{[^{}]*\})*\}/s',
            $raw,
            'say() no longer records the phase for the heartbeat — the stall-vs-slow'
                . ' signal is gone and timeout-minutes would again kill jobs blind.'
        );
    }

    public function testTheHeartbeatRecordsNoVerdicts(): void
    {
        $raw = (string) file_get_contents(self::SMOKE_SCRIPT);

        self::assertMatchesRegularExpression('/heartbeat\(\)\s*\{/', $raw, 'heartbeat() vanished');
        $body = self::functionBodyOf($raw, 'heartbeat');

        // Strip whole-line comments (the docblock mentions pass/fail as a
        // prohibition; prose is not a call site), then look for call sites.
        $code = preg_replace('/^\s*#.*$/m', '', $body) ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/(?:^|[\s;&|(])+(?:pass|fail)\s+["\']/',
            $code,
            'heartbeat() must never record verdicts: DockerEntrypointTest pins the'
                . ' exact check-id set, and a liveness line must not become one.'
        );
        self::assertStringContainsString('[heartbeat] alive', $code);
    }

    public function testTheHeartbeatIsStartedBeforeAnyPhaseAndStoppedInCleanup(): void
    {
        $raw = (string) file_get_contents(self::SMOKE_SCRIPT);

        $start = strpos($raw, "\nheartbeat &\n");
        $trap = strpos($raw, 'trap cleanup EXIT');
        self::assertIsInt($start, 'heartbeat is never started');
        self::assertIsInt($trap, 'EXIT trap vanished');
        self::assertLessThan($trap, $start, 'heartbeat must start before the first say() phase');

        $cleanup = self::functionBodyOf($raw, 'cleanup');
        self::assertLessThan(
            (int) strpos($cleanup, 'if [ "$KEEP" = "1" ]'),
            (int) strpos($cleanup, 'kill "$HEARTBEAT_PID"'),
            'cleanup() must stop the heartbeat before its early return (KEEP=1 path)'
        );
        self::assertMatchesRegularExpression('/if \[ "\$HEARTBEAT_INTERVAL" -lt 1 \]/', $raw);
    }

    public function testTheGateBuildsRunPlainProgressUnderAPresenceProbe(): void
    {
        $raw = (string) file_get_contents(self::SMOKE_SCRIPT);

        // Every image build the gate performs must carry the progress flag…
        self::assertSame(
            3,
            preg_match_all('/\$DOCKER build "\$\{BUILD_PROGRESS\[@\]\}"/', $raw),
            'a $DOCKER build escaped plain progress (expect: base + from-base + direct = 3)'
        );
        // …and the flag must be probed, not assumed: plain `docker` has --progress,
        // a future non-BuildKit $DOCKER would hard-fail on an unprobed flag.
        self::assertMatchesRegularExpression(
            '/\$DOCKER build --help 2>\/dev\/null \| grep -q -F -- \'--progress\'/',
            $raw,
            'the --progress capability probe was removed — the gate would break on'
                . ' a docker without it (or the flag was dropped from the builds).'
        );
    }

    /**
     * Extract the brace-balanced body of a top-level shell function.
     * Brace counting on text containing `$( ... )`, quoted braces and
     * `${VAR}` expansions is fragile; we bound it to the two functions we
     * inspect by requiring the opening line to be exactly `name() {`.
     */
    private static function functionBodyOf(string $raw, string $name): string
    {
        $start = preg_match('/^' . preg_quote($name, '/') . '\(\)\s*\{/m', $raw, $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $start, "function {$name}() not found at line start");

        $i = (int) $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($raw);
        while ($i < $len && $depth > 0) {
            $ch = $raw[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }
        self::assertSame(0, $depth, "unbalanced braces extracting {$name}()");

        return substr($raw, (int) $m[0][1], $i - (int) $m[0][1]);
    }
}
