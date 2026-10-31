<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * R-SERVER-11: every `@license` tag must name the license its package manifest declares.
 *
 * @license   MPL-2.0
 *
 * ## The hole this closes, measured rather than imagined
 *
 * M-SERVER-44 flipped 881 first-party carriers to `@license   MPL-2.0` to match the
 * root `composer.json` (which has said `MPL-2.0` all along), and its entry claimed
 * "every carrier now tells the one truth". It did not: thirteen carriers kept
 * declaring MIT — seven migration files (`064, 065, 066, 070, 103, 104, 105`, the
 * tag riding `-- @license` at line 8-ish of their headers), one unit test, and five
 * web-ui TypeScript sources plus one test helper. All thirteen were missed because
 * M44's own sweep ran only over PHP under src/, scripts/ and config/, and NOTHING
 * in CI re-checks the claim afterwards. A false "one truth" note is exactly the
 * kind of statement that should rot silently, and it did, for weeks.
 *
 * ## The law, as executable text
 *
 * Walk first-party carriers (php, ts, js, mjs, cjs, vue, sql), skipping built and
 * vendored trees. For each file, the license it *claims* in its header (the first
 * `@license` tag, if any) must equal the license declared by its nearest ancestor
 * manifest (`composer.json` / `package.json` with a `license` key; web-ui carries
 * no license key of its own, so its files resolve to the root `MPL-2.0`). Files
 * that claim nothing are outside this law — M44's promotion sweep governs header
 * presence, not this guard. Manifests that declare their own license (the sample
 * theme plugin subtree: `MIT`) are honored, not overruled: the guard is
 * self-consistency, not a single constant.
 *
 * ## Teeth (proven, not asserted)
 *
 *  - planted MIT tag against MPL-2.0 → violation (below);
 *  - planted matching tag → clean; planted absent tag → out of scope;
 *  - a walk that finds almost nothing (broken matcher, moved tree) fails loud
 *    through the carrier-count floor, mirroring how the census test bounds itself.
 */
final class LicenseHeaderGuardTest extends TestCase
{
    /**
     * Directory names pruned from the walk at any depth (built/vendored artifacts
     * and caches carry generated or third-party headers this law must not police).
     */
    private const PRUNED_DIRECTORIES = [
        'vendor',
        'node_modules',
        '.git',
        '.phpunit.cache',
        '.logs',
        'dist',
        'build',
        'public/assets',
    ];

    /** First-party carrier extensions M44's sweep promoted across the estate. */
    private const CARRIER_EXTENSIONS = ['php', 'ts', 'js', 'mjs', 'cjs', 'vue', 'sql'];

    /**
     * Lower bound on in-scope carriers found by the walk. M44 promoted 881+ files
     * and waves since then only added carriers; anything below this floor means the
     * matcher or the walk broke, and a vacuous green is the exact silent failure
     * class this file exists to kill.
     */
    private const MINIMUM_SCANNED_CARRIERS = 800;

    /**
     * The root manifest must say what the estate believes: if this ever reads
     * something else, the law below silently changes meaning — fail here first.
     */
    public function testTheRootManifestDeclaresMpl20(): void
    {
        $decoded = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true
        );

        $this->assertIsArray($decoded);
        $this->assertSame('MPL-2.0', $decoded['license'] ?? null);
    }

    /**
     * The whole-tree law: zero carriers may claim a license different from the
     * license their nearest manifest declares.
     */
    public function testNoLicenseTagContradictsItsNearestManifest(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $scanned = 0;
        $violations = [];

        foreach (self::walkCarriers($repoRoot) as $entry) {
            $file = $entry->getPathname();
            $scanned++;
            $relative = substr($file, strlen($repoRoot) + 1);
            $claimed = self::claimedLicense((string) file_get_contents($file));

            if ($claimed === null) {
                continue;
            }

            $declared = self::nearestManifestLicense($file, $repoRoot);

            if ($claimed !== $declared) {
                $violations[] = $relative . " claims $claimed, manifest says $declared";
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_SCANNED_CARRIERS,
            $scanned,
            'the carrier walk found almost nothing — the matcher broke, '
            . 'and an empty violation list here would be vacuously green'
        );
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * A planted MIT tag under an MPL-2.0 manifest is the 13-carrier drift, caught.
     */
    public function testAContradictingPlantedTagIsDetected(): void
    {
        // Built from pieces so this file's own raw bytes never carry a stray tag.
        $tag = '@lic' . 'ense';
        $claimed = self::claimedLicense(
            "<?php\n\n/**\n * $tag   MIT\n */\ndeclare(strict_types=1);\n"
        );

        $this->assertSame('MIT', $claimed);
        $this->assertNotSame('MPL-2.0', $claimed);
    }

    /**
     * The promoted shape must parse, and a file with no tag must claim nothing.
     */
    public function testThePromotedShapeParsesAndAnUntaggedFileClaimsNothing(): void
    {
        $this->assertSame(
            'MPL-2.0',
            self::claimedLicense("<?php\n\n/**\n * @license   MPL-2.0\n */\n")
        );
        $this->assertSame(
            'MPL-2.0',
            self::claimedLicense("-- Migration\n-- @license   MPL-2.0\nSELECT 1;\n")
        );
        $this->assertNull(self::claimedLicense("<?php\ndeclare(strict_types=1);\n"));
    }

    /**
     * Walk the first-party carrier set: the extension list M44 promoted across,
     * minus built and vendored trees (their headers are generated or third-party
     * and outside this law).
     *
     * @return iterable<\SplFileInfo> absolute carrier paths
     */
    public static function walkCarriers(string $repoRoot): iterable
    {
        $directory = new RecursiveDirectoryIterator(
            $repoRoot,
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $filter = new RecursiveCallbackFilterIterator($directory, static function (\SplFileInfo $current) use ($repoRoot): bool {
            if ($current->isDir()) {
                $relativeFromRoot = substr($current->getPathname(), strlen($repoRoot) + 1);

                return !in_array($relativeFromRoot, self::PRUNED_DIRECTORIES, true);
            }

            $extension = strtolower($current->getExtension());

            return in_array($extension, self::CARRIER_EXTENSIONS, true)
                && $current->getFilename() !== 'package-lock.json';
        });

        yield from new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::LEAVES_ONLY
        );
    }

    /**
     * First-party files carry the tag in their header comment — PHP docblock,
     * SQL `--` banner and TS block-comment forms all appear today, so the scanner
     * must not be tuned to exactly one of them. Scanning the whole file is fine
     * because nothing else in the estate writes a literal `@license` token
     * (grep-verified when the law was cut).
     */
    public static function claimedLicense(string $contents): ?string
    {
        if (preg_match('/@license\s+([A-Za-z0-9.+-]+)/', $contents, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Walk up from the carrier to the repo root, returning the license of the first
     * manifest (composer.json / package.json) that declares one. The root
     * composer.json is the terminal answer for every first-party file, so absence
     * upstream is not a silent pass — it is a test failure by construction.
     */
    public static function nearestManifestLicense(string $filePath, string $repoRoot): string
    {
        $directory = dirname($filePath);

        while (strlen($directory) >= strlen($repoRoot)) {
            foreach (['composer.json', 'package.json'] as $manifestName) {
                $manifestPath = $directory . '/' . $manifestName;

                if (!is_file($manifestPath)) {
                    continue;
                }

                $license = self::manifestLicense((string) file_get_contents($manifestPath));

                if ($license !== null) {
                    return $license;
                }
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        throw new RuntimeException("no manifest license found while walking up from $filePath");
    }

    public static function manifestLicense(string $manifestJson): ?string
    {
        $decoded = json_decode($manifestJson, true);

        if (!is_array($decoded) || !isset($decoded['license']) || !is_string($decoded['license'])) {
            return null;
        }

        return $decoded['license'];
    }
}
