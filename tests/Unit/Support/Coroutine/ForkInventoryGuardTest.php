<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support\Coroutine;

use PHPUnit\Framework\TestCase;

/**
 * S196 — the recorded fork inventory + structural guard (S345 rule 3).
 *
 * ## WHAT THIS IS
 *
 * The authoritative, machine-checked inventory of every coroutine-fork site
 * in `src/`. A "fork site" is one of the two guarded idioms:
 *
 *   A) a `WorkerContext::inCoroutine()` static call (the shared helper), or
 *   B) an inline `\Swoole\Coroutine::getCid() > 0` / `!== -1` style condition.
 *
 * The scan is TOKENIZED (`php_strip_whitespace`, temp-file form) so a
 * docblock or comment mentioning the idiom can never be counted as a site.
 * The DB connection classes (`PhlixMySQLConnection`, `PooledMySQLConnection`)
 * read `getCid()` as an ASSIGNMENT for mutex ownership, not as an arm
 * condition — they are context reads, not forks, and are deliberately absent
 * (their coroutine context is exercised with cid-capture evidence by
 * `PhlixMySQLConnectionTest` / `PooledMySQLConnectionTest`).
 *
 * ## THE GUARD (S345 rule 3)
 *
 * "No uncovered forks remain" needs its own guard: this test FAILS when a new
 * guarded fork appears in `src/` without a covering coroutine-entering test
 * (or when a covered fork's test stops entering a coroutine / stops naming
 * the class, or when a fork disappears — keeping the map honest in both
 * directions).
 *
 * ## COVERAGE STATE (measured 2026-08-25 on master d198f9e0 + this branch)
 *
 * 26 fork files / 32 arm-fork sites, of which:
 *  - 14 files newly covered by S196 fork tests (each driven through the REAL
 *    fork decision inside `RunsInCoroutine` with observed branch identity;
 *    each mutation-proven — see the S196 PR body);
 *  - 4 files covered by earlier steps: StunClient (S169/170), NatPmpClient /
 *    PortForwardService / UpnpIgdClient (S197);
 *  - 8 pre-existing coroutine tests re-measured with execution evidence (the
 *    "possibly covered" 10 minus the 2 DB context-read classes): 7 with
 *    direct behavioral evidence (sibling-interleave, in-flight counters,
 *    fake-client consultation, timer deadline sentinel), 1 (FfmpegRunner)
 *    with coroutine-execution + result evidence whose arm is NOT
 *    mutation-discriminating (a guard flip to a never-true condition would
 *    not red it) — recorded as the weakest evidence in the set.
 *
 * ⚠ Divergence filed S403 (allocation requested with this PR): `Coroutine\System::exec`
 * maps an unexecutable command to `['code' => 127, 'output' => '']` while `shell_exec`
 * yields `null` — ChapterMarkerService::runCommand and FfmpegRunner::runProbeCommand
 * / runCoroutineAwareShellExec return '' vs null across arms. Benign for all
 * current callers; filed rather than patched (no code-based rule aligns the
 * arms for every input without inventing a new contract).
 */
final class ForkInventoryGuardTest extends TestCase
{
    /**
     * src file (relative to repo root) => covering coroutine-entering test
     * file(s). Sorted; the scan below must equal this set exactly.
     *
     * @var array<string, list<string>>
     */
    private const COVERING_TESTS = [
        'src/Admin/S3Client.php' => ['tests/Unit/Admin/S3ClientCoroutineForkTest.php'],
        'src/Hub/HttpClient.php' => ['tests/Unit/Hub/HttpClientCoroutineForkTest.php'],
        'src/Hub/RelayConsumer.php' => ['tests/Unit/Hub/RelayConsumerCoroutineForkTest.php'],
        'src/LiveTv/ComskipRunner.php' => ['tests/Unit/LiveTv/ComskipRunnerCoroutineForkTest.php'],
        'src/LiveTv/Recorder.php' => ['tests/Unit/LiveTv/RecorderCoroutineForkTest.php'],
        'src/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriver.php' => [
            'tests/Unit/LiveTv/Tuners/HdHomeRun/HdHomeRunTunerDriverTest.php',
        ],
        'src/Media/Library/MediaScanner.php' => ['tests/Unit/Media/Library/MediaScannerTest.php'],
        'src/Media/Markers/ChapterMarkerService.php' => [
            'tests/Unit/Media/Markers/ChapterMarkerServiceCoroutineForkTest.php',
        ],
        'src/Media/MediaAsset/MediaAssetWorker.php' => [
            'tests/Unit/Media/MediaAsset/MediaAssetWorkerCoroutineForkTest.php',
        ],
        'src/Media/Metadata/LibraryMetadataMatcher.php' => [
            'tests/Unit/Media/Metadata/LibraryMetadataMatcherCoroutineForkTest.php',
        ],
        'src/Media/Metadata/MetadataHttpClient.php' => [
            'tests/Unit/Media/Metadata/MetadataHttpClientCoroutineForkTest.php',
        ],
        'src/Media/SimilarityWorker.php' => ['tests/Unit/Media/SimilarityWorkerCoroutineForkTest.php'],
        'src/Media/Storage/ArtworkStorage.php' => ['tests/Unit/Media/Storage/ArtworkStorageTest.php'],
        'src/Media/Transcoding/FfmpegRunner.php' => ['tests/Unit/Media/Transcoding/FfmpegRunnerTest.php'],
        'src/Media/Transcoding/SegmentProcessRegistry.php' => [
            'tests/Unit/Media/Transcoding/SegmentProcessRegistryCoroutineForkTest.php',
        ],
        'src/Media/Transcoding/TranscodeManager.php' => ['tests/Unit/Media/Transcoding/TranscodeManagerTest.php'],
        'src/Network/NatPmpClient.php' => ['tests/Unit/Network/CoroutineSocketFailureContainmentTest.php'],
        'src/Network/PortForwardService.php' => ['tests/Unit/Network/CoroutineSocketFailureContainmentTest.php'],
        'src/Network/StunClient.php' => ['tests/Unit/Network/StunClientTest.php'],
        'src/Network/UpnpIgdClient.php' => ['tests/Unit/Network/CoroutineSocketFailureContainmentTest.php'],
        'src/Plugins/Catalog/PluginCatalogService.php' => ['tests/Unit/Plugins/Catalog/PluginCatalogServiceTest.php'],
        'src/Plugins/OAuth2/OAuth2HttpClient.php' => [
            'tests/Unit/Plugins/OAuth2/OAuth2HttpClientCoroutineForkTest.php',
        ],
        'src/Roku/RemoteRokuClient.php' => ['tests/Unit/Roku/RemoteRokuClientTest.php'],
        'src/Roku/RokuEcpClient.php' => ['tests/Unit/Roku/RokuEcpClientTest.php'],
        'src/Server/Integrations/Trakt/HttpClient.php' => [
            'tests/Unit/Server/Integrations/Trakt/HttpClientCoroutineForkTest.php',
        ],
        'src/Webhooks/WebhookHttpClient.php' => ['tests/Unit/Webhooks/WebhookHttpClientCoroutineForkTest.php'],
    ];

    /**
     * src/Common/Runtime/WorkerContext.php is the DEFINITION of the
     * inCoroutine() helper — its own getCid() > 0 line is the helper's body,
     * not a fork site.
     */
    private const NON_FORK_FILES = [
        'src/Common/Runtime/WorkerContext.php',
    ];

    private const IDIOM_A = '/(?:\bWorkerContext|\\\\?Phlix\\\\Common\\\\Runtime\\\\WorkerContext)::inCoroutine\s*\(/';
    private const IDIOM_B = '/(?:\\\\?Swoole\\\\Coroutine)::getCid\s*\(\s*\)\s*(?:>|>=|!==|===|==|<|<=)\s*-?\d+/';

    /**
     * Tokenized scan over src/: the set of files with at least one guarded
     * fork site must EXACTLY equal the pinned inventory. A new guarded fork
     * without a covering coroutine test fails here (S345 rule 3); a removed
     * fork fails too, so the map cannot silently rot in the other direction.
     */
    public function testEveryGuardedForkSiteIsInventoriedAndCovered(): void
    {
        $scanned = $this->scanForkFiles();

        $this->assertSame(
            array_keys(self::COVERING_TESTS),
            $scanned,
            'the scanned fork-file set must exactly match the inventory. A NEW guarded fork '
            . 'without a covering coroutine-entering test must be added to COVERING_TESTS '
            . '(S345 rule 3); a REMOVED fork must be dropped from it.'
        );
    }

    /**
     * Every covering test must exist, genuinely enter a coroutine, and name
     * the class whose fork it claims to cover — a test that stops entering a
     * coroutine is a silent reversion to the S170 blind spot.
     */
    public function testEveryCoveringTestEntersACoroutineAndNamesItsClass(): void
    {
        foreach (self::COVERING_TESTS as $srcFile => $testFiles) {
            $className = $this->shortClassName($srcFile);

            foreach ($testFiles as $testFile) {
                $this->assertFileExists($testFile, $srcFile . ' is inventoried as covered by ' . $testFile);

                $content = (string) file_get_contents($testFile);

                $this->assertMatchesRegularExpression(
                    '/\\\\Swoole\\\\Coroutine\\\\run\s*\(|runInCoroutine\s*\(/',
                    $content,
                    $testFile . ' must genuinely enter a coroutine to cover ' . $srcFile
                );
                $this->assertStringContainsString(
                    $className,
                    $content,
                    $testFile . ' must name the class it covers (' . $srcFile . ')'
                );
            }
        }
    }

    /**
     * @return list<string> Sorted src/ files (relative paths) containing a
     *         tokenized guarded fork site.
     */
    private function scanForkFiles(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator('src', \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $file) {
            if (in_array($file, self::NON_FORK_FILES, true)) {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'fork_guard_');
            file_put_contents($tmp, (string) file_get_contents($file));
            $code = php_strip_whitespace($tmp);
            unlink($tmp);

            if ($code === false || $code === '') {
                continue;
            }

            if (preg_match(self::IDIOM_A, $code) === 1 || preg_match(self::IDIOM_B, $code) === 1) {
                $found[] = $file;
            }
        }

        sort($found);

        return $found;
    }

    private function shortClassName(string $srcFile): string
    {
        return pathinfo($srcFile, PATHINFO_FILENAME);
    }
}
