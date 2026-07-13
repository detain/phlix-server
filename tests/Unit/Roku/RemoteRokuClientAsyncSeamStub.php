<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Roku;

use Phlix\Roku\RemoteRokuClient;

/**
 * SV-4.5 test seam: forces the async HTTP decision and records which transport
 * the routing chose, without any network I/O. Used by {@see RemoteRokuClientTest}.
 */
final class RemoteRokuClientAsyncSeamStub extends RemoteRokuClient
{
    public bool $asyncCalled = false;
    public bool $blockingCalled = false;

    protected function preferAsyncHttp(): bool
    {
        return true;
    }

    protected function httpPostAsync(string $url, string $body): ?string
    {
        $this->asyncCalled = true;
        return 'ok';
    }

    protected function httpPostBlocking(string $url, string $body): ?string
    {
        $this->blockingCalled = true;
        return 'ok';
    }
}
