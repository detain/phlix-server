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

    /**
     * E-18: LocalAddressProbe's historical second copy of the predicate must
     * now be a delegate, not a re-derivation — the file may not mention
     * getCid() at all once settled.
     */
    public function testLocalAddressProbeDelegatesToWorkerContext(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Network/LocalAddressProbe.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'WorkerContext::inCoroutine()',
            $source,
            'LocalAddressProbe::inCoroutine() must delegate to the SSOT (E-18).'
        );
        $this->assertStringNotContainsString(
            'getCid',
            $source,
            'No second inline predicate may survive in LocalAddressProbe (E-18).'
        );
    }

    /**
     * E-18: the three Network clients that carried verbatim private delegate
     * copies must now route through WorkerContext (their private inCoroutine()
     * methods keep existing — the SH-007 surface test pins that — but their
     * bodies point at the SSOT).
     */
    public function testNetworkDelegatesRouteThroughWorkerContext(): void
    {
        foreach (['StunClient', 'UpnpIgdClient', 'PortForwardService'] as $carrier) {
            $source = file_get_contents(dirname(__DIR__, 4) . '/src/Network/' . $carrier . '.php');
            $this->assertIsString($source);
            $this->assertStringContainsString(
                'WorkerContext::inCoroutine()',
                $source,
                $carrier . ' must delegate to WorkerContext (E-18).'
            );
            $this->assertStringNotContainsString(
                'LocalAddressProbe::inCoroutine()',
                $source,
                $carrier . ' must not keep the old LocalAddressProbe hop (E-18).'
            );
        }
    }

    /**
     * E-18: RelayConsumer's inline coroutine bypass (the Channel-vs-synchronous
     * dispatch fork) is the sixth copy; it must read the SSOT predicate. Touch
     * is deliberately one expression — the relay file is high-traffic.
     */
    public function testRelayConsumerBypassUsesWorkerContext(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/Hub/RelayConsumer.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'WorkerContext::inCoroutine()',
            $source,
            'RelayConsumer must read the SSOT predicate (E-18).'
        );
        $this->assertStringNotContainsString(
            'Coroutine::getCid',
            $source,
            'RelayConsumer must not keep its own getCid() copy (E-18).'
        );
    }

    /**
     * E-18 behavior on this host: no Swoole at all — every migrated carrier
     * must agree with the SSOT and report false. The union semantics only
     * diverge from either original under a runtime that defines the
     * Swoole coroutine API without loading the extension; nothing here does
     * (see the WorkerContext class docblock for the settle analysis).
     */
    public function testAllCarriersAgreeFalseOnPlainRuntime(): void
    {
        $this->assertFalse(WorkerContext::inCoroutine());
        $this->assertFalse(\Phlix\Network\LocalAddressProbe::inCoroutine());
    }
}
