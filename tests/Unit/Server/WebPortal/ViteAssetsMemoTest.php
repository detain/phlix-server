<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Server\WebPortal\ViteAssets;
use PHPUnit\Framework\TestCase;

/**
 * minors M-SERVER-33 (W-min109): the /app boot parsed Vite's manifest on
 * every request. The memo must serve the parsed map while the file's
 * (mtime:size) fingerprint is unchanged, re-read on a real deploy (fingerprint
 * move), and honor the test-seam reset.
 */
final class ViteAssetsMemoTest extends TestCase
{
    private const V1 = '{"src/main.ts":{"file":"assets/app/entry-AAAAAAAA.js","isEntry":true}}';
    private const V2 = '{"src/main.ts":{"file":"assets/app/entry-BBBBBBBB.js","isEntry":true}}';

    private string $root = '';
    private string $manifest = '';
    private int $frozen = 0;

    protected function setUp(): void
    {
        ViteAssets::resetParsedForTesting();
        $this->root = sys_get_temp_dir() . '/phlix_s33_' . bin2hex(random_bytes(6));
        $dir = $this->root . '/assets/app/.vite';
        mkdir($dir, 0o777, true);
        $this->manifest = $dir . '/manifest.json';
        file_put_contents($this->manifest, self::V1);
        $this->frozen = time() - 1000;
        touch($this->manifest, $this->frozen);
    }

    protected function tearDown(): void
    {
        ViteAssets::resetParsedForTesting();
        @system('rm -rf ' . escapeshellarg($this->root));
    }

    public function testMemoServesTheParsedManifestWhileTheFingerprintHolds(): void
    {
        $assets = new ViteAssets($this->root);
        $first = $assets->getEntryJsPath();
        self::assertSame('/assets/app/entry-AAAAAAAA.js', $first);

        // Same length, same mtime: a byte-swap the fingerprint cannot see. The
        // memoized worker answers the ORIGINAL entry — proving no second read
        // + parse happened. The pre-cure code re-read here and flips to B's.
        // (Split through locals: the same-expression repeat trips phpstan's
        // already-narrowed-type law — d2685e2b-family idiom.)
        file_put_contents($this->manifest, self::V2);
        touch($this->manifest, $this->frozen);

        $again = $assets->getEntryJsPath();
        self::assertSame('/assets/app/entry-AAAAAAAA.js', $again);
    }

    public function testAFingerprintMoveBustsTheMemoLikeARealDeploy(): void
    {
        $assets = new ViteAssets($this->root);
        self::assertSame('/assets/app/entry-AAAAAAAA.js', $assets->getEntryJsPath());

        file_put_contents($this->manifest, self::V2);
        touch($this->manifest, $this->frozen + 5);

        self::assertSame('/assets/app/entry-BBBBBBBB.js', $assets->getEntryJsPath());
    }

    public function testResetSeamDropsTheMemoForFreshReads(): void
    {
        $assets = new ViteAssets($this->root);
        self::assertSame('/assets/app/entry-AAAAAAAA.js', $assets->getEntryJsPath());

        ViteAssets::resetParsedForTesting();
        file_put_contents($this->manifest, self::V2);
        touch($this->manifest, $this->frozen);

        self::assertSame('/assets/app/entry-BBBBBBBB.js', $assets->getEntryJsPath());
    }

    public function testMissingManifestStillRefusesPerCall(): void
    {
        $assets = new ViteAssets($this->root . '/gone');

        $this->expectException(\RuntimeException::class);
        $assets->getEntryJsPath();
    }
}
