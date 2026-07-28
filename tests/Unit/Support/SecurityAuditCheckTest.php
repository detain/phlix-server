<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * The security audit must fail when it cannot audit — never skip.
 *
 * ## What went wrong
 *
 * `.github/workflows/coding-standards.yml`'s "Run Security Audit" step was:
 *
 * ```bash
 * if [ -f vendor/bin/security-checker ]; then
 *   ./vendor/bin/security-checker security:check
 * elif [ -f vendor/bin/composer-audit ]; then
 *   ./vendor/bin/composer-audit audit --format=github
 * fi
 * ```
 *
 * Neither binary has ever existed in this repo — `sensiolabs/security-checker`
 * (abandoned upstream) and any `composer-audit` package are both absent from
 * composer.json, while `vendor/bin/` holds phpcs, phpstan and psalm. Both
 * branches were always false, the `if` fell through, the step exited 0, and the
 * "Security Audit" job reported GREEN having audited nothing.
 *
 * This is the third instance of one defect: S146 (Psalm green having analysed
 * zero files) and the coverage-threshold repair (an `xmllint` parse that skipped
 * because xmllint is not installed on ubuntu-latest) are the other two. Every
 * time, a *missing tool* sat behind a conditional that treated absence as
 * success. {@see CoverageThresholdCheckTest} is the sibling of this file.
 *
 * ## What these tests are actually protecting
 *
 * Two things.
 *
 * First, the *failure direction* — every way the audit can fail to run must exit
 * non-zero:
 *
 * | condition                    | old behaviour | required behaviour |
 * | ---------------------------- | ------------- | ------------------ |
 * | tool missing                 | skip, exit 0  | **exit 1**         |
 * | tool too old to audit        | skip, exit 0  | **exit 1**         |
 * | payload missing / empty      | skip, exit 0  | **exit 1**         |
 * | payload unparseable          | skip, exit 0  | **exit 1**         |
 * | payload shape unrecognised   | skip, exit 0  | **exit 1**         |
 * | advisory repo unreachable    | skip, exit 0  | **exit 1**         |
 *
 * {@see testMissingToolFailsInsteadOfSkipping()} is the direct regression test
 * for the defect.
 *
 * Second, and just as important, the *blocking/advisory split*. `composer audit`
 * returns one exit code for two very different findings, and on this repo that
 * conflation bites immediately: `composer audit --locked` exits 1 today with
 * ZERO security advisories, purely because fgrosse/phpasn1 and
 * web-auth/metadata-service are abandoned transitive dependencies nobody here
 * can fix. Trusting that exit code would paint every PR red on day one, and a
 * gate that is red for reasons unrelated to the code gets switched off — which
 * is precisely how the no-op it replaces came to exist.
 *
 * So advisories BLOCK and abandonment is ADVISORY — but loudly, as a real
 * annotation, never as a swallowed exit code.
 * {@see testAbandonedPackagesWarnLoudlyButDoNotBlock()} pins that, and
 * {@see testAdvisoryBlocksEvenWhenAbandonedPackagesArePresent()} pins that the
 * advisory half did not become decorative in the process.
 */
final class SecurityAuditCheckTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/security-audit-check.php';

    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/coding-standards.yml';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // The happy path still works.
    // -----------------------------------------------------------------------

    public function testCleanAuditPasses(): void
    {
        $payload = $this->writePayload(['advisories' => [], 'abandoned' => [], 'filter' => []]);

        $result = $this->runCheck($payload);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('No security advisories affecting locked packages.', $result['output']);
        $this->assertStringContainsString('Security audit passed.', $result['output']);
    }

    /**
     * `composer audit --format=json` emits `[]` for an empty advisory set but a
     * package-keyed object when populated. Both encodings must read as "clean";
     * an object-shaped empty must not be mistaken for a finding.
     */
    public function testEmptyAdvisoriesObjectAlsoPasses(): void
    {
        $payload = $this->tempPath();
        file_put_contents($payload, '{"advisories": {}, "abandoned": {}}');

        $result = $this->runCheck($payload);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('Security audit passed.', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Advisories BLOCK.
    // -----------------------------------------------------------------------

    public function testSecurityAdvisoryFailsTheBuild(): void
    {
        $payload = $this->writePayload([
            'advisories' => ['guzzlehttp/psr7' => [$this->advisory()]],
            'abandoned' => [],
        ]);

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'A known-vulnerable dependency must never pass silently.');
        $this->assertStringContainsString('::error::', $result['output']);
        $this->assertStringContainsString('Security audit FAILED', $result['output']);
        $this->assertStringContainsString('guzzlehttp/psr7', $result['output']);
        $this->assertStringContainsString('CVE-2023-29197', $result['output']);
    }

    /**
     * The advisory half of the split must not swallow the blocking half: an
     * abandoned package sitting alongside a real advisory must still fail.
     */
    public function testAdvisoryBlocksEvenWhenAbandonedPackagesArePresent(): void
    {
        $payload = $this->writePayload([
            'advisories' => ['guzzlehttp/psr7' => [$this->advisory()]],
            'abandoned' => ['fgrosse/phpasn1' => null],
        ]);

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'Abandonment being advisory must not downgrade a real advisory.');
        $this->assertStringContainsString('::warning::', $result['output'], 'The abandoned package is still reported.');
        $this->assertStringContainsString('Security audit FAILED', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Abandonment is ADVISORY — but loud.
    // -----------------------------------------------------------------------

    /**
     * The design decision, stated as a test.
     *
     * This is the exact payload this repo produces today. `composer audit` exits
     * 1 on it; this gate must exit 0 while making the finding impossible to miss
     * in the log. Both halves matter: exit 0 so an unfixable upstream
     * abandonment cannot wedge every PR, and a real `::warning::` annotation so
     * the advisory-ness is explicit rather than hidden in a discarded exit code.
     */
    public function testAbandonedPackagesWarnLoudlyButDoNotBlock(): void
    {
        $payload = $this->writePayload([
            'advisories' => [],
            'abandoned' => [
                'fgrosse/phpasn1' => null,
                'web-auth/metadata-service' => 'web-auth/webauthn-lib',
            ],
            'filter' => [],
        ]);

        $result = $this->runCheck($payload);

        $this->assertSame(0, $result['exit'], 'An unfixable abandoned transitive dep must not block every PR.');
        $this->assertStringContainsString('::warning::', $result['output'], 'Advisory-ness must be LOUD, not implicit.');
        $this->assertStringContainsString('2 abandoned package(s)', $result['output']);
        $this->assertStringContainsString('does NOT fail the build', $result['output']);
        $this->assertStringContainsString('fgrosse/phpasn1 — no replacement suggested', $result['output']);
        $this->assertStringContainsString('web-auth/metadata-service — replaced by web-auth/webauthn-lib', $result['output']);
    }

    public function testIgnoredAdvisoriesAreReportedRatherThanHidden(): void
    {
        $payload = $this->writePayload([
            'advisories' => [],
            'ignored-advisories' => ['guzzlehttp/psr7' => [$this->advisory()]],
            'abandoned' => [],
        ]);

        $result = $this->runCheck($payload);

        $this->assertSame(0, $result['exit'], 'An explicitly acknowledged advisory does not block.');
        $this->assertStringContainsString('::notice::', $result['output']);
        $this->assertStringContainsString('IGNORED', $result['output']);
        $this->assertStringContainsString('CVE-2023-29197', $result['output'], 'An acknowledged advisory is still named.');
    }

    // -----------------------------------------------------------------------
    // Every "cannot audit" path is LOUD.
    // -----------------------------------------------------------------------

    /**
     * The defect, stated as a test.
     *
     * With no payload argument the script must go and run the tool. When the
     * tool is absent that is a FAILURE, not a pass. The old step's answer to
     * this exact condition was to fall through the `if` and exit 0.
     *
     * COMPOSER_BIN points at nothing, so this never touches the network.
     */
    public function testMissingToolFailsInsteadOfSkipping(): void
    {
        $result = $this->runCheck(null, ['COMPOSER_BIN' => '/nonexistent/composer']);

        $this->assertSame(1, $result['exit'], 'A missing audit tool must FAIL the step, not silently skip it.');
        $this->assertStringContainsString('::error::', $result['output']);
        $this->assertStringContainsString('not available', $result['output']);
    }

    /**
     * `composer audit` arrived in Composer 2.4. An older composer would make the
     * command fall over in a way that is easy to mistake for "nothing found", so
     * the version is asserted up front.
     */
    public function testTooOldComposerFails(): void
    {
        $fake = $this->writeFakeComposer('Composer version 2.3.10 2022-01-01 00:00:00');

        $result = $this->runCheck(null, ['COMPOSER_BIN' => $fake]);

        $this->assertSame(1, $result['exit'], 'A composer without `audit` must fail loudly.');
        $this->assertStringContainsString('too old', $result['output']);
    }

    public function testUnparseableVersionOutputFails(): void
    {
        $fake = $this->writeFakeComposer('not a composer at all');

        $result = $this->runCheck(null, ['COMPOSER_BIN' => $fake]);

        $this->assertSame(1, $result['exit'], 'If we cannot prove the tool is composer, we must not audit with it.');
        $this->assertStringContainsString('Could not parse a version', $result['output']);
    }

    public function testMissingPayloadFails(): void
    {
        $result = $this->runCheck(sys_get_temp_dir() . '/phlix-audit-does-not-exist.json');

        $this->assertSame(1, $result['exit'], 'A missing payload must fail the step.');
        $this->assertStringContainsString('does not exist', $result['output']);
    }

    public function testEmptyPayloadFails(): void
    {
        $payload = $this->tempPath();
        file_put_contents($payload, '');

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'An empty payload must fail the step.');
        $this->assertStringContainsString('::error::', $result['output']);
    }

    public function testUnparseableJsonFails(): void
    {
        $payload = $this->tempPath();
        file_put_contents($payload, '{"advisories": [');

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'Malformed JSON must fail the step.');
        $this->assertStringContainsString('not parseable JSON', $result['output']);
    }

    /**
     * A payload with no `advisories` key is not something this gate understands.
     * Reading it as "no advisories found" is the silent-skip defect wearing a
     * different hat.
     */
    public function testPayloadWithoutAdvisoriesKeyFails(): void
    {
        $payload = $this->tempPath();
        file_put_contents($payload, '{"abandoned": {}}');

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'An unrecognised payload shape must fail, not be read as clean.');
        $this->assertStringContainsString('no "advisories" key', $result['output']);
    }

    /**
     * The coverage gate's lesson restated: if the advisory database could not be
     * reached, the audit measured NOTHING, and a gate that cannot measure must
     * not report success.
     */
    public function testUnreachableAdvisoryRepositoryFails(): void
    {
        $payload = $this->writePayload([
            'advisories' => [],
            'unreachable-repositories' => ['https://repo.packagist.org'],
            'abandoned' => [],
        ]);

        $result = $this->runCheck($payload);

        $this->assertSame(1, $result['exit'], 'An audit that reached no advisory source measured nothing.');
        $this->assertStringContainsString('unreachable', $result['output']);
        $this->assertStringContainsString('measured nothing', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Guards against the defect being reintroduced.
    // -----------------------------------------------------------------------

    /**
     * The workflow must not reacquire an `if [ -f vendor/bin/... ]` guard around
     * any gate in this file — not the security audit, and not PHPCS or PHPStan,
     * whose binaries happen to exist today but which had the identical shape.
     *
     * Comments are stripped first, and deliberately so: the workflow *quotes*
     * the broken original in order to explain why it was broken, and a guard
     * that fires on its own documentation is a false positive — which, per the
     * reasoning in {@see IntegrationDbGuardAdoptionTest}, costs more than an
     * escape, because a noisy check gets deleted and then nothing is caught.
     */
    public function testWorkflowHasNoSilentToolGuards(): void
    {
        $yaml = $this->stripYamlComments((string) file_get_contents(self::WORKFLOW));

        $this->assertStringNotContainsString(
            'if [ -f vendor/bin/',
            $yaml,
            'An `if [ -f vendor/bin/... ]` guard turns a missing tool into a silent green. '
            . 'Assert the tool exists and let a missing one FAIL (S146).',
        );
        $this->assertStringNotContainsString(
            'security-checker',
            $yaml,
            'sensiolabs/security-checker is abandoned upstream and has never been installed here. '
            . 'The audit is `composer audit`, built into Composer 2.4+.',
        );
        $this->assertStringNotContainsString(
            'vendor/bin/composer-audit',
            $yaml,
            'No such binary has ever existed in this repo.',
        );
        $this->assertStringContainsString('scripts/security-audit-check.php', $yaml);
    }

    /**
     * Each gate in this workflow must assert its tool before running it.
     */
    public function testEveryGateAssertsItsToolIsInstalled(): void
    {
        $yaml = (string) file_get_contents(self::WORKFLOW);

        foreach (['PHPCS', 'PHPStan', 'Psalm'] as $tool) {
            $this->assertStringContainsString(
                sprintf('Assert %s is actually installed', $tool),
                $yaml,
                sprintf('%s must assert its binary exists rather than skipping when it is absent.', $tool),
            );
        }

        $this->assertStringContainsString('Assert the security audit tool is actually available', $yaml);
    }

    /**
     * No error-swallowing constructs in the checker itself. Comments are
     * stripped for the same reason as above — the script header quotes the
     * original broken `if`/`elif` verbatim so the defect stays legible.
     */
    public function testCheckerHasNoSilentFallback(): void
    {
        $source = $this->stripPhpComments((string) file_get_contents(self::SCRIPT));

        $this->assertStringNotContainsString('2>/dev/null', $source);
        $this->assertStringNotContainsString('|| echo', $source);
        $this->assertSame(
            1,
            substr_count($source, 'exit(0);'),
            'The only exit(0) in the checker must be the single success path at the end.',
        );
    }

    // -----------------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------------

    /**
     * One real advisory, copied from live `composer audit --format=json` output
     * so the fixture cannot drift from the shape composer actually emits.
     *
     * @return array<string, mixed>
     */
    private function advisory(): array
    {
        return [
            'advisoryId' => 'PKSA-hn62-zkx4-1y5q',
            'packageName' => 'guzzlehttp/psr7',
            'affectedVersions' => '>=2.0.0,<2.4.5',
            'title' => 'Improper header validation',
            'cve' => 'CVE-2023-29197',
            'link' => 'https://github.com/guzzle/psr7/security/advisories/GHSA-wxmh-65f7-jcvw',
            'reportedAt' => '2023-04-17T15:03:25+00:00',
            'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-wxmh-65f7-jcvw']],
            'severity' => 'medium',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writePayload(array $payload): string
    {
        $path = $this->tempPath();

        file_put_contents($path, (string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function writeFakeComposer(string $versionLine): string
    {
        $path = $this->tempPath();

        file_put_contents($path, "#!/bin/sh\necho " . escapeshellarg($versionLine) . "\n");
        chmod($path, 0o755);

        return $path;
    }

    /**
     * Drop whole-line `#` comments — the YAML step comments and the shell
     * comments inside `run:` blocks. A genuine reintroduction of a silent guard
     * would be executable code, not a commented line.
     */
    private function stripYamlComments(string $yaml): string
    {
        $kept = array_filter(
            explode("\n", $yaml),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        );

        return implode("\n", $kept);
    }

    private function stripPhpComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function tempPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-audit-');

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{exit: int, output: string}
     */
    private function runCheck(?string $payloadPath, array $env = []): array
    {
        $command = '';

        foreach ($env as $name => $value) {
            $command .= $name . '=' . escapeshellarg($value) . ' ';
        }

        $command .= escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::SCRIPT);

        if ($payloadPath !== null) {
            $command .= ' ' . escapeshellarg($payloadPath);
        }

        $command .= ' 2>&1';

        $output   = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        return ['exit' => $exitCode, 'output' => implode("\n", $output)];
    }
}
