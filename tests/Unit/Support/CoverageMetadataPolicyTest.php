<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * S141 — the coverage-metadata policy, enforced.
 *
 * ## The defect
 *
 * PHPUnit narrows a test's RECORDED coverage to whatever its coverage metadata names,
 * and throws the rest away without a word. Measured on this tree (PHP 8.3.6 + PCOV
 * 1.0.11, the CI invocation, real MySQL 8.0, one random seed, the same tree twice with
 * only the 603 annotation lines removed on the second pass — both runs Tests 8971,
 * Skipped 7, identical failures):
 *
 * | Clover metric      | with metadata     | without           |
 * | ------------------ | ----------------- | ----------------- |
 * | statements         | 65.49 %           | **67.22 %**       |
 * | files at 0.00 %    | 143               | **101**           |
 * | files that LOST    | —                 | **0**             |
 *
 * 42 of those 143 zeros — 29 % — were files the suite executes anyway; six of them run
 * in FULL. "Untested" and "attributed elsewhere by an annotation" were
 * indistinguishable in the report, and the natural reaction to a 0 % file is to write
 * duplicate tests for behaviour that is already covered.
 *
 * The mechanism is in `php-code-coverage`'s
 * {@see \SebastianBergmann\CodeCoverage\CodeCoverage} `applyCoversAndUsesFilter()`:
 * `false` (from a covers-nothing marker) CLEARS the whole run's data for that test, a
 * non-empty list keeps only the named units' listed lines and DELETES every other
 * file, and `[]` (no metadata at all) keeps everything. None of the three warns.
 *
 * ## The policy
 *
 * No coverage metadata in `tests/`, in any spelling. `phpunit.xml` carries the
 * authoritative statement of how to read the numbers; this test is what stops that
 * statement from quietly becoming false again.
 *
 * ## Why the needles are assembled instead of written out
 *
 * A detector whose own prose matches its rule reports itself. That happened for real
 * in this repo on 2026-08-02: an S105 guard counted `substr_count('$this->middleware(')`
 * and failed on its own commit, because the docblock that same commit added mentioned
 * the call in prose. So every needle below is concatenated at runtime and
 * {@see testTheGuardsOwnSourceCannotSatisfyItsOwnRule} proves this file contains none
 * of them literally — which is also why the tables above spell things "metadata" and
 * "annotation" rather than naming the tags.
 */
final class CoverageMetadataPolicyTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const PHPUNIT_XML = self::REPO . '/phpunit.xml';

    /**
     * Files permitted to still carry coverage metadata, each with the reason.
     *
     * A concurrent writer owned this file (uncommitted) in this shared checkout when
     * the policy landed on 2026-08-03, and two writers must not edit one file. The
     * entry is NOT a permanent carve-out: {@see testEveryAllowedExceptionIsStillNeeded}
     * fails as soon as the annotation is gone, so the exception has to be deleted
     * rather than rot into a hole the policy no longer notices.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'tests/Unit/Server/Http/Controllers/Admin/AdminSettingsControllerTest.php' =>
            'owned by a concurrent writer when S141 landed (2026-08-03)',
    ];

    /**
     * Every spelling PHPUnit 10.5 accepts, assembled so this file is not its own hit.
     *
     * The doc-comment tags come from `Metadata/Parser/AnnotationParser.php`; the
     * attributes from `PHPUnit\Framework\Attributes\*`. `Uses*` is deliberately in the
     * list too: `linesToBeUsed()` only ever WIDENS an existing narrowing, so a `uses`
     * marker with no `covers` marker is inert — but it is also a strong signal that
     * someone is reaching for the mechanism this policy retired.
     *
     * ⚠ The third pattern allows a leading backslash and the fully-qualified
     * `PHPUnit\Framework\Attributes` prefix. That is not defensive padding: the first
     * version of this guard matched the plain short form only, and its own positive
     * control ({@see testTheScannerActuallyDetectsEverySpelling}) failed on the
     * fully-qualified attribute spelling — a legal, working form the scan would have
     * walked straight past while reporting a confident zero. A detector narrower than
     * its rule fakes a clean result; that is why the control exists and why it builds
     * that case (`attr-fqcn`) instead of trusting the short form to stand for it.
     *
     * ⚠ And the reason this paragraph does not simply QUOTE the offending spelling is
     * that the first attempt did, and {@see testNoTestFileCarriesCoverageMetadata}
     * immediately reported this file as an offender at the docblock line. Two guards in
     * this repo have now failed on their own documentation. Describe the syntax; do not
     * write it.
     *
     * @return list<non-empty-string> PCRE patterns
     */
    private function needles(): array
    {
        $at = '@';

        return [
            '/' . $at . 'covers(?:Nothing|DefaultClass)?\b/',
            '/' . $at . 'uses(?:DefaultClass)?\b/',
            '/#\[\s*\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?(?:Covers|Uses)'
                . '(?:Class|Method|Function|Nothing)?\b/',
        ];
    }

    /**
     * @return list<string> repo-relative paths of every PHP file under tests/
     */
    private function testTreeFiles(): array
    {
        $root = realpath(self::REPO);
        self::assertIsString($root);

        $found = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $found[] = ltrim(str_replace($root, '', $entry->getPathname()), '/');
        }

        sort($found);

        return $found;
    }

    /**
     * @param list<non-empty-string> $needles
     *
     * @return list<string> "path:line" for each hit
     */
    private function scan(string $relPath, array $needles): array
    {
        $abs = realpath(self::REPO) . '/' . $relPath;
        $lines = file($abs, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, $relPath . ' could not be read');

        $hits = [];
        foreach ($lines as $i => $line) {
            foreach ($needles as $needle) {
                if (preg_match($needle, $line) === 1) {
                    $hits[] = $relPath . ':' . ($i + 1);
                    break;
                }
            }
        }

        return $hits;
    }

    public function testNoTestFileCarriesCoverageMetadata(): void
    {
        $needles = $this->needles();
        $offenders = [];
        $scanned = 0;

        foreach ($this->testTreeFiles() as $rel) {
            if (isset(self::ALLOWED[$rel])) {
                continue;
            }

            $scanned++;
            foreach ($this->scan($rel, $needles) as $hit) {
                $offenders[] = $hit;
            }
        }

        // Anti-vacuity: if the walk found nothing to look at, "no offenders" is
        // meaningless. The tree has ~750 files; 500 is a floor, not a target.
        self::assertGreaterThan(
            500,
            $scanned,
            'the tests/ walk scanned almost nothing, so a clean result proves nothing',
        );

        self::assertSame(
            [],
            $offenders,
            "S141: coverage metadata is not permitted in tests/ — it silently DISCARDS\n"
            . "every other file the test executes, which put 42 executed files at 0.00%%\n"
            . "in this repo's report. Read the policy in phpunit.xml. Delete the marker;\n"
            . "do not add the file to this test's allow-list.\nOffending lines:\n  "
            . implode("\n  ", $offenders),
        );
    }

    public function testEveryAllowedExceptionIsStillNeeded(): void
    {
        $needles = $this->needles();

        foreach (self::ALLOWED as $rel => $why) {
            self::assertFileExists(realpath(self::REPO) . '/' . $rel, $rel);

            self::assertNotSame(
                [],
                $this->scan($rel, $needles),
                sprintf(
                    "%s no longer carries coverage metadata, so its exception (%s) is "
                    . 'stale. DELETE the entry from CoverageMetadataPolicyTest::ALLOWED '
                    . 'and from the "ONE DOCUMENTED EXCEPTION" note in phpunit.xml — a '
                    . 'carve-out nobody needs is a hole the policy stops noticing.',
                    $rel,
                    $why,
                ),
            );
        }
    }

    public function testTheGuardsOwnSourceCannotSatisfyItsOwnRule(): void
    {
        $self = 'tests/' . str_replace(
            '\\',
            '/',
            substr(self::class, strlen('Phlix\\Tests\\')),
        ) . '.php';

        self::assertSame(
            [],
            $this->scan($self, $this->needles()),
            'this guard must not contain its own needles literally, or it reports itself '
            . 'and the next author "fixes" the guard instead of the code',
        );
    }

    /**
     * The two settings that would make a MISSING annotation a test failure.
     *
     * S120 learned this the hard way: reasoning from "the attribute is absent,
     * therefore the behaviour is off" was wrong in both halves, because
     * `failOnPhpunitWarning` DEFAULTS to true. So assert the default as well as the
     * absence. `phpunit.xsd:179,203` declare `default="false"` for both, and
     * `TextUI/Configuration/Xml/Loader.php:795,803` initialise both to `false`
     * — including the legacy aliases, which the same loader still honours.
     */
    public function testPhpunitXmlDoesNotRequireCoverageMetadata(): void
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->load(self::PHPUNIT_XML), 'phpunit.xml must parse');

        $root = $doc->documentElement;
        self::assertNotNull($root);

        foreach (
            [
                'requireCoverageMetadata',
                'forceCoversAnnotation',
                'beStrictAboutCoverageMetadata',
                'beStrictAboutCoversAnnotation',
            ] as $attribute
        ) {
            self::assertFalse(
                $root->hasAttribute($attribute),
                sprintf(
                    'phpunit.xml must not set %s: it turns "this test names no unit" into '
                    . 'a risky-test failure, which is direct pressure to re-add the '
                    . 'annotations S141 removed. Both settings default to false '
                    . '(phpunit.xsd:179,203).',
                    $attribute,
                ),
            );
        }

        $xsd = file_get_contents(self::REPO . '/vendor/phpunit/phpunit/phpunit.xsd');
        self::assertIsString($xsd);
        self::assertStringContainsString(
            '<xs:attribute name="requireCoverageMetadata" type="xs:boolean" default="false"/>',
            $xsd,
            'the installed PHPUnit no longer defaults requireCoverageMetadata to false, '
            . 'so absence is no longer proof it is off — re-derive this assertion',
        );
    }

    /**
     * The authoritative reading lives in exactly one place, and must stay there.
     *
     * Without this, the policy comment is a comment: deletable in a drive-by edit,
     * with nothing to notice. The three fragments are the load-bearing claims, not
     * decoration — the percentages, the ban, and the name of this test.
     */
    public function testPhpunitXmlStillStatesTheAuthoritativeReading(): void
    {
        $xml = file_get_contents(self::PHPUNIT_XML);
        self::assertIsString($xml);

        foreach (
            [
                'S141 — HOW TO READ THIS REPO\'S COVERAGE NUMBERS',
                'A 0.00% file is a file the suite NEVER EXECUTES',
                'CoverageMetadataPolicyTest',
            ] as $fragment
        ) {
            self::assertStringContainsString(
                $fragment,
                $xml,
                'phpunit.xml is the ONE authoritative statement of how to read this '
                . 'repo\'s coverage numbers (S141). Do not delete it; if the policy '
                . 'changes, rewrite it and update this assertion in the same commit.',
            );
        }
    }

    /**
     * Positive control: the scanner must be able to FIND metadata, or every green
     * result above is green because the detector is broken.
     *
     * Cf. the repo's own history of detectors that were narrower than their rule and
     * so reported a confident zero.
     */
    public function testTheScannerActuallyDetectsEverySpelling(): void
    {
        $dir = sys_get_temp_dir() . '/s141-scan-' . bin2hex(random_bytes(6));
        mkdir($dir . '/tests', 0o700, true);

        $at = '@';
        $samples = [
            'doc-covers'        => " * " . $at . "covers \\Phlix\\Server\\Http\\Router",
            'doc-covers-method' => " * " . $at . "covers \\Phlix\\X::y",
            'doc-covers-nothing' => " * " . $at . "coversNothing",
            'doc-covers-default' => " * " . $at . "coversDefaultClass \\Phlix\\X",
            'doc-uses'          => " * " . $at . "uses \\Phlix\\X",
            'attr-covers-class' => '#[' . 'CoversClass(Router::class)]',
            'attr-covers-fn'    => '#[' . 'CoversFunction(\'strlen\')]',
            'attr-covers-none'  => '#[' . 'CoversNothing]',
            'attr-uses-class'   => '#[' . 'UsesClass(Router::class)]',
            'attr-fqcn'         => '#[' . '\\PHPUnit\\Framework\\Attributes\\CoversClass(X::class)]',
        ];

        $needles = $this->needles();
        $missed = [];

        try {
            foreach ($samples as $label => $line) {
                $file = $dir . '/tests/Probe.php';
                file_put_contents($file, "<?php\n/**\n" . $line . "\n */\nclass Probe {}\n");

                $hit = false;
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $ln) {
                    foreach ($needles as $needle) {
                        if (preg_match($needle, $ln) === 1) {
                            $hit = true;
                            break 2;
                        }
                    }
                }

                if (!$hit) {
                    $missed[] = $label;
                }
            }

            // Negative control: prose that merely TALKS about the mechanism, and a
            // plain test file, must not match — a rule that fires on its own
            // documentation gets deleted rather than obeyed.
            $clean = $dir . '/tests/Clean.php';
            file_put_contents(
                $clean,
                "<?php\n/**\n * This test deliberately names no unit; see the S141 policy.\n */\n"
                . "class Clean { public function testX(): void {} }\n",
            );
            $falsePositives = [];
            foreach (file($clean, FILE_IGNORE_NEW_LINES) ?: [] as $ln) {
                foreach ($needles as $needle) {
                    if (preg_match($needle, $ln) === 1) {
                        $falsePositives[] = $ln;
                    }
                }
            }
        } finally {
            foreach ((array) glob($dir . '/tests/*') as $f) {
                if (is_string($f)) {
                    unlink($f);
                }
            }
            rmdir($dir . '/tests');
            rmdir($dir);
        }

        self::assertSame([], $missed, 'the scanner cannot see these spellings');
        self::assertSame([], $falsePositives, 'the scanner fires on prose about itself');
    }
}
