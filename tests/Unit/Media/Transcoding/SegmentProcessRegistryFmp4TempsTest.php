<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * S56 — the registry's temp cleaner must also remove the auxiliary temps a CMAF
 * encode writes beside its `.part-<hex>` marker.
 *
 * A killed encode never runs its own `rm`, so the registry's cleaner is the only
 * thing that removes its corpse. Pre-S56 that corpse was one file; on the CMAF
 * branch it is four (`<tmp>`, `<tmp>.i`, `<tmp>.s0`, `<tmp>.m3u8`).
 *
 * The SV-4.2 invariant this must not break: cleanup is keyed on the LAUNCHER'S
 * OWN unique hex, never on a `{$final}.part-*` family glob, so a sibling
 * worker's live temp for the same final segment survives. Both halves are
 * asserted.
 */
final class SegmentProcessRegistryFmp4TempsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix_s56_reg_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * Invokes the registry's SHIPPED default temp cleaner — the one production
     * actually uses — rather than a re-implementation of it.
     */
    private function defaultTempCleaner(): callable
    {
        $method = new \ReflectionMethod(SegmentProcessRegistry::class, 'defaultTempCleaner');
        $cleaner = $method->invoke(null);
        $this->assertIsCallable($cleaner);

        return $cleaner;
    }

    public function test_it_removes_the_cmaf_auxiliary_temps_of_the_killed_launcher(): void
    {
        $final = "{$this->dir}/seg-v720p-00042.m4s";
        $tmp = "{$final}.part-deadbeef";
        foreach (['', '.i', '.s0', '.m3u8'] as $suffix) {
            file_put_contents($tmp . $suffix, 'corpse');
        }
        $this->assertCount(4, glob("{$this->dir}/*") ?: [], 'control: four temps exist before the clean');

        ($this->defaultTempCleaner())($tmp);

        $this->assertSame([], glob("{$this->dir}/*") ?: []);
    }

    /**
     * The SV-4.2 re-review invariant: a CONCURRENT sibling worker encoding the
     * same final segment has its own `.part-<other hex>` (and its own auxiliary
     * temps). Killing one launcher must not destroy the other's live encode.
     */
    public function test_it_leaves_a_sibling_workers_live_temps_untouched(): void
    {
        $final = "{$this->dir}/seg-v720p-00042.m4s";
        $mine = "{$final}.part-deadbeef";
        $theirs = "{$final}.part-cafebabe";
        foreach (['', '.i', '.s0', '.m3u8'] as $suffix) {
            file_put_contents($mine . $suffix, 'mine');
            file_put_contents($theirs . $suffix, 'theirs');
        }

        ($this->defaultTempCleaner())($mine);

        $survivors = glob("{$this->dir}/*") ?: [];
        sort($survivors);
        $this->assertSame(
            [$theirs, "{$theirs}.i", "{$theirs}.m3u8", "{$theirs}.s0"],
            $survivors,
            'only the killed launcher\'s own hex may be cleaned'
        );
    }

    /**
     * On the MPEG-TS path there is no auxiliary temp at all, so the sibling
     * sweep must be a no-op — and in particular must not start matching the
     * PUBLISHED segment that sits next to the temp.
     */
    public function test_the_mpegts_path_is_unaffected(): void
    {
        $final = "{$this->dir}/seg-00042.ts";
        $tmp = "{$final}.part-deadbeef";
        file_put_contents($tmp, 'corpse');
        file_put_contents($final, 'published');
        file_put_contents("{$this->dir}/seg-00043.ts", 'published');

        ($this->defaultTempCleaner())($tmp);

        $survivors = glob("{$this->dir}/*") ?: [];
        sort($survivors);
        $this->assertSame([$final, "{$this->dir}/seg-00043.ts"], $survivors);
    }

    public function test_an_empty_temp_path_is_a_no_op(): void
    {
        file_put_contents("{$this->dir}/keep.ts", 'x');

        ($this->defaultTempCleaner())('');

        $this->assertSame(["{$this->dir}/keep.ts"], glob("{$this->dir}/*") ?: []);
    }
}
