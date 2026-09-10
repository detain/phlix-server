<?php

/**
 * Idempotent copyright-header insertion script for phlix-server.
 *
 * Finds all PHP files under src/ (excluding vendor/, .git/, node_modules/, generated/,
 * .phpunit.cache/)
 * that do not yet carry the @copyright 2026 Joe Huss <detain@interserver.net> line,
 * and inserts a file-level docblock in the required format.
 *
 * Run: php scripts/add-headers.php
 * Run again to verify idempotency (second run = no diff).
 */

declare(strict_types=1);

/**
 * Append every `.php` file under `$dir` to `$files`, pruning excluded subtrees.
 *
 * The exclusion is applied by a {@see RecursiveCallbackFilterIterator}, which is
 * what actually stops the traversal descending. The previous implementation
 * `continue`d on the directory node instead — that skips the directory entry
 * itself, never its children, and directory entries were discarded anyway by the
 * `isFile()` test below. Measured on a fixture tree: it rewrote headers inside
 * `src/generated/` and `src/node_modules/`, the directories this script's own
 * docblock says it excludes. It then called
 * `setFlags(RecursiveIteratorIterator::CATCH_GET_CHILD)` mid-iteration, where the
 * call lands on the INNER `FilesystemIterator` (RecursiveIteratorIterator proxies
 * unknown methods to it), and the value 16 in that flag position is
 * `FilesystemIterator::CURRENT_AS_SELF` — a coincidental equal of the outer
 * iterator's `CATCH_GET_CHILD` — so the call silently changed what the inner
 * iterator yields and caught nothing — PHPStan level 9 reports that as
 * `argument.invalidConstant`.
 *
 * Declared as a closure (estate convention, and the shape the hub's S248 rewrite
 * of this same script shipped) so the file stays free of named functions: a named
 * `collect()` here would collide with the global `collect()` that `crell/fp` ships
 * (arrived via `crell/tukio`'s autoloader), and a named function in a script that also executes logic
 * trips PSR1.Files.SideEffects. The walk itself recurses through
 * {@see RecursiveIteratorIterator}, so the closure needs no self-reference.
 *
 * @param list<string>   $files    Accumulator, appended to in place.
 * @param list<string>   $excludes Directory basenames whose subtrees are skipped.
 */
$collect = /** @param list<string> $files */ static function (string $dir, array &$files, array $excludes): void {
    $pruned = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        static function (mixed $node) use ($excludes): bool {
            if (!$node instanceof SplFileInfo) {
                return false;
            }

            return !$node->isDir() || !in_array($node->getBasename(), $excludes, true);
        }
    );

    $iterator = new RecursiveIteratorIterator($pruned, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $node) {
        if (!$node instanceof SplFileInfo) {
            continue;
        }

        if ($node->isFile() && $node->getExtension() === 'php') {
            $files[] = $node->getPathname();
        }
    }
};

/**
 * Recursively collect all .php files under src/, excluding vendor/, .git/, generated/,
 * node_modules/ and .phpunit.cache/.
 *
 * @return list<string> Absolute file paths.
 */
$getPHPFiles = /** @return list<string> */ static function (string $repoRoot) use ($collect): array {
    $dirs = ['src'];
    $excludes = ['vendor', '.git', 'generated', 'node_modules', '.phpunit.cache'];

    $files = [];
    foreach ($dirs as $dir) {
        $path = $repoRoot . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        $collect($path, $files, $excludes);
    }

    sort($files);
    return $files;
};

/**
 * Best-effort one-line description derived from namespace / class name.
 */
$inferDescription = static function (string $file, string $relativePath): string {
    $content = file_get_contents($file);
    if ($content === false) {
        return 'Phlix media server source file.';
    }

    // Try to extract namespace or class name.
    if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
        $parts = explode('\\', $nsMatch[1]);
        $last = end($parts);
        if ($last !== '') {
            return 'Phlix media server component: ' . $last;
        }
    }

    if (preg_match('/(?:final\s+|abstract\s+|readonly\s+)*class\s+(\S+)/', $content, $classMatch)) {
        return 'Phlix media server component: ' . $classMatch[1];
    }

    if (preg_match('/(?:final\s+|abstract\s+|readonly\s+)*interface\s+(\S+)/', $content, $interfaceMatch)) {
        return 'Phlix media server component: ' . $interfaceMatch[1];
    }

    if (preg_match('/(?:final\s+|abstract\s+|readonly\s+)*trait\s+(\S+)/', $content, $traitMatch)) {
        return 'Phlix media server component: ' . $traitMatch[1];
    }

    if (preg_match('/(?:final\s+|abstract\s+|readonly\s+)*enum\s+(\S+)/', $content, $enumMatch)) {
        return 'Phlix media server component: ' . $enumMatch[1];
    }

    if (preg_match('/^function\s+(\S+)/m', $content, $funcMatch)) {
        return 'Phlix media server component: ' . $funcMatch[1];
    }

    return 'Phlix media server source file.';
};

$repoRoot = __DIR__ . '/..';
$copyrightLine = '@copyright 2026 Joe Huss <detain@interserver.net>';
$headerTemplate = <<<'PHPBLOCK'

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
PHPBLOCK;

$changed = 0;
$skipped = 0;

foreach ($getPHPFiles($repoRoot) as $file) {
    $relativePath = substr($file, strlen($repoRoot) + 1);

    // Skip files that already carry the copyright line.
    $content = file_get_contents($file);
    if ($content === false) {
        // Never silently continue past an unreadable file: this script rewrites
        // what it reads, so "could not read" must not become "wrote nothing".
        fwrite(STDERR, "ERROR {$relativePath}  (could not be read)\n");
        exit(1);
    }

    if (str_contains($content, $copyrightLine)) {
        echo "SKIP  {$relativePath}  (already has copyright)\n";
        $skipped++;
        continue;
    }

    // Build the specific docblock for this file.
    $description = $inferDescription($file, $relativePath);
    $docblock = str_replace('<one-line description>.', $description . '.', $headerTemplate);

    // Find <?php at start, then consume all following whitespace/newlines
    // to find the true insertion point (before declare/class/function/etc).
    if (preg_match('/^<\?php\s*/', $content, $openMatch)) {
        $afterOpen = substr($content, strlen($openMatch[0]));
        // Insert docblock at this position.
        $newContent = "<?php\n" . $docblock . "\n" . $afterOpen;
    } else {
        // Fallback: prepend.
        $newContent = "<?php\n" . $docblock . "\n" . $content;
    }

    file_put_contents($file, $newContent);
    echo "ADDED {$relativePath}\n";
    $changed++;
}

echo "\nDone. {$changed} file(s) updated, {$skipped} already had copyright.\n";
