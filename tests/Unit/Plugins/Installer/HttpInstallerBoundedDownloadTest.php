<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;

/**
 * M-SRV-38 (review campaign 2026-10-28, server__srphp M2): the installer used to
 * pull the whole remote artifact into worker memory with ONE uncapped
 * `file_get_contents()` and no named timeout — a hostile/compromised release
 * host answering with a multi-hundred-MB stream became one PHP string of that
 * size *plus* a second copy via `file_put_contents`, and the digest gate could
 * only reject the content AFTER the full body was already resident.
 *
 * The cure is the estate's own bounded-fetch idiom (the register's Exception 4
 * shape, `SsdpDiscovery::fetchOnceBounded()`): open with an explicit
 * `timeout`, reject a declared `Content-Length` over the cap BEFORE any body
 * is read, pump with a byte budget that stops at MAX_PACKAGE_BYTES + 1, and
 * release the temp file on overflow.
 *
 * The pins and what each kills:
 *  - testDeclaredContentLengthOverCapIsRejected — the header pre-check arm. On
 *    the parent this endpoint was a SUCCESSFUL 3-byte "download" (a forged
 *    Content-Length never reaches the byte budget through the old code at all),
 *    so the exception-type assertion is the red. Deleting only the pre-check
 *    redds it via the message: the body is 'abc' (3 bytes), so the capped pump
 *    would accept it and return the stub path.
 *  - testUnboundedStreamIsCutAtTheByteBudget — the pump arm (chunked body, no
 *    declared length). On the parent the whole 72 MiB became an in-memory
 *    string and the call returned normally; the exception assertion is the red.
 *  - the zero-residue ledger in both rejection arms kills deletion of the
 *    overflow `@unlink()` (house S439 discipline: the redirect path already
 *    learned this the hard way).
 *  - testStreamFetchOptionsCarryAnExplicitTimeoutAndZeroFollowBudget — the
 *    "named, not defaulted" arm: deleting `'timeout'` from the options, or
 *    rotating its value, or raising `max_redirects` off 1 redds it. The value
 *    is byte-pinned at 30 exactly like the sibling suites pin their law
 *    constants (a silent 0 — infinite — or 60 — inherited — is a law change).
 *  - testPumpArgumentIsOneBytePastThePackageCap — the budget ARGUMENT arm: an
 *    infinite stream can only be bounded by the read cap, which no behavioral
 *    leg can observe against a finite fixture, so it is pinned by source
 *    equality (house shape: the S440 guard's run-line pin).
 *  - testContentLengthExactlyAtTheCapIsAccepted — the boundary is INCLUSIVE:
 *    flipping the pre-check from `>` to `>=` redds it.
 *  - testSmallBodyStillStreamsToTheTempFile — vacuity control: the plumbing
 *    still fetches; a blanket rejection cannot satisfy the register quietly.
 *
 * Venue mirrors the sibling HttpInstallerRedirectE2eTest (loopback `php -S`,
 * `exec` reassignment so proc_terminate signals the SERVER — house S315 law —
 * per-test fixture, hit-log ledger). The subject method is private and runs
 * through ReflectionMethod, the same seam that suite uses.
 */
final class HttpInstallerBoundedDownloadTest extends TestCase
{
    private const MAX_PACKAGE_BYTES = 67108864;

    private const FETCH_TIMEOUT_SECONDS = 30;

    private string $root = '';

    private string $base = '';

    private string $serverDir = '';

    /** @var resource|null */
    private $serverProc = null;

    private int $serverPort = 0;

    private string $hitLog = '';

    /** @var list<string> phlix_plugin_* files present before each download call */
    private array $tempLedgerBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phlix_http_bounded_' . uniqid('', true);
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
        // appends its path there. The caps are baked in as plain literals:
        // the fixture must not import production constants, or a rotation of
        // the constant would silently rotate the fixture's meaning too.
        $hitsExport = var_export($this->hitLog, true);
        $cap = self::MAX_PACKAGE_BYTES;
        $overCap = $cap + 1;

        return <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
file_put_contents({$hitsExport}, \$uri . "\\n", FILE_APPEND);
if (\$uri === '/claims-huge') {
    header('Content-Length: 99999999999');
    echo 'abc';
    exit;
}
if (\$uri === '/claims-at-cap') {
    header('Content-Length: {$cap}');
    echo 'abc';
    exit;
}
if (\$uri === '/claims-over-cap') {
    header('Content-Length: {$overCap}');
    echo 'abc';
    exit;
}
if (\$uri === '/unbounded') {
    // No Content-Length: chunked output, 9 x 8 MiB = 72 MiB total — just over
    // the byte budget with a margin. connection_aborted() ends the pump as
    // soon as the client stops reading; the loop ceiling keeps the fixture
    // self-terminating even if the abort signal lags.
    header('Content-Type: application/octet-stream');
    for (\$i = 0; \$i < 9; \$i++) {
        echo str_repeat('A', 8388608);
        flush();
        if (connection_aborted()) {
            break;
        }
    }
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

            // `exec ` is load-bearing (house S315 law, see the sibling suite):
            // without it proc_terminate() would signal the shell while
            // php -S keeps serving.
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
            $this->assertIsResource($proc, 'proc_open() refused to start the bounded-download fixture server');
            $this->serverProc = $proc;
            $this->serverPort = $port;

            if ($this->waitForReady()) {
                return;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer();
        }

        $this->fail("the bounded-download fixture server never became ready.\n{$lastError}");
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

    /** @return list<string> */
    private function tempLedger(): array
    {
        $found = glob(sys_get_temp_dir() . '/phlix_plugin_*') ?: [];
        sort($found);

        return $found;
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
        $this->tempLedgerBefore = $this->tempLedger();

        try {
            $result = $method->invoke($installer, 'http://127.0.0.1:' . $this->serverPort . $path);
            $this->assertIsString($result);

            return $result;
        } catch (PluginInstallException $e) {
            return $e;
        }
    }

    /**
     * Assert the rejection arm fully: exception of the right law, its message,
     * the single served hit, and zero new temp residue. The result path is
     * released in `finally` so a RED parent run leaves no multi-MB litter
     * behind.
     *
     * @param callable(PluginInstallException): void $messageLaw
     */
    private function assertRejected(string $path, callable $messageLaw): void
    {
        $result = $this->download($path);
        try {
            $this->assertInstanceOf(PluginInstallException::class, $result, "over-cap fetch of {$path} must throw, got a stored artifact");
            $messageLaw($result);
        } finally {
            if (is_string($result)) {
                @unlink($result);
            }
        }

        $this->assertSame([$path], $this->hits(), "the fixture must serve exactly {$path} for this arm");
        $this->assertSame($this->tempLedgerBefore, $this->tempLedger(), 'a refused download must leave zero phlix_plugin_* residue');
    }

    public function testStreamFetchOptionsCarryAnExplicitTimeoutAndZeroFollowBudget(): void
    {
        $method = new \ReflectionMethod(HttpInstaller::class, 'streamFetchOptions');
        $method->setAccessible(true);
        /** @var array<string, array<string, mixed>> $options */
        $options = $method->invoke(null);

        $this->assertArrayHasKey('http', $options);
        // The named bound (M-SRV-38): a timeout must be IN the options array,
        // not inherited from default_socket_timeout. Byte-pinned at 30 exactly
        // like the sibling suites pin their law constants — rotating the value
        // is a law change and must redden here, not drift.
        $this->assertArrayHasKey('timeout', $options['http'], "M-SRV-38: the fetch context must carry an explicit 'timeout'");
        $this->assertSame(self::FETCH_TIMEOUT_SECONDS, $options['http']['timeout']);
        // R20-02 layer 1 stays visible from here too: raising max_redirects off
        // 1 re-enables wrapper-followed SSRF hops and must redden at the shape.
        $this->assertSame(1, $options['http']['max_redirects']);
        $this->assertTrue($options['http']['ignore_errors']);
    }

    public function testPumpArgumentIsOneBytePastThePackageCap(): void
    {
        // Source-equality pin (house shape: the S440 guard's run-line pin, the
        // redirect suite's context-shape law): the THIRD argument of the pump
        // — the per-fetch read budget — is invisible to every behavioral leg,
        // because a finite server stream still trips the post-copy check with
        // the budget stripped. But the budget is what bounds an INFINITE
        // stream: `MAX_PACKAGE_BYTES + 1` never reads more than 64 MiB + 1
        // no matter what the host keeps sending. Mutating the argument to -1
        // (unbounded) or to a fatter literal reddens HERE, by string
        // equality, without needing a hostile 10 GB fixture.
        $method = new \ReflectionMethod(HttpInstaller::class, 'downloadToTemp');
        $source = (string) file_get_contents((string) $method->getFileName());

        $this->assertSame(
            1,
            preg_match(
                '/stream_copy_to_stream\(\$handle, \$out, self::MAX_PACKAGE_BYTES \+ 1\)/',
                $source,
            ),
            'the download pump must carry the MAX_PACKAGE_BYTES + 1 byte budget (M-SRV-38)',
        );
    }

    public function testDeclaredContentLengthOverCapIsRejected(): void
    {
        // Header pre-check arm. On the parent this leg was a SUCCESSFUL 3-byte
        // download: @file_get_contents returns the short body and the wrapper's
        // length mismatch is swallowed by the @, so the forged
        // 99999999999-byte declaration never reached any check at all.
        $this->assertRejected('/claims-huge', function (PluginInstallException $e): void {
            $this->assertStringContainsString('exceeds the ' . self::MAX_PACKAGE_BYTES . '-byte package size limit', $e->getMessage());
            $this->assertStringContainsString('99999999999', $e->getMessage());
        });
    }

    public function testDeclaredContentLengthOneByteOverCapIsRejected(): void
    {
        // The boundary is exactly at MAX_PACKAGE_BYTES: a cap+1 declaration is
        // refused, so the comparison is `>` and the arithmetic is off-by-one
        // clean on both sides of the constant.
        $this->assertRejected('/claims-over-cap', function (PluginInstallException $e): void {
            $this->assertStringContainsString('exceeds the ' . self::MAX_PACKAGE_BYTES . '-byte package size limit', $e->getMessage());
            $this->assertStringContainsString((string) (self::MAX_PACKAGE_BYTES + 1), $e->getMessage());
        });
    }

    public function testContentLengthExactlyAtTheCapIsAccepted(): void
    {
        // Inclusive-boundary pin: `>` flipped to `>=` → this reddens (the
        // pre-check would refuse the declaration and this leg gets an
        // exception instead of a path). The declared size EQUALS the cap, the
        // served body is 3 bytes, the fetch must succeed.
        $result = $this->download('/claims-at-cap');
        try {
            $this->assertIsString($result, 'a declaration exactly AT the cap is within the law');
            $this->assertSame('abc', file_get_contents($result));
        } finally {
            if (is_string($result)) {
                @unlink($result);
            }
        }
    }

    public function testUnboundedStreamIsCutAtTheByteBudget(): void
    {
        // Pump arm: chunked body with NO declared length — only the byte
        // budget can stop it. On the parent the whole 72 MiB became an
        // in-memory string and the call RETURNED normally; the exception-type
        // assertion is the red. Deleting the unlink turns the residue ledger
        // into the second red.
        $this->assertRejected('/unbounded', function (PluginInstallException $e): void {
            $this->assertStringContainsString('exceeds the ' . self::MAX_PACKAGE_BYTES . '-byte package size limit', $e->getMessage());
        });
    }

    public function testSmallBodyStillStreamsToTheTempFile(): void
    {
        // Vacuity + blanket-rejection control: the plumbing genuinely fetches
        // within budget and the staged content is byte-faithful.
        $result = $this->download('/ok');
        try {
            $this->assertIsString($result);
            $this->assertSame('PAYLOAD-OK', file_get_contents($result));
        } finally {
            if (is_string($result)) {
                @unlink($result);
            }
        }

        $this->assertSame(['/ok'], $this->hits());
        $this->assertSame($this->tempLedgerBefore, $this->tempLedger());
    }
}
