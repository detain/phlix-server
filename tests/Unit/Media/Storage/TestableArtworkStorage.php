<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Media\Storage\ArtworkStorage;
use Workerman\Http\Client;

/**
 * Test double exposing the protected download-path hooks so the async and
 * blocking branches of {@see ArtworkStorage} can be exercised without a live
 * Workerman worker or real network.
 */
final class TestableArtworkStorage extends ArtworkStorage
{
    public ?bool $forceBlocking = null;
    public ?Client $fakeClient = null;

    /**
     * S73 SSRF evidence — every URL the BLOCKING fetch seam was asked for,
     * in order. The AC test asserts an unallowlisted host never appears here
     * (rejection happens BEFORE the fetch is issued, not after the socket).
     *
     * @var list<string>
     */
    public array $blockingFetchUrls = [];

    /**
     * Scripted results for {@see fetchOnceBlocking()}, consumed in order.
     * Each entry is the ['code' => int, 'location' => string] the seam would
     * have returned from a real cURL exchange; a 200 also writes a marker
     * body into the temp file so success paths can assert on content.
     *
     * @var list<array{code: int, location?: string}>
     */
    public array $scriptedBlockingResponses = [];

    /**
     * S73: recording/scripted blocking-fetch seam REPLACING cURL entirely —
     * the SSRF hop tests need deterministic redirect chains with no network.
     */
    protected function fetchOnceBlocking(string $url, string $tmpFile): array
    {
        $this->blockingFetchUrls[] = $url;

        $next = array_shift($this->scriptedBlockingResponses);
        if ($next === null) {
            throw new \LogicException('TestableArtworkStorage: no scripted blocking response left for ' . $url);
        }

        if ($next['code'] === 200) {
            file_put_contents($tmpFile, 'SSRF-TEST-JPEG-BYTES');
        }

        return ['code' => $next['code'], 'location' => $next['location'] ?? ''];
    }

    protected function shouldUseBlockingDownload(string $url): bool
    {
        if ($this->forceBlocking !== null) {
            return $this->forceBlocking;
        }

        return parent::shouldUseBlockingDownload($url);
    }

    public function shouldUseBlockingDownloadPublic(string $url): bool
    {
        return parent::shouldUseBlockingDownload($url);
    }

    protected function getAsyncClient(): Client
    {
        if ($this->fakeClient !== null) {
            return $this->fakeClient;
        }

        return parent::getAsyncClient();
    }

    /**
     * Public passthrough to the protected atomic variant writer so the
     * temp-then-rename mechanism (and its failure cleanup) can be exercised
     * directly without a live download or the Swoole coroutine path.
     */
    public function atomicWriteVariantPublic(string $variantFile, string $jpegData): bool
    {
        return parent::atomicWriteVariant($variantFile, $jpegData);
    }
}
