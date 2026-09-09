<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * S187 — the whole-tree unused-import guard.
 *
 * Every `use` import in the PHP estate (the same file set the S427 census
 * counts: all `*.php` below the repository root except `vendor/` and
 * `node_modules/` segments) must be referenced somewhere in its own file
 * outside its import statement. This gate exists because 150 such imports
 * accumulated silently across src/, tests/ and scripts/ before S187 removed
 * them: no CI leg checked (the vendored phpcs has no Namespaces sniffs and
 * composer.json cannot churn — R3), so the invariant now lives as a phpunit
 * gate and rides the existing PHPUnit step.
 *
 * Conservative by design (ruling R5): a short name that appears ONLY in a
 * docblock or comment still counts as USED. PHPStan level 9 and Psalm resolve
 * FQCNs inside docblocks through these imports, and inline suppressions lean
 * on them; deleting such an import would redden or silently mis-resolve the
 * analyser legs. The gate therefore reports strictly fewer imports than a
 * token-only analyser would — never more than are safe to delete.
 *
 * The mutation tests below prove detection rather than argue it: they copy
 * real src/ and tests/ fixtures into a temp tree, plant a genuinely unused
 * import, and require this scanner to report it red. An empty tree is a
 * scanner wiring failure, not a pass (S345 floor rule).
 */
final class UnusedImportGuardTest extends TestCase
{
    /** Lane survival token (S187). Code-resident by design; never prose. */
    public const string SURVIVAL_TOKEN = 'S187REFLOWSTYLEX5W9';

    /** Path segments excluded from the estate — identical to the census. */
    private const EXCLUDED_SEGMENTS = ['vendor', 'node_modules', '.git'];

    /** Roots whose estate must be unused-import-free at all times. */
    private const GUARDED_ROOTS = ['src', 'tests', 'scripts', 'config', 'migrations', 'public', 'examples'];

    public function testEveryImportInTheEstateIsReferencedInItsFile(): void
    {
        $root = \dirname(__DIR__, 3);
        $rows = [];
        $scanned = 0;
        foreach (self::GUARDED_ROOTS as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_dir($abs)) {
                continue;
            }
            foreach (self::phpFiles($abs) as $file) {
                $scanned++;
                $rows = array_merge($rows, self::unusedImportsInFile($file, $root));
            }
        }
        $this->assertGreaterThan(1000, $scanned, 'guard ran on a partial tree — estate missing?');
        $this->assertSame([], $rows, "unused imports found:\n" . implode("\n", $rows));
    }

    public function testTopLevelEstatePhpFilesAreGuarded(): void
    {
        $root = \dirname(__DIR__, 3);
        $rows = [];
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $rows = array_merge($rows, self::unusedImportsInFile($file, $root));
        }
        $this->assertSame([], $rows, "unused imports found:\n" . implode("\n", $rows));
    }

    public function testPlantedUnusedImportInASrcFixtureIsDetected(): void
    {
        $row = $this->plantAndScan(\dirname(__DIR__, 3) . '/src/Media/Metadata/Rating.php', 'GhostKlassQq');
        $this->assertNotNull($row, 'planted unused src-style import must be reported');
        $this->assertStringContainsString('GhostKlassQq', (string) $row['stmt']);
    }

    public function testPlantedUnusedImportInATestsFixtureIsDetected(): void
    {
        $fixture = \dirname(__DIR__, 3) . '/tests/Unit/Support/FixtureIdGeneratorTest.php';
        $row = $this->plantAndScan($fixture, 'GhostKlassQq');
        $this->assertNotNull($row, 'planted unused tests-style import must be reported');
        $this->assertStringContainsString('GhostKlassQq', (string) $row['stmt']);
    }

    public function testDocblockOnlyReferenceCountsAsUsedAndIsNotReported(): void
    {
        $fixture = \dirname(__DIR__, 3) . '/src/Media/Metadata/Rating.php';
        $tmpDir = $this->makeTempDir();
        try {
            $src = (string) file_get_contents($fixture);
            $planted = preg_replace(
                '/^(namespace [^;]+;)$/m',
                "$1\n\nuse Planted\\Unused\\DocRefKlassXq;\n\n/** @see DocRefKlassXq */",
                $src,
                1
            );
            $this->assertNotSame($src, $planted, 'fixture must contain a namespace line to plant after');
            file_put_contents($tmpDir . '/planted.php', (string) $planted);
            foreach (self::phpFiles($tmpDir) as $file) {
                $this->assertSame(
                    [],
                    self::scanSource((string) file_get_contents($file)),
                    'docblock-only import must count as used'
                );
            }
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    public function testEmptyTreeFailsFastInsteadOfReportingClean(): void
    {
        $tmpDir = $this->makeTempDir();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no .php files');
            self::assertNonEmptyScanResult(self::phpFiles($tmpDir), $tmpDir);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    // ── scanner ──────────────────────────────────────────────────────────────

    /**
     * @return list<string> one row per unused import: "relative/path.php:123  use ...;"
     */
    private static function unusedImportsInFile(string $file, string $root): array
    {
        $rows = [];
        foreach (self::scanSource((string) file_get_contents($file)) as $hit) {
            $rows[] = substr($file, strlen($root) + 1) . ':' . $hit['line'] . '  ' . $hit['stmt'];
        }
        return $rows;
    }

    /**
     * @param list<string> $files
     */
    private static function assertNonEmptyScanResult(array $files, string $where): void
    {
        if ($files === []) {
            throw new RuntimeException("scanner wired to a tree with no .php files: $where");
        }
    }

    /**
     * Tokenise one file and report every depth-0 import whose effective short
     * name never occurs as a bare word (code token, or word in a comment)
     * outside its own import statement.
     *
     * @return list<array{line:int, stmt:string}>
     */
    private static function scanSource(string $src): array
    {
        /** @var list<array{0:int|string,1:string,2:int}> $norm */
        $norm = [];
        $line = 1;
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                $norm[] = [$t[0], $t[1], $t[2]];
                $line = $t[2] + substr_count($t[1], "\n");
            } else {
                $norm[] = [$t, $t, $line];
            }
        }
        $n = count($norm);

        $imports = [];
        $importSpan = [];
        $depth = 0;
        for ($i = 0; $i < $n; $i++) {
            $id = $norm[$i][0];
            if ($id === '{') {
                $depth++;
                continue;
            }
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                // string interpolation opens via these tokens but closes with a
                // plain '}'; counting them keeps the depth pairing exact.
                $depth++;
                continue;
            }
            if ($id === '}') {
                $depth--;
                continue;
            }
            if ($id !== T_USE || $depth !== 0) {
                continue;
            }
            $j = $i;
            $gdepth = 0;
            while ($j < $n) {
                if ($norm[$j][0] === '{') {
                    $gdepth++;
                } elseif ($norm[$j][0] === '}') {
                    $gdepth--;
                } elseif ($norm[$j][0] === ';' && $gdepth === 0) {
                    break;
                }
                $j++;
            }
            $end = min($j, $n - 1);
            $stmt = '';
            for ($k = $i; $k <= $end; $k++) {
                $stmt .= $norm[$k][1];
            }
            $names = self::parseImportNames($norm, $i, $end);
            if ($names !== null) {
                $imports[] = [
                    'names' => $names,
                    'line' => $norm[$i][2],
                    'stmt' => preg_replace('/\s+/', ' ', trim($stmt)),
                ];
                for ($k = $i; $k <= $end; $k++) {
                    $importSpan[$k] = true;
                }
            }
            $i = $end;
        }
        if ($imports === []) {
            return [];
        }

        $usedName = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset($importSpan[$i])) {
                continue;
            }
            $id = $norm[$i][0];
            if ($id === T_STRING) {
                $usedName[strtolower($norm[$i][1])] = true;
            } elseif ($id === T_DOC_COMMENT || $id === T_COMMENT) {
                foreach ($imports as $imp) {
                    foreach ($imp['names'] as $short => $_) {
                        if (preg_match('/\b' . preg_quote((string) $short, '/') . '\b/', $norm[$i][1])) {
                            $usedName[strtolower((string) $short)] = true;
                        }
                    }
                }
            }
        }

        $hits = [];
        foreach ($imports as $imp) {
            foreach ($imp['names'] as $short => $_) {
                if (!isset($usedName[strtolower((string) $short)])) {
                    $hits[] = ['line' => $imp['line'], 'stmt' => (string) $imp['stmt']];
                }
            }
        }
        return $hits;
    }

    /**
     * Names imported by the use statement spanning [$start, $end].
     * Returns null when the statement shape is not recognised (fail-safe:
     * an unparsed import is never reported as unused).
     *
     * @param list<array{0:int|string,1:string,2:int}> $norm
     * @return array<string,string>|null  short name => display
     */
    private static function parseImportNames(array $norm, int $start, int $end): ?array
    {
        $names = [];
        $pending = '';
        $alias = null;
        for ($i = $start + 1; $i <= $end; $i++) {
            $id = $norm[$i][0];
            $text = $norm[$i][1];
            if ($id === T_WHITESPACE || $id === T_FUNCTION || $id === T_CONST || $id === T_NS_SEPARATOR) {
                continue;
            }
            if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_STRING) {
                $pending = $pending === '' ? $text : $pending . '\\' . $text;
                continue;
            }
            if ($id === T_AS) {
                for ($i++; $i <= $end; $i++) {
                    if ($norm[$i][0] !== T_WHITESPACE) {
                        break;
                    }
                }
                $alias = $norm[$i][1];
                continue;
            }
            if ($id === '{') {
                $pending = '';
                $alias = null;
                continue;
            }
            if ($id === ',' || $id === ';' || $id === '}') {
                if ($pending !== '') {
                    $short = $alias ?? self::lastSegment($pending);
                    $names[$short] = ($alias !== null ? 'use ' . $pending . ' as ' . $alias : 'use ' . $pending) . ';';
                }
                $pending = '';
                $alias = null;
                continue;
            }
            return null;
        }
        return $names === [] ? null : $names;
    }

    private static function lastSegment(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    // ── filesystem helpers ───────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        self::assertNonEmptyScanResult(glob($dir . '/*') ?: [], $dir);
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (\SplFileInfo $f): bool {
                    if ($f->isDir()) {
                        return !in_array($f->getFilename(), self::EXCLUDED_SEGMENTS, true);
                    }
                    return $f->getExtension() === 'php';
                }
            )
        );
        foreach ($it as $file) {
            $out[] = (string) $file->getPathname();
        }
        sort($out);
        self::assertNonEmptyScanResult($out, $dir);
        return $out;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/s187-guard-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));
        return $dir;
    }

    /**
     * @return array{line:int, stmt:string}|null
     */
    private function plantAndScan(string $fixture, string $marker): ?array
    {
        $tmpDir = $this->makeTempDir();
        try {
            $src = (string) file_get_contents($fixture);
            $planted = preg_replace(
                '/^(namespace [^;]+;)$/m',
                "$1\n\nuse Planted\\Unused\\$marker;",
                $src,
                1
            );
            $this->assertNotSame($src, $planted, "fixture must contain a namespace line to plant after: $fixture");
            file_put_contents($tmpDir . '/planted.php', (string) $planted);
            $rows = [];
            foreach (self::phpFiles($tmpDir) as $file) {
                $rows = array_merge($rows, self::scanSource((string) file_get_contents($file)));
            }
            foreach ($rows as $row) {
                if (str_contains((string) $row['stmt'], $marker)) {
                    return $row;
                }
            }
            return null;
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($dir);
    }
}
