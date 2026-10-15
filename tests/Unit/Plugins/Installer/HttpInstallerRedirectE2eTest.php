<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;

/**
 * R20-02 follow-through (review campaign 2026-10-14): the redirect defense is
 * TWO layers and until now NEITHER had an end-to-end test —
 * HttpInstallerTest only reflection-calls the private header checker with
 * hand-built arrays. A live fetch through the real stream wrapper is the only
 * harness that can prove each layer does its job:
 *
 *  - layer 1 (the `max_redirects => 1` stream context): the wrapper must not
 *    REQUEST any redirect target. The sibling server logs every served URI;
 *    deleting this option lets PHP follow up to 20 hops, so the target hop
 *    appears in the hit log and these tests redden — that is the layer's teeth.
 *  - layer 2 (`assertNoRedirectResponses`): a surfaced 3xx status line must
 *    throw PluginInstallException naming the redirect. Deleting this call lets
 *    a non-followed 302 return an EMPTY-BODY "success" (PHP answers the 302
 *    itself once layer 1 stops it, and 302 is not >= 400, so the fetch-error
 *    arm alone cannot catch it) — the exception assertions redden.
 *
 * Each layer therefore dies to its own deletion, and neither assertion is
 * satisfiable by the other layer (follow-to-200 without the checker, or reject
 * without stopping the follow, each still trip one pin).
 *
 * The subject method is private; it is invoked through ReflectionMethod —
 * the same seam the sibling suite already uses for the checker. The URL is a
 * loopback `php -S` fixture started per test, mirroring the venue idiom of
 * AccessScheduleHeadNoBodyWireTest (`exec` reassignment so proc_terminate
 * signals the SERVER, not the shell).
 */
final class HttpInstallerRedirectE2eTest extends TestCase
{
    private string $root = '';

    private string $base = '';

    private string $serverDir = '';

    /** @var resource|null */
    private $serverProc = null;

    private int $serverPort = 0;

    private string $hitLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phlix_http_redirect_' . uniqid('', true);
        $this->base = $this->root . '/var/plugins';
        $this->serverDir = $this->root . '/server';
        $this->hitLog = $this->root . '/hits.log';
        mkdir($this->base, 0775, true);
        mkdir($this->serverDir, 0775, true);
        file_put_contents($this->serverDir . '/router.php', $this->routerScript());
        touch($this->hitLog);

        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->root !== '') {
            RecursiveDelete::remove($this->root);
        }
        parent::tearDown();
    }

    private function routerScript(): string
    {
        // The hit-log path is baked in with var_export (the php -S router
        // receives no usable $argv for extra args). Every served request
        // appends its path there.
        $hitsExport = var_export($this->hitLog, true);

        return <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
file_put_contents({$hitsExport}, \$uri . "\\n", FILE_APPEND);
if (\$uri === '/redirect') {
    header('Location: /target', true, 302);
    exit;
}
if (\$uri === '/chain1') {
    header('Location: /chain2', true, 302);
    exit;
}
if (\$uri === '/chain2') {
    header('Location: /chain3', true, 302);
    exit;
}
if (\$uri === '/chain3') {
    header('Location: /target', true, 302);
    exit;
}
if (\$uri === '/gone') {
    http_response_code(404);
    echo 'missing';
    exit;
}
echo 'PAYLOAD-OK';
PHP;
    }

    private function startServer(): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            $out = $this->root . "/server-{$attempt}.out";

            // ⚠ `exec ` is load-bearing (house S315 law, see
            // AccessScheduleHeadNoBodyWireTest): /bin/sh does not optimise the
            // fork away, so without it proc_terminate() would signal the shell
            // while php -S keeps serving.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                '-S',
                "127.0.0.1:{$port}",
                $this->serverDir . '/router.php',
                $this->hitLog,
            ]));

            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes,
            );
            $this->assertIsResource($proc, 'proc_open() refused to start the redirect fixture server');
            $this->serverProc = $proc;
            $this->serverPort = $port;

            if ($this->waitForReady()) {
                return;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer();
        }

        $this->fail("the redirect fixture server never became ready.\n{$lastError}");
    }

    private function waitForReady(): bool
    {
        $deadline = microtime(true) + 10.0;
        $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
        while (microtime(true) < $deadline) {
            if ($this->serverProc !== null && proc_get_status($this->serverProc)['running'] === false) {
                return false;
            }
            $body = @file_get_contents("http://127.0.0.1:{$this->serverPort}/ok", false, $context);
            if ($body === 'PAYLOAD-OK') {
                // The readiness probe itself hit /ok — reset the ledger so each
                // arm asserts on requests IT made.
                $this->resetHits();

                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function stopServer(): void
    {
        if ($this->serverProc === null) {
            return;
        }
        $status = proc_get_status($this->serverProc);
        if ($status['running'] === true) {
            proc_terminate($this->serverProc, SIGTERM);
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && proc_get_status($this->serverProc)['running'] === true) {
                usleep(50_000);
            }
            if (proc_get_status($this->serverProc)['running'] === true) {
                proc_terminate($this->serverProc, SIGKILL);
                usleep(200_000);
            }
        }
        proc_close($this->serverProc);
        $this->serverProc = null;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, "could not reserve a port: {$errstr} ({$errno})");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    /** @return list<string> paths the fixture server actually served */
    private function hits(): array
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($this->hitLog)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    private function resetHits(): void
    {
        file_put_contents($this->hitLog, '');
    }

    private function installer(): HttpInstaller
    {
        return new HttpInstaller($this->base, $this->createMock(StructuredLogger::class));
    }

    /**
     * Run the PRIVATE downloadToTemp against the live fixture. Returns either
     * the temp-file path (caller unlinks) or the thrown exception.
     *
     * @return string|PluginInstallException
     */
    private function download(string $path)
    {
        $installer = $this->installer();
        $method = new \ReflectionMethod(HttpInstaller::class, 'downloadToTemp');
        $method->setAccessible(true);

        try {
            $result = $method->invoke($installer, 'http://127.0.0.1:' . $this->serverPort . $path);
            $this->assertIsString($result);

            return $result;
        } catch (PluginInstallException $e) {
            return $e;
        }
    }

    public function testSingleHopRedirectIsRejectedAndTheTargetIsNeverRequested(): void
    {
        $result = $this->download('/redirect');

        // Layer 2 teeth: WITHOUT assertNoRedirectResponses the fetch would
        // "succeed" with an empty body (302 is neither false-bytes nor >=400).
        $this->assertInstanceOf(PluginInstallException::class, $result);
        $this->assertStringContainsString('HTTP redirects are not allowed', $result->getMessage());
        $this->assertStringContainsString('302', $result->getMessage());

        // Layer 1 teeth: WITHOUT max_redirects=1 the wrapper would follow the
        // hop (default 20) and /target would appear in the server-side log.
        $this->assertSame(['/redirect'], $this->hits(), 'the stream wrapper must not fetch the redirect target');
    }

    public function testRedirectChainStopsAtTheFirstHop(): void
    {
        $result = $this->download('/chain1');

        $this->assertInstanceOf(PluginInstallException::class, $result);
        $this->assertStringContainsString('HTTP redirects are not allowed', $result->getMessage());
        $this->assertSame(['/chain1'], $this->hits(), 'not one hop of a multi-hop chain may be requested');
    }

    public function testPlain200DownloadStillWorksOnThisVenue(): void
    {
        $result = $this->download('/ok');

        // Vacuity + blanket-rejection control: the plumbing genuinely fetches,
        // and neither redirect layer fires on a clean 200.
        $this->assertIsString($result);
        try {
            $this->assertSame('PAYLOAD-OK', file_get_contents($result));
        } finally {
            @unlink($result);
        }
        $this->assertSame(['/ok'], $this->hits());
    }

    public function testHttpErrorStatusIsRejectedByTheFetchGate(): void
    {
        $result = $this->download('/gone');

        // Pins the isHttpErrorStatus(terminalResponseStatus(...)) arm e2e:
        // deleting it would store the 404's BODY as a "downloaded artifact".
        $this->assertInstanceOf(PluginInstallException::class, $result);
        $this->assertStringContainsString('Failed to fetch plugin source', $result->getMessage());
        $this->assertStringNotContainsString('redirects', $result->getMessage());
        $this->assertSame(['/gone'], $this->hits());
    }
}
