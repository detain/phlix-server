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
