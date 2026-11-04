<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;

/**
 * Review server__ipd SRV-M3: the README's "Architecture" tree promised a map
 * of `src/` but omitted 19 of the 24 real top-level subsystems (Access, Admin,
 * AirPlay, Casting, Chromecast, Collections, Config, Console, Discovery, Dlna,
 * Hub, LiveTv, Network, Playlists, Plugins, Roku, Stats, Theming, Webhooks) —
 * a reader auditing the codebase against the docs would conclude whole
 * features do not exist.
 *
 * The pin is two-sided, so the tree can neither shrink nor rot:
 *  1. every top-level `src/<Dir>` on disk must appear as a depth-1 entry in
 *     the README tree (coverage);
 *  2. every tree entry (at any listed depth) must resolve to a real directory
 *     (no phantom subsystems — docs claiming code that never lands is the
 *     mirror image of the defect this kills).
 *
 * Deliberately STRUCTURE-only: file-level README detail is what drifted in the
 * first place, so the tree enumerates directories and the pin validates
 * directories. Renaming or adding a subsystem is a legitimate edit — the test
 * just requires the map to move with the territory in the same commit.
 */
final class ArchitectureTreeCoversSrcSubsystemsTest extends TestCase
{
    private const README = __DIR__ . '/../../../README.md';

    private const SRC = __DIR__ . '/../../../src';

    /**
     * Box-drawing entry lines: an optional run of `│` / space indent prefixes,
     * then `├── ` or `└── `, then a directory name and `/`. The trailing
     * comment after `#` is ignored by the parser.
     */
    private const ENTRY_PATTERN = '/^(?:│ {3}| {4})*[├└]── ([A-Za-z][A-Za-z0-9]*)\//u';

    public function testTheReadmeKeepsItsArchitectureCodeBlock(): void
    {
        $block = self::architectureBlock();

        $this->assertNotSame(
            '',
            $block,
            'the README must keep a fenced code block under "## Architecture" —'
            . ' the pin is meaningless without the map',
        );
    }

    public function testEveryTopLevelSrcSubsystemIsListedInTheTree(): void
    {
        $treeDepth1 = self::treeEntries()['depth1'];

        $onDisk = [];
        foreach (scandir(self::SRC) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir(self::SRC . '/' . $entry)) {
                $onDisk[] = $entry;
            }
        }

        sort($onDisk);
        $missing = array_values(array_diff($onDisk, $treeDepth1));

        $this->assertSame(
            [],
            $missing,
            'src/ subsystems absent from the README Architecture tree'
            . ' (SRV-M3 law: the map lists every top-level subsystem): ' . implode(', ', $missing),
        );
    }

    public function testEveryTreeEntryResolvesToARealDirectory(): void
    {
        $phantoms = [];
        foreach (self::treeEntries()['paths'] as $relPath) {
            if (!is_dir(self::SRC . '/' . $relPath)) {
                $phantoms[] = $relPath;
            }
        }

        $this->assertSame(
            [],
            $phantoms,
            'the README Architecture tree names directories that do not exist under'
            . ' src/ — remove them or land them first: ' . implode(', ', $phantoms),
        );
    }

    /**
     * Parse the Architecture code block into depth-1 names and full relative
     * paths (depth tracked via the two-column indent the tree uses).
     *
     * @return array{depth1: list<string>, paths: list<string>}
     */
    private static function treeEntries(): array
    {
        $lines = explode("\n", self::architectureBlock());

        /** @var array<int, string> $stack */
        $stack = [];
        /** @var list<string> $depth1 */
        $depth1 = [];
        /** @var list<string> $paths */
        $paths = [];

        foreach ($lines as $line) {
            if (preg_match('/^public\//', $line) === 1) {
                break; // the block's second root: public/ subtree entries are not src/ claims
            }
            if (preg_match('/^\s*src\/\s*$/', $line) === 1) {
                continue; // the block's root line
            }

            $matches = [];
            if (preg_match(self::ENTRY_PATTERN, $line, $matches) !== 1) {
                continue;
            }

            $name = $matches[1];

            // Indent depth: each tree level is one 4-COLUMN prefix (`│   ` or
            // four spaces). The `│` glyph is 3 BYTES, so counting is done on
            // the matched prefixes themselves, never on byte offsets.
            $depth = 0;
            $offset = 0;
            while (preg_match('/^(│ {3}| {4})/u', substr($line, $offset), $m) === 1) {
                $offset += strlen($m[1]);
                $depth++;
            }
            $depth++;

            $stack[$depth] = $name;
            for ($d = $depth + 1; isset($stack[$d]); $d++) {
                unset($stack[$d]);
            }

            $relParts = [];
            for ($d = 1; $d <= $depth; $d++) {
                $relParts[] = $stack[$d] ?? '';
            }
            $relPath = implode('/', $relParts);

            if ($depth === 1) {
                $depth1[] = $name;
            }

            $paths[] = $relPath;
        }

        sort($depth1);

        return ['depth1' => $depth1, 'paths' => $paths];
    }

    /**
     * The fenced block that follows the `## Architecture` heading.
     */
    private static function architectureBlock(): string
    {
        $readme = (string) file_get_contents(self::README);

        $headingAt = strpos($readme, "\n## Architecture\n");
        if ($headingAt === false) {
            return '';
        }

        $open = strpos($readme, "```\n", $headingAt);
        if ($open === false) {
            return '';
        }

        $close = strpos($readme, "\n```", $open + 4);
        if ($close === false) {
            return '';
        }

        return substr($readme, $open + 4, $close - $open - 4);
    }
}
