<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanIgnorePatterns;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Consequence tests for `scanner.ignore_patterns`.
 *
 * Per the settings-program rule these assert the OBSERVABLE EFFECT — that a
 * configured pattern actually causes {@see MediaScanner} to SKIP a file that it
 * would otherwise index — not that a getter returns the configured list.
 *
 * The probe is {@see MediaScanner::countFiles()}: it performs a real recursive
 * directory walk applying the same extension + {@see MediaScanner::shouldSkipFile()}
 * filter that `scanFlat()` uses, and returns an integer, so a skip is directly
 * observable without a database.
 *
 * The override values below deliberately DIFFER from the shipped defaults
 * (e.g. `['nfo']` in place of the six shipped patterns), so a scanner left on
 * its literal list produces a visibly different count rather than
 * coincidentally agreeing.
 *
 * @covers \Phlix\Media\Library\ScanIgnorePatterns
 * @covers \Phlix\Media\Library\MediaScanner
 */
final class ScanIgnorePatternsTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
        $this->tmpDir = null;
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * A patterns object over a settings store returning `$value` for the key.
     */
    private function patternsFor(mixed $value): ScanIgnorePatterns
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === ScanIgnorePatterns::SETTING_KEY ? $value : null
        );

        return new ScanIgnorePatterns($repo);
    }

    private function scannerWith(?ScanIgnorePatterns $patterns): MediaScanner
    {
        return new MediaScanner(
            $this->createMock(Connection::class),
            $this->createMock(ItemRepository::class),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $patterns
        );
    }

    /**
     * @param array<int, string> $filenames
     */
    private function makeTempDirWith(array $filenames): string
    {
        $dir = sys_get_temp_dir() . '/phlix_ignore_test_' . uniqid();
        mkdir($dir, 0775, true);
        foreach ($filenames as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────────
    // consequence: a configured pattern really skips a file
    // ─────────────────────────────────────────────────────────────────

    /**
     * THE test. `.nfo` is not a media extension, so the discriminator has to be
     * a real media file: `Behind The Scenes.mkv` is indexed by a default
     * scanner and skipped once `scenes` is configured as an ignore pattern.
     *
     * Both halves run against the SAME fixture, so the assertion cannot pass by
     * the filter being globally broken in either direction.
     */
    public function test_a_configured_pattern_causes_a_real_skip(): void
    {
        $this->tmpDir = $this->makeTempDirWith([
            'Movie One (2020).mkv',
            'Behind The Scenes.mkv',
        ]);

        // Shipped defaults: neither file matches, both are counted.
        $this->assertSame(
            2,
            $this->scannerWith(null)->countFiles($this->tmpDir, 'movie'),
            'Baseline: with the shipped list nothing here is ignored.'
        );

        // Override with a list that does NOT contain any shipped pattern, so a
        // scanner still reading the literal would return 2 here.
        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor(['scenes']))->countFiles($this->tmpDir, 'movie'),
            'The configured pattern must actually remove the file from the walk.'
        );
    }

    /**
     * The override REPLACES the shipped list rather than adding to it: a file
     * matching a shipped default is counted once the override omits it. This is
     * the discriminating direction — it fails if the consumer merges the
     * configured list over the hardcoded one.
     */
    public function test_an_override_replaces_rather_than_extends_the_defaults(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Half Done.part.mkv']);

        $this->assertSame(
            0,
            $this->scannerWith(null)->countFiles($this->tmpDir, 'movie'),
            'Baseline: `.part` is a shipped pattern, so this file is ignored.'
        );

        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor(['_unpack']))->countFiles($this->tmpDir, 'movie'),
            'An override that omits `.part` must stop ignoring it.'
        );
    }

    /**
     * An explicitly empty list is LEGAL and means "skip nothing extra".
     */
    public function test_an_empty_configured_list_skips_nothing_extra(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Half Done.part.mkv', 'Movie.mkv']);

        $this->assertSame(
            2,
            $this->scannerWith($this->patternsFor([]))->countFiles($this->tmpDir, 'movie'),
            'An empty list must disable every configurable skip.'
        );
    }

    /**
     * ...but an empty list must NOT disable the hardcoded dotfile rule. That
     * rule is deliberately not configurable, so this asserts the one thing the
     * setting is forbidden from reaching.
     */
    public function test_an_empty_configured_list_does_not_re_enable_dotfiles(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['.hidden.mkv', 'Movie.mkv']);

        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor([]))->countFiles($this->tmpDir, 'movie'),
            'The dotfile rule must survive an empty ignore list.'
        );
    }

    /**
     * `sample` is OPT-IN, not shipped. Both halves of that matter, so both are
     * asserted here through the real scanner walk.
     */
    public function test_sample_files_are_kept_by_default_and_skipped_once_configured(): void
    {
        $files = [
            'Movie Name (2020).mkv',
            'Movie Name (2020)-sample.mkv',
            'sample.mkv',
            'Movie.Name.2020.SAMPLE.mkv',
        ];

        // Default: nothing is skipped. Upgrading an existing install must not
        // silently change which files its libraries import.
        $this->tmpDir = $this->makeTempDirWith($files);
        $this->assertSame(
            4,
            $this->scannerWith(null)->countFiles($this->tmpDir, 'movie'),
            'The shipped defaults must not drop sample clips — that is an opt-in.'
        );

        // Configured: the token rule catches every real sample convention,
        // including the uppercase one. Same directory, different policy — so
        // the only variable between the two assertions is the setting.
        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor(['sample']))->countFiles($this->tmpDir, 'movie'),
            'Once configured, only the real movie survives.'
        );
    }

    /**
     * The token narrowing: a title that merely CONTAINS the letters is kept.
     * A bare substring match would wrongly drop both of these.
     */
    public function test_sample_does_not_swallow_titles_containing_the_letters(): void
    {
        $this->tmpDir = $this->makeTempDirWith([
            'Free Samples (2012).mkv',
            'Resampled (1999).mkv',
        ]);

        $this->assertSame(
            2,
            $this->scannerWith(null)->countFiles($this->tmpDir, 'movie'),
            '`sample` is token-matched, so `Samples` and `Resampled` are kept.'
        );
    }

    /**
     * Matching is case-insensitive, which is what makes SABnzbd's real
     * uppercase `_UNPACK_` marker actually get skipped.
     */
    public function test_matching_is_case_insensitive(): void
    {
        $this->tmpDir = $this->makeTempDirWith([
            'Movie_UNPACK_stage.mkv',
            'Movie.TMP.mkv',
            'Keeper.mkv',
        ]);

        $this->assertSame(
            1,
            $this->scannerWith(null)->countFiles($this->tmpDir, 'movie'),
            'Uppercase spellings of the shipped markers must be skipped too.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // validation / hostile input
    // ─────────────────────────────────────────────────────────────────

    /**
     * Non-string entries must be DROPPED rather than reaching str_contains(),
     * while the valid entries in the same list still take effect.
     */
    public function test_non_string_entries_are_dropped_but_valid_ones_apply(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Trailer Clip.mkv', 'Movie.mkv']);

        /** @var list<mixed> $hostile */
        $hostile = [123, null, ['nested'], new \stdClass(), 'trailer', true];

        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor($hostile))->countFiles($this->tmpDir, 'movie'),
            'The one usable pattern applies; the junk entries are ignored, not fatal.'
        );
    }

    /**
     * An empty or whitespace-only pattern is a substring of every filename and
     * would blank the entire library. It must be dropped.
     */
    public function test_empty_patterns_cannot_blank_the_library(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['A.mkv', 'B.mkv', 'C.mkv']);

        $this->assertSame(
            3,
            $this->scannerWith($this->patternsFor(['', '   ']))->countFiles($this->tmpDir, 'movie'),
            'An empty pattern must never match everything.'
        );
    }

    /**
     * A pattern is never treated as a regular expression — operator-supplied
     * config must not become a matching engine.
     */
    public function test_patterns_are_literal_not_regex(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Movie.mkv']);

        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor(['.*']))->countFiles($this->tmpDir, 'movie'),
            '`.*` must match literally (it does not occur here), not as a regex.'
        );
    }

    /**
     * A non-array configured value falls back to the shipped defaults rather
     * than blanking or crashing the walk.
     *
     * @return array<string, array{mixed}>
     */
    public static function nonArrayValues(): array
    {
        return [
            'string' => ['.part'],
            'int'    => [42],
            'null'   => [null],
            'bool'   => [true],
        ];
    }

    /**
     * @dataProvider nonArrayValues
     */
    public function test_a_non_array_value_falls_back_to_the_defaults(mixed $value): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Half Done.part.mkv', 'Movie.mkv']);

        $this->assertSame(
            1,
            $this->scannerWith($this->patternsFor($value))->countFiles($this->tmpDir, 'movie'),
            'A malformed value must leave the shipped skip behaviour intact.'
        );
    }

    /**
     * A throwing settings store must not break scanning, and must leave the
     * shipped defaults in force rather than skipping everything or nothing.
     */
    public function test_a_throwing_settings_store_does_not_break_the_scan(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $this->tmpDir = $this->makeTempDirWith(['Half Done.part.mkv', 'Movie.mkv']);

        $this->assertSame(
            1,
            $this->scannerWith(new ScanIgnorePatterns($repo))->countFiles($this->tmpDir, 'movie'),
            'A settings outage must degrade to the shipped patterns, not blank them.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // policy object surface
    // ─────────────────────────────────────────────────────────────────

    public function test_without_a_store_the_shipped_defaults_apply(): void
    {
        $patterns = new ScanIgnorePatterns();

        $this->assertTrue($patterns->matches('Half Done.part'));
        $this->assertFalse(
            $patterns->matches('movie-sample.mkv'),
            '`sample` is opt-in; the shipped list must leave sample clips alone.'
        );
        $this->assertFalse($patterns->matches('Movie One (2020).mkv'));
    }

    /**
     * The five previously-hardcoded literals must still all be shipped — this
     * pins the migration from the literal list so a default cannot be dropped
     * by accident.
     */
    public function test_the_shipped_defaults_still_contain_the_replaced_literals(): void
    {
        foreach (['.part', '.tmp', '_unpack', '.download', '.!ut'] as $literal) {
            $this->assertContains(
                $literal,
                ScanIgnorePatterns::DEFAULT_PATTERNS,
                "The previously-hardcoded literal {$literal} must remain a shipped default."
            );
        }

        // ...and NOTHING else. The shipped defaults must stay equivalent in
        // effect to the literal list they replaced, so that upgrading cannot
        // silently change which files a library imports. `sample` is offered
        // as an opt-in (see config/scanner.php) precisely because it CAN drop
        // a real title, and that is not a decision to impose at upgrade time.
        $this->assertNotContains('sample', ScanIgnorePatterns::DEFAULT_PATTERNS);
        $this->assertCount(5, ScanIgnorePatterns::DEFAULT_PATTERNS);
    }

    /**
     * The shipped config file and the class constant must agree — otherwise the
     * admin UI would display one list while the scanner applied another.
     */
    public function test_the_config_file_matches_the_class_defaults(): void
    {
        /** @var mixed $config */
        $config = require __DIR__ . '/../../../../config/scanner.php';

        $this->assertIsArray($config);
        $fromFile = $config['ignore_patterns'] ?? null;
        $this->assertIsArray($fromFile);

        sort($fromFile);
        $fromClass = ScanIgnorePatterns::DEFAULT_PATTERNS;
        sort($fromClass);

        $this->assertSame($fromClass, $fromFile);
    }

    /**
     * refresh() must actually re-read the store — that is what makes the key
     * LIVE-per-scan rather than frozen for the worker's lifetime.
     */
    public function test_refresh_re_reads_the_store(): void
    {
        $calls = 0;
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            function (string $key) use (&$calls) {
                $calls++;

                return $calls === 1 ? ['first'] : ['second'];
            }
        );

        $patterns = new ScanIgnorePatterns($repo);

        $this->assertTrue($patterns->matches('a-first-file.mkv'));
        // Memoised: repeated calls do not re-query.
        $this->assertTrue($patterns->matches('another-first.mkv'));
        $this->assertSame(1, $calls, 'The list must be memoised between refreshes.');

        $patterns->refresh();

        $this->assertFalse($patterns->matches('a-first-file.mkv'));
        $this->assertTrue($patterns->matches('a-second-file.mkv'));
        $this->assertSame(2, $calls, 'refresh() must cause exactly one re-read.');
    }
}
