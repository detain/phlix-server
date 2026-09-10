<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S304 — the extension gate must fail LOUDLY, and must stay WIRED.
 *
 * ## Why this exists
 *
 * `scripts/assert-php-extensions.php` is the executed assertion behind every CI
 * `Setup PHP` step: naming extensions in setup-php's `extensions:` list is not a
 * gate, because `fail_fast` defaults to false and a failed install is still a
 * green step. The script closes that gap — but per S146/S183/S305 doctrine, a
 * gate without an executed failure-path test is exactly the no-op-gate class this
 * repo keeps re-finding. This file pins both halves:
 *
 * 1. the script's behaviour — clean run exits 0; the documented negative-control
 *    knob reddens with a `::error::` annotation; every malformed argument form is
 *    rejected loudly rather than read as "no extras";
 * 2. the wiring — the step exists in all SEVEN server-source jobs (three in
 *    phpunit.yml, three in coding-standards.yml, one in syncplay-e2e.yml), no
 *    call site is neutered with `|| true` / `continue-on-error`, every
 *    `setup-php` use is SHA-pinned (no floating `@v2` remains), and the two
 *    composer-only jobs carry their written justification.
 *
 * The survival token deliberately does NOT appear in this file: premerge counts
 * its occurrences in the tokenized corpus, and it must stay exactly 1 (the const
 * in the script under test).
 */
final class PhpExtensionAssertGateTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const SCRIPT = self::REPO . '/scripts/assert-php-extensions.php';

    private const CONTRACT = self::REPO . '/scripts/required-php-extensions.php';

    private const PHPUNIT_YML = self::REPO . '/.github/workflows/phpunit.yml';

    private const STANDARDS_YML = self::REPO . '/.github/workflows/coding-standards.yml';

    private const SYNCPLAY_YML = self::REPO . '/.github/workflows/syncplay-e2e.yml';

    private const STEP_RUN = 'run: php scripts/assert-php-extensions.php';

    private const STEP_NAME = 'Assert required PHP extensions loaded (S304)';

    private const SETUP_PHP_PIN = 'uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240';

    /**
     * The gate speaks: on a runtime that satisfies the contract (every CI leg that
     * runs this suite installs exactly the contract first), the bare invocation
     * exits 0 and reports the full set it checked.
     */
    public function testCleanRunExitsZeroAndReportsTheContract(): void
    {
        $result = $this->runScript([]);

        self::assertSame(
            0,
            $result['exit'],
            'The gate must pass on a fully provisioned runtime; got: ' . $result['output'],
        );
        self::assertStringContainsString('Required PHP extensions: all ', $result['output']);
        self::assertStringNotContainsString('::error::', $result['output']);
    }

    /**
     * The AC's forced-flip proof, made permanent: the documented
     * negative-control knob must redden the job and name the missing extension.
     * If this ever exits 0, the gate is measuring nothing.
     */
    public function testNegativeControlKnobReddensAndNamesTheAbsentExtension(): void
    {
        $result = $this->runScript(['--require=this_ext_does_not_exist']);

        self::assertSame(1, $result['exit'], 'Absent extension must exit 1; got: ' . $result['output']);
        self::assertStringContainsString('::error::', $result['output']);
        self::assertStringContainsString('this_ext_does_not_exist', $result['output']);
        // The failure must teach, not just burn: the sodium/hash callout rides along.
        self::assertStringContainsString('executed assertion', $result['output']);
    }

    /**
     * Argument hygiene precedes any green: empty, bare and unknown argument forms
     * all exit 1, so a typo in a workflow step can never read as "no extras".
     *
     * @dataProvider malformedArgumentForms
     *
     * @param list<string> $args
     */
    public function testMalformedArgumentsAreRejectedLoudly(array $args, string $expectedInOutput): void
    {
        $result = $this->runScript($args);

        self::assertSame(1, $result['exit'], 'Expected exit 1 for ' . implode(' ', $args) . '; got: ' . $result['output']);
        self::assertStringContainsString($expectedInOutput, $result['output']);
        self::assertStringNotContainsString(
            'Required PHP extensions: all',
            $result['output'],
            'A rejected invocation must never print the success line.',
        );
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function malformedArgumentForms(): array
    {
        return [
            'bare --require'            => [['--require'], 'needs a value'],
            'empty --require='          => [['--require='], 'needs a value'],
            'whitespace --require='     => [['--require=   '], 'empty value'],
            'typo --requires=x'         => [['--requires=swoole'], 'Unrecognised argument'],
            'stray positional'          => [['swoole'], 'Unrecognised argument'],
        ];
    }

    /**
     * Wiring pin (cf. WorkflowToolGateTest): every step site must EXIST, and the
     * per-file counts are the evidence that all seven server-source jobs carry it.
     * The composer-only jobs are excluded on purpose and carry justification
     * comments — both of those facts are pinned here too, so neither the coverage
     * nor the exclusions can silently drift.
     */
    public function testGateIsWiredIntoEveryServerSourceJobAndNotNeutered(): void
    {
        $files = [
            'phpunit.yml'          => [self::PHPUNIT_YML, 3],
            'coding-standards.yml' => [self::STANDARDS_YML, 3],
            'syncplay-e2e.yml'     => [self::SYNCPLAY_YML, 1],
        ];

        $seenJustifications = 0;

        foreach ($files as $name => [$path, $expectedSteps]) {
            $raw = (string) file_get_contents($path);

            self::assertSame(
                $expectedSteps,
                substr_count($raw, self::STEP_RUN),
                sprintf('%s must carry exactly %d executed extension assertions.', $name, $expectedSteps),
            );
            self::assertSame(
                $expectedSteps,
                substr_count($raw, self::STEP_NAME),
                sprintf('%s: every assertion step must carry the S304 step name.', $name),
            );

            foreach (explode("\n", $raw) as $line) {
                if (str_contains($line, 'assert-php-extensions.php')) {
                    self::assertStringNotContainsString('|| true', $line, $name . ': verdict must own its exit code');
                    self::assertStringNotContainsString('continue-on-error', $line, $name);
                }
                if (str_contains($line, 'Assert required PHP extensions loaded')) {
                    self::assertStringNotContainsString('if:', $line, $name . ': a conditional step is a silent step');
                }
            }

            $seenJustifications += substr_count($raw, 'deliberately NO `extensions:` list');
        }

        self::assertSame(2, $seenJustifications, 'Both composer-only jobs must carry the written exclusion.');
    }

    /**
     * The supply-chain half of the step: the tool that decides which extensions
     * exist must itself be pinned. Nine uses, all at the v2.37.2 commit; zero
     * floating tags anywhere in .github/workflows/.
     */
    public function testEverySetupPhpUseIsShaPinned(): void
    {
        $pinTotal  = 0;
        $dir       = self::REPO . '/.github/workflows';
        $workflows = glob($dir . '/*.yml') ?: [];

        self::assertNotEmpty($workflows);

        foreach ($workflows as $path) {
            $raw    = (string) file_get_contents($path);
            $pinTotal += substr_count($raw, self::SETUP_PHP_PIN);
            self::assertStringNotContainsString(
                'uses: shivammathur/setup-php@v',
                $raw,
                basename($path) . ' still floats a setup-php tag.',
            );
        }

        self::assertSame(9, $pinTotal, 'All nine setup-php uses (5+3+1) must sit on the pinned commit.');
    }

    /**
     * Contract consumption pin: the script must `require` the single S314 list and
     * must not smuggle a second required-ext set into its own body. sodium/hash
     * are allowed as MESSAGE labels (SECURITY_CRITICAL), never as a required list.
     */
    public function testScriptConsumesTheContractAndCarriesNoSecondList(): void
    {
        $source = $this->stripPhpComments((string) file_get_contents(self::SCRIPT));

        self::assertStringContainsString("require \$contractPath", $source);
        self::assertStringContainsString("'/required-php-extensions.php'", $source);
        self::assertFileExists(self::CONTRACT);

        // No literal extension list beyond the SECURITY_CRITICAL messaging array:
        // exactly one array of bare extension-name strings may exist in the code.
        preg_match_all('/\[\s*\'[a-z_]+\'\s*,\s*\'[a-z_]+\'\s*(?:,\s*\'[a-z_]+\'\s*)*\]/', $source, $matches);
        $extArrays = array_values(array_filter(
            $matches[0],
            static fn (string $m): bool => (bool) preg_match('/sodium|hash|curl|json/', $m),
        ));

        self::assertCount(1, $extArrays, 'Only the SECURITY_CRITICAL message labels may list extensions: ' . implode(' | ', $extArrays));
        self::assertSame("['sodium', 'hash']", $extArrays[0]);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, output: string}
     */
    private function runScript(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SCRIPT);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $command .= ' 2>&1';

        $output   = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        return ['exit' => $exitCode, 'output' => implode("\n", $output)];
    }

    private function stripPhpComments(string $source): string
    {
        $out     = '';
        $tokens  = token_get_all($source);

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
