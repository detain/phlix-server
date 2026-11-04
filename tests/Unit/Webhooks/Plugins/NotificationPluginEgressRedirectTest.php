<?php

/**
 * Phlix media server test: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Webhooks\Plugins\AbstractNotificationPlugin;
use Phlix\Webhooks\Plugins\ApprisePlugin;
use Phlix\Webhooks\Plugins\DiscordPlugin;
use Phlix\Webhooks\Plugins\MqttPlugin;
use Phlix\Webhooks\Plugins\NtfyPlugin;
use Phlix\Webhooks\Plugins\SlackPlugin;
use Phlix\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The redirect leg of the family's egress law (review server__secaudit U1,
 * against 32cbd665): the moment-of-use guard vets the CONFIGURED URL, but
 * PHP's http wrapper follows up to 20 hops of whatever that endpoint
 * answers — a configured-public URL 302-ing to a denied range smuggled
 * bytes past a check that had passed at hop one. The cure is the transport
 * denial itself: every guarded context goes through
 * {@see AbstractNotificationPlugin::buildEgressHttpOptions()} with
 * follow_location=0 + max_redirects=0, and
 * {@see AbstractNotificationPlugin::egressSucceeded()} refuses any 3xx the
 * endpoint hands back with a logged warning that names no URL.
 *
 * Unlike the sibling spy suite, THIS file cannot fake the transport — the
 * class under test is the real wrapper's redirect behaviour — so it runs
 * the plugins' own file_get_contents() against a live loopback `php -S`
 * fixture (venue idiom copied from HttpInstallerRedirectE2eTest, incl. the
 * S315 `exec` law so proc_terminate signals the server, not the shell).
 * The fixture logs every path it SERVES; the conviction is that '/secret'
 * never appears there. The guard lets the fixture through via
 * SsrfGuard::setAllowedCidrs() — exactly the "initial URL passes the
 * guard" premise of the attack.
 *
 * Red at the parent commit, per leg:
 *  - the five redirect legs: the parent followed the hop, so '/secret' was
 *    served (and its body returned) AND no refusal line existed;
 *  - the 200-success leg: the parent's per-site getLastResponseCode() read
 *    `global $http_response_header` from a helper scope — the wrapper only
 *    populates it in the CALLER's locals — so even a real 200 reported
 *    failure (the same broken read the old success line stood on; this
 *    commit centralizes and repairs it, and this leg is its pin);
 *  - the builder pin: the method did not exist at the parent.
 *
 * The fixed-host plugins (Telegram, Pushover) cannot be pointed at the
 * fixture — their structural-leg redirect law is pinned through the single
 * source the builder test convicts.
 */
final class NotificationPluginEgressRedirectTest extends TestCase
{
    /** Marker body only ever served by the redirect TARGET path. */
    private const SECRET_BODY = 'SECRET-EXFIL-MARKER';

    private string $root = '';

    private string $hitLog = '';

    /** @var resource|null */
    private $serverProc = null;

    private int $serverPort = 0;

    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        // The guard must let the loopback fixture pass at check time — the
        // attack premise is a FIRST hop that passes while the second hop is
        // what the denial exists to stop.
        SsrfGuard::setAllowedCidrs(['127.0.0.0/8']);

        $this->root = sys_get_temp_dir() . '/phlix_egress_redirect_' . uniqid('', true);
        $this->hitLog = $this->root . '/hits.log';
        $serverDir = $this->root . '/server';
        mkdir($serverDir, 0775, true);
        file_put_contents($serverDir . '/router.php', $this->routerScript());
        touch($this->hitLog);

        // Captured-logger config (same shape as NotificationPluginSsrfParityTest)
        // so the refusal line and the no-URL law are asserted on real log bytes.
        LoggerFactory::reset();
        $this->logPath = $this->root . '/app.log';
        file_put_contents(
            $this->root . '/logger.php',
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->logPath, true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n",
        );
        LoggerFactory::init($this->root . '/logger.php');

        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        LoggerFactory::reset();
        SsrfGuard::reset();
        if ($this->root !== '') {
            RecursiveDelete::remove($this->root);
        }

        parent::tearDown();
    }

    /**
     * Router: /ok answers 200, /secret answers 200 with the marker body,
     * EVERY other path answers 302 → /secret — so each plugin's own initial
     * URL is a hop. Every served path is appended to the hit log the tests
     * read; the Location is rebuilt from the request's own Host so the
     * fixture needs no knowledge of its bound port.
     */
    private function routerScript(): string
    {
        $hitsExport = var_export($this->hitLog, true);
        $markerExport = var_export(self::SECRET_BODY, true);

        return <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
file_put_contents({$hitsExport}, \$uri . "\\n", FILE_APPEND);
if (\$uri === '/ok') {
    echo 'PAYLOAD-OK';
    exit;
}
if (\$uri === '/secret') {
    echo {$markerExport};
    exit;
}
header('Location: http://' . \$_SERVER['HTTP_HOST'] . '/secret', true, 302);
exit;
PHP;
    }

    private function startServer(): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            $out = $this->root . "/server-{$attempt}.out";

            // ⚠ `exec ` is load-bearing (house S315 law, see
            // HttpInstallerRedirectE2eTest): proc_terminate() must signal the
            // server, not the shell that spawned it.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                '-S',
                "127.0.0.1:{$port}",
                $this->root . '/server/router.php',
            ]));

            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes,
            );
            $this->assertIsResource($proc, 'proc_open() refused to start the egress redirect fixture server');
            $this->serverProc = $proc;
            $this->serverPort = $port;

            if ($this->waitForReady()) {
                return;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer();
        }

        $this->fail("the egress redirect fixture server never became ready.\n{$lastError}");
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
                // The readiness probe itself served /ok — reset the ledger so
                // each arm asserts only on requests IT made.
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
            while (microtime(true) < $deadline) {
                // Re-read every round: the child exits asynchronously and a
                // hoisted or assumed-constant read would poll a stale value.
                $status = proc_get_status($this->serverProc);
                if ($status['running'] !== true) {
                    break;
                }
                usleep(50_000);
            }
            $status = proc_get_status($this->serverProc);
            if ($status['running'] === true) {
                proc_terminate($this->serverProc, SIGKILL);
                usleep(200_000);
            }
        }
        proc_close($this->serverProc);
        $this->serverProc = null;
    }

    private function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, "could not reserve a port: {$errstr} ({$errno})");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    /** @return list<string> paths the fixture actually served */
    private function hits(): array
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($this->hitLog)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    private function resetHits(): void
    {
        file_put_contents($this->hitLog, '');
    }

    /**
     * Every configured-URL plugin, pointed at the fixture through its own
     * real config keys (ntfy/mqtt exercise the COMPOSED destination: the
     * path they knock on is server/broker + topic, built inside send()).
     * `%PORT%` is substituted per test once the fixture has bound.
     *
     * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function configuredEgressProvider(): array
    {
        return [
            'slack webhook path' => [
                SlackPlugin::class,
                ['enabled' => true, 'webhook_url' => 'http://127.0.0.1:%PORT%/services/T0/B0/secret-token'],
                '/services/T0/B0/secret-token',
            ],
            'discord webhook path' => [
                DiscordPlugin::class,
                ['enabled' => true, 'webhook_url' => 'http://127.0.0.1:%PORT%/api/webhooks/123/tok'],
                '/api/webhooks/123/tok',
            ],
            'apprise configured url' => [
                ApprisePlugin::class,
                ['enabled' => true, 'url' => 'http://127.0.0.1:%PORT%/notify'],
                '/notify',
            ],
            'ntfy composed server+topic' => [
                NtfyPlugin::class,
                ['enabled' => true, 'topic' => 'phlix', 'server' => 'http://127.0.0.1:%PORT%'],
                '/phlix',
            ],
            'mqtt composed broker+topic' => [
                MqttPlugin::class,
                ['enabled' => true, 'topic' => 'alerts', 'broker' => 'http://127.0.0.1:%PORT%'],
                '/alerts',
            ],
        ];
    }

    /**
     * Core U1 pin: a configured URL that PASSES the moment-of-use guard and
     * answers 302 → a second local path must never have that path fetched —
     * zero bytes out to the redirect target — and the 3xx itself must be
     * refused with a logged warning that names no URL.
     *
     * @param class-string         $pluginClass
     * @param array<string, mixed> $config
     * @param string               $initialPath
     */
    #[DataProvider('configuredEgressProvider')]
    public function test_redirect_from_guarded_egress_is_never_followed(
        string $pluginClass,
        array $config,
        string $initialPath,
    ): void {
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = str_replace('%PORT%', strval($this->serverPort), $value);
            }
        }

        self::assertFalse((new $pluginClass($config))->send($this->event()));

        // The conviction: at the parent the wrapper followed the hop and the
        // fixture served (and returned!) /secret. Zero bytes to the target
        // means the target was never REQUESTED at all.
        self::assertSame(
            [$initialPath],
            $this->hits(),
            'the stream wrapper must not request the redirect target',
        );

        $log = (string) @file_get_contents($this->logPath);
        $label = ucfirst($pluginClass::getName());
        self::assertStringContainsString($label . ' notification refused: HTTP redirect not followed', $log);
        self::assertStringContainsString('playback.started', $log);
        // The redaction law: neither the initial URL nor the smuggled target
        // ever reaches the log (both are capability-bearing strings).
        self::assertStringNotContainsString('127.0.0.1', $log);
        self::assertStringNotContainsString('/secret', $log);
    }

    /**
     * Positive control + repair pin: a guarded 200 answer now SUCCEEDS.
     * The parent read `global $http_response_header` from a helper method —
     * the wrapper only populates it in the caller's locals — so every send
     * reported failure even on a real 200. Red at the parent.
     */
    public function test_plain_200_on_the_guarded_path_reports_success(): void
    {
        $plugin = new SlackPlugin([
            'enabled' => true,
            'webhook_url' => "http://127.0.0.1:{$this->serverPort}/ok",
        ]);

        self::assertTrue($plugin->send($this->event()));
        self::assertSame(['/ok'], $this->hits());
    }

    /**
     * Single-source pin covering the fixed-host legs (Telegram/Pushover
     * cannot be dialled at the fixture): the builder ALWAYS carries the
     * denial, and a site option can never override a law key.
     */
    public function test_egress_http_options_carry_the_redirect_law(): void
    {
        $plugin = new SlackPlugin(['enabled' => true]);
        $method = new ReflectionMethod($plugin, 'buildEgressHttpOptions');
        $method->setAccessible(true);

        /** @var array<string, mixed> $options */
        $options = $method->invoke($plugin, [
            'method' => 'POST',
            'follow_location' => 20,
            'max_redirects' => 20,
        ]);

        self::assertSame(0, $options['follow_location']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(10, $options['timeout']);
        self::assertTrue($options['ignore_errors']);
        self::assertSame('POST', $options['method']);
    }

    private function event(string $type = 'playback.started'): WebhookEvent
    {
        return new WebhookEvent($type, ['title' => 'Redirect'], new DateTimeImmutable());
    }
}
