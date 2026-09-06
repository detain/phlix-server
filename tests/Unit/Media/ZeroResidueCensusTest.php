<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\Extension;
use Phlix\Tests\Support\ResidueCensus\ZeroResidueCensusExtension;

/**
 * S439 (finish-S167) — pins the zero-residue census and its wiring.
 *
 * The census extension ({@see ZeroResidueCensusExtension}) fails the suite when a
 * run leaves `phlix_*` entries in the temp dir — the exact defect class audit31
 * measured at 212 residue entries (`steps/97-triage/deep-audit/S167.md`). Like
 * S120's guard before it, the census is one deletable `phpunit.xml` line away from
 * being silently decorative, so this test pins the registration against the PARSED
 * document (a commented-out line is not an element), proves the registered class is
 * a real PHPUnit extension, and exercises the diff itself — planted entries are
 * found, the pre-run baseline excludes pre-existing dirt, clean dirs yield nothing.
 *
 * The scratch dir deliberately uses an `s439-census-` prefix rather than `phlix_`
 * (same choice as `AssertionEscapeGuardWiringTest`'s `s120-wiring-` scratch): the
 * top-level census glob must never be able to frame this test's own scaffolding for
 * residue — though the tearDown removes it either way.
 */
final class ZeroResidueCensusTest extends TestCase
{
    private const REPO = __DIR__ . '/../../..';

    private const PHPUNIT_XML = self::REPO . '/phpunit.xml';

    private const EXTENSION_CLASS = ZeroResidueCensusExtension::class;

    // Merge-lane canary (P-1). Lives in the comment-stripped PHP corpus via the
    // executing assertion below, never in a *.md file. Not a security assertion.
    private const LANE_SENTINEL = 'S439ZERORESIDUEX4F8';

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            foreach ((array) glob($dir . '/phlix_*') as $child) {
                if (is_string($child)) {
                    is_dir($child) ? @rmdir($child) : @unlink($child);
                }
            }

            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        $this->scratchDirs = [];

        parent::tearDown();
    }

    public function testTheCensusFindsPlantedEntriesAndHonoursTheBaseline(): void
    {
        $scratch = sys_get_temp_dir() . '/s439-census-' . bin2hex(random_bytes(6));
        mkdir($scratch, 0o777, true);
        $this->scratchDirs[] = $scratch;

        $plantedFile = $scratch . '/phlix_planted_a';
        $plantedDir = $scratch . '/phlix_planted_b';
        $untouched = $scratch . '/not_phlix_c';
        file_put_contents($plantedFile, 'x');
        mkdir($plantedDir);
        file_put_contents($untouched, 'x');
        $this->scratchDirs[] = $plantedDir;

        $found = ZeroResidueCensusExtension::findResidue($scratch, []);

        $this->assertSame(
            [$plantedFile, $plantedDir],
            $found,
            'A zero-baseline census must name exactly the phlix_* entries present, sorted, '
            . 'files and directories alike — and must ignore every non-phlix_ neighbour.',
        );

        // The same entries in the baseline are pre-run dirt, not suite residue:
        $this->assertSame(
            [],
            ZeroResidueCensusExtension::findResidue($scratch, $found),
            'Entries present before execution began must never be attributed to the suite.',
        );

        @unlink($untouched);
    }

    public function testEmptyTempDirYieldsEmptyCensus(): void
    {
        $scratch = sys_get_temp_dir() . '/s439-census-' . bin2hex(random_bytes(6));
        mkdir($scratch, 0o777, true);
        $this->scratchDirs[] = $scratch;

        $this->assertSame([], ZeroResidueCensusExtension::findResidue($scratch, []));
    }

    /**
     * The silent mutation this closes: delete (or comment out) the census
     * `<bootstrap>` line from phpunit.xml. Checked against the parsed document,
     * same argument as AssertionEscapeGuardWiringTest.
     */
    public function testPhpunitXmlStillRegistersTheZeroResidueCensusExtension(): void
    {
        $document = new \DOMDocument();
        $this->assertTrue(
            $document->load(self::PHPUNIT_XML),
            'phpunit.xml must parse before its contents can be asserted.',
        );

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            sprintf('/phpunit/extensions/bootstrap[@class="%s"]', self::EXTENSION_CLASS),
        );

        $this->assertNotFalse($nodes, 'the XPath query must be valid');
        $this->assertSame(
            1,
            $nodes->length,
            'phpunit.xml must register ' . self::EXTENSION_CLASS . ' as an <extensions><bootstrap> '
            . 'element. Without it the S439 census never runs, and a suite that leaks 212 /tmp/phlix_* '
            . 'entries like audit31 measured passes silently — the exact blindness finish-S167 opened.',
        );
    }

    public function testTheRegisteredExtensionClassExistsAndIsAPhpunitExtension(): void
    {
        $this->assertTrue(
            class_exists(self::EXTENSION_CLASS),
            'phpunit.xml names a bootstrap class that must be autoloadable.',
        );
        $this->assertContains(
            Extension::class,
            class_implements(self::EXTENSION_CLASS) ?: [],
            self::EXTENSION_CLASS . ' must implement ' . Extension::class
            . ' or PHPUnit will refuse to bootstrap it.',
        );
    }

    public function testLaneSentinelIsResidentInCodeAndAbsentFromMarkdown(): void
    {
        // P-1: the sentinel must live in comment-stripped executable PHP (this
        // assertion executes the constant so the literal survives), and in ZERO
        // *.md files — documentation must not carry the canary.
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{19}$/', self::LANE_SENTINEL);

        $stripped = php_strip_whitespace(__FILE__);
        $this->assertIsString($stripped);
        $this->assertStringContainsString(
            self::LANE_SENTINEL,
            $stripped,
            'the sentinel must survive comment stripping — it lives in code, not prose.',
        );

        $directory = new \RecursiveDirectoryIterator(self::REPO, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);

        $offenders = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getPathname());

            foreach (['/vendor/', '/node_modules/', '/.git/'] as $foreign) {
                if (str_contains($relative, $foreign)) {
                    continue 2;
                }
            }

            if (str_contains((string) file_get_contents($file->getPathname()), self::LANE_SENTINEL)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the lane sentinel must appear in zero markdown files. Found in: ' . implode(', ', $offenders),
        );
    }
}
