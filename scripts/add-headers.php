<?php

/**
 * Idempotent copyright-header insertion script for phlix-server.
 *
 * Finds all PHP files under src/ (excluding vendor/, .git/, node_modules/, generated/)
 * that do not yet carry the @copyright 2026 Joe Huss <detain@interserver.net> line,
 * and inserts a file-level docblock in the required format.
 *
 * Run: php scripts/add-headers.php
 * Run again to verify idempotency (second run = no diff).
 */

declare(strict_types=1);

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

foreach (getPHPFiles($repoRoot) as $file) {
    $relativePath = substr($file, strlen($repoRoot) + 1);

    $content = file_get_contents($file);
    if (str_contains($content, $copyrightLine)) {
        echo "SKIP  {$relativePath}  (already has copyright)\n";
        $skipped++;
        continue;
    }

    $description = inferDescription($content, $relativePath);
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

/**
 * Recursively collect all .php files under src/, excluding vendor/, .git/, generated, node_modules.
 *
 * @return list<string> Absolute file paths.
 */
function getPHPFiles(string $repoRoot): array
{
    $dirs = ['src'];
    $excludes = ['vendor', '.git', 'generated', 'node_modules', '.phpunit.cache'];

    $files = [];
    foreach ($dirs as $dir) {
        $path = $repoRoot . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }
        collect($path, $files, $excludes);
    }

    sort($files);
    return $files;
}

function collect(string $dir, array &$files, array $excludes): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $node) {
        if ($node->isDir()) {
            $basename = $node->getBasename();
            if (in_array($basename, $excludes, true)) {
                $iterator->setFlags(RecursiveIteratorIterator::CATCH_GET_CHILD);
            }
            if (in_array($basename, $excludes, true)) {
                continue;
            }
        }

        if ($node->isFile() && $node->getExtension() === 'php') {
            $files[] = $node->getPathname();
        }
    }
}

/**
 * Best-effort one-line description derived from namespace / class name.
 */
function inferDescription(string $content, string $relativePath): string
{
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
}
