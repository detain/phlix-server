<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Runtime;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Runtime\WorkerContext;
use Phlix\Webhooks\WebhookHttpClient;

/**
 * SV-0.3 / SV-0.4 regression tests for the shared worker-context detection used
 * by all four async HTTP clients to choose between the async (event-loop /
 * coroutine) path and the blocking cURL path.
 *
 * SV-0.3: {@see WorkerContext::isEventLoopRunning()} must report false outside a
 * running Workerman worker (CLI/PHPUnit) so the clients fall back to blocking
 * cURL there instead of touching the async client / Timer that requires an
 * event loop.
 *
 * SV-0.4: {@see WorkerContext::inCoroutine()} is the guard that gates the
 * Swoole\Coroutine\Channel cooperative-wait path. Outside a coroutine it must
 * be false so the clients NEVER reach Channel::pop() (which would return false
 * immediately = a false timeout while the callback is still pending).
 */
final class WorkerContextTest extends TestCase
{
    /**
     * SV-0.3: no Workerman worker is running under PHPUnit, so the helper the
     * clients trust to enable the async path must report false here.
     */
    public function testIsEventLoopRunningIsFalseOutsideWorker(): void
    {
        $this->assertFalse(
            WorkerContext::isEventLoopRunning(),
            'No Workerman worker runs under PHPUnit; the async branch must not be selected.'
        );
    }

    /**
     * SV-0.4: PHPUnit runs on the main (non-coroutine) stack, so the Channel
     * guard must report false — this is precisely what forces the clients onto
     * the blocking path and keeps Channel::pop() out of a non-coroutine context.
     */
    public function testInCoroutineIsFalseOutsideCoroutine(): void
    {
        $this->assertFalse(
            WorkerContext::inCoroutine(),
            'Outside any coroutine the Channel guard must be false so no Channel::pop() is reached.'
        );
    }

    /**
     * SV-0.3/0.4 branch-selection guard via a real client seam.
     *
     * Outside a worker AND outside a coroutine, {@see WebhookHttpClient::post()}
     * must route to its blocking cURL path. An empty URL is a sentinel that ONLY
     * the blocking `postCurl()` path produces ("Empty URL"); the async Channel
     * path never returns that error. Asserting it proves the blocking branch was
     * chosen — i.e. the client did not attempt an invalid out-of-coroutine
     * Channel wait.
     */
    public function testWebhookClientTakesBlockingPathOutsideCoroutine(): void
    {
        $client = new WebhookHttpClient(1);

        $result = $client->post('', 'test.event', 'delivery-1', ['payload' => true]);

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Empty URL',
            $result['error'],
            'Outside a coroutine the client must take the blocking postCurl() path (empty-URL sentinel).'
        );
    }
}
