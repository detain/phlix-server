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
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Webhooks\Plugins\ApprisePlugin;
use Phlix\Webhooks\Plugins\DiscordPlugin;
use Phlix\Webhooks\Plugins\NtfyPlugin;
use Phlix\Webhooks\Plugins\PushoverPlugin;
use Phlix\Webhooks\Plugins\SlackPlugin;
use Phlix\Webhooks\Plugins\TelegramPlugin;
use Phlix\Webhooks\WebhookEvent;

/**
 * The notification-plugin family gains the moment-of-use SSRF gate the
 * webhook surfaces proved (SRV-M20, review server__secaudit major: the
 * Slack/Discord/ntfy/Apprise/Telegram/Pushover plugins POSTed to URLs read
 * from configuration with no re-validation while MqttPlugin guarded its
 * broker URL).
 *
 * The conviction harness is stronger than the boolean refusal the M20-era
 * MqttPluginSsrfTest pinned: http/https are swapped for {@see
 * NotificationEgressSpy}, so every test measures the transport itself.
 * A poisoned destination that merely *returns false* but still knocks on
 * the socket — exactly what every unguarded plugin did at the parent of
 * this fix — is convicted by the spy's record, and the public-URL legs
 * pin that the guard never became a kill switch. IP-literal destinations
 * need no DNS (SsrfGuard resolves literals inline), and hostname legs
 * replace the resolver, so the whole file runs network-free under the
 * parallel-8 unit bucket.
 */

// The PHP stream-wrapper API dictates the snake_case method name below
// (`stream_open`): PHP looks it up by that exact name. The tests ruleset
// already excludes the PSR-1 naming and multiple-classes sniffs for the
// reason CountingStreamWrapper documents.
final class NotificationEgressSpy
{
    /** @var list<string> Transport attempts, in order. */
    public static array $paths = [];

    /** @var resource|null Set by PHP for stream contexts. */
    public $context;

    public static function reset(): void
    {
        self::$paths = [];
    }

    /**
     * @param string      $path      Requested URL.
     * @param string      $mode      fopen mode (unused — nothing is opened).
     * @param int         $options   Wrapper options (unused).
     * @param string|null $openedPath Out-param (unused).
     *
     * @return bool always false: record the attempt, refuse the transport.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($mode, $options, $openedPath);
        self::$paths[] = $path;

        return false;
    }
}

final class NotificationPluginSsrfParityTest extends TestCase
{
    /** Public IPv4 used for allowed-host legs (example.com; never contacted). */
    private const PUBLIC_IP = '93.184.216.34';

    private string $tempDir;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationEgressSpy::reset();
        stream_wrapper_unregister('http');
        stream_wrapper_unregister('https');
        stream_wrapper_register('http', NotificationEgressSpy::class);
        stream_wrapper_register('https', NotificationEgressSpy::class);

        // Captured-logger config so refusals are asserted on their log line
        // (same shape AdminSettingsControllerErrorEnvelopeTest writes).
        LoggerFactory::reset();
        $this->tempDir = sys_get_temp_dir() . '/srv_ssrf_parity_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);
        $this->logPath = $this->tempDir . '/app.log';
        file_put_contents(
            $this->tempDir . '/logger.php',
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
        LoggerFactory::init($this->tempDir . '/logger.php');
    }

    protected function tearDown(): void
    {
        stream_wrapper_restore('http');
        stream_wrapper_restore('https');
        SsrfGuard::reset();
        NotificationEgressSpy::reset();
        LoggerFactory::reset();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Refused destinations: poisoned configured URLs (link-local/loopback
    // literals). Red at the parent commit: no guard existed, so the spy
    // recorded an egress attempt for every leg.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function poisonedConfiguredUrlProvider(): array
    {
        return [
            'slack metadata-literal webhook' => [
                SlackPlugin::class,
                ['enabled' => true, 'webhook_url' => 'http://169.254.169.254/services/T0/B0/secret'],
                'http://169.254.169.254/services/T0/B0/secret',
            ],
            'discord loopback webhook' => [
                DiscordPlugin::class,
                ['enabled' => true, 'webhook_url' => 'http://127.0.0.1:9999/api/webhooks/1/tok'],
                'http://127.0.0.1:9999/api/webhooks/1/tok',
            ],
            'apprise private-range url' => [
                ApprisePlugin::class,
                ['enabled' => true, 'url' => 'http://10.0.0.7:8080/notify'],
                'http://10.0.0.7:8080/notify',
            ],
            'ntfy poisoned server (composed url)' => [
                NtfyPlugin::class,
                ['enabled' => true, 'topic' => 'phlix', 'server' => 'http://169.254.169.254'],
                'http://169.254.169.254/phlix',
            ],
        ];
    }

    /**
     * @param class-string             $pluginClass
     * @param array<string, mixed>     $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('poisonedConfiguredUrlProvider')]
    public function test_poisoned_configured_url_is_refused_with_zero_egress(
        string $pluginClass,
        array $config,
        string $expectedUrl,
    ): void {
        self::assertFalse((new $pluginClass($config))->send($this->event()));
        // The conviction: at the parent this egress attempt WAS made.
        self::assertSame([], NotificationEgressSpy::$paths, "unguarded egress attempted to {$expectedUrl}");
    }

    // ------------------------------------------------------------------
    // Refused destinations: DNS rebind of a fixed/legit host — covers ALL
    // six plugins, including Telegram and Pushover whose hosts are
    // structural (the moment-of-use check is what closes that class).
    // Red at the parent for every leg: unguarded, the spy recorded it.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>, 2: string}>
     */
    public static function rebindingHostProvider(): array
    {
        return [
            'slack' => [
                SlackPlugin::class,
                ['enabled' => true, 'webhook_url' => 'https://hooks.slack.com/services/T0/B0/secret'],
                'https://hooks.slack.com/services/T0/B0/secret',
            ],
            'discord' => [
                DiscordPlugin::class,
                ['enabled' => true, 'webhook_url' => 'https://discord.com/api/webhooks/123/tok'],
                'https://discord.com/api/webhooks/123/tok',
            ],
            'ntfy (default server)' => [
                NtfyPlugin::class,
                ['enabled' => true, 'topic' => 'phlix'],
                'https://ntfy.sh/phlix',
            ],
            'apprise' => [
                ApprisePlugin::class,
                ['enabled' => true, 'url' => 'https://apprise.example.com/notify'],
                'https://apprise.example.com/notify',
            ],
            'telegram (fixed host)' => [
                TelegramPlugin::class,
                ['enabled' => true, 'bot_token' => '123:ABC', 'chat_id' => '42'],
                'https://api.telegram.org/bot123:ABC/sendMessage',
            ],
            'pushover (fixed host)' => [
                PushoverPlugin::class,
                ['enabled' => true, 'user_key' => 'user', 'api_token' => 'token'],
                // The plugin's own private const, mirrored by literal: the
                // spy must see exactly the URL the transport receives.
                'https://api.pushover.net/1/messages.json',
            ],
        ];
    }

    /**
     * @param class-string         $pluginClass
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rebindingHostProvider')]
    public function test_dns_rebound_host_is_refused_with_zero_egress(
        string $pluginClass,
        array $config,
        string $expectedUrl,
    ): void {
        SsrfGuard::setResolver(static fn (string $host): array => ['169.254.169.254']);

        self::assertFalse((new $pluginClass($config))->send($this->event()));
        // The conviction: at the parent every one of the six knocked on the socket.
        self::assertSame([], NotificationEgressSpy::$paths, "unguarded egress attempted to {$expectedUrl}");
    }

    // ------------------------------------------------------------------
    // Invariant: the guard is not a kill switch. A publicly-resolving
    // destination must still reach the transport, once, at every plugin.
    // (Green at the parent too — this pins the fix against overreach.)
    // ------------------------------------------------------------------

    /**
     * @param class-string         $pluginClass
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rebindingHostProvider')]
    public function test_public_destination_still_reaches_the_transport(
        string $pluginClass,
        array $config,
        string $expectedUrl,
    ): void {
        SsrfGuard::setResolver(static fn (string $host): array => [self::PUBLIC_IP]);

        (new $pluginClass($config))->send($this->event());

        self::assertSame([$expectedUrl], NotificationEgressSpy::$paths);
    }

    // ------------------------------------------------------------------
    // Refusal shape: one logged warning naming the guard and the event,
    // never the URL — these URLs are credentials (webhook paths, the
    // bot token embedded in the Telegram path). Red at the parent: no
    // such refusal existed to log.
    // ------------------------------------------------------------------

    public function test_refusal_is_logged_without_the_destination_url(): void
    {
        $secretPath = 'services/T0/B0/SUPER-SECRET';
        $plugin = new SlackPlugin(['enabled' => true, 'webhook_url' => 'http://127.0.0.1/' . $secretPath]);

        self::assertFalse($plugin->send($this->event('library.updated')));

        $log = (string) @file_get_contents($this->logPath);
        self::assertStringContainsString('Slack notification blocked by SSRF guard', $log);
        self::assertStringContainsString('library.updated', $log);
        self::assertStringNotContainsString($secretPath, $log, 'destination path leaked into the log');
    }

    private function event(string $type = 'playback.started'): WebhookEvent
    {
        return new WebhookEvent($type, ['title' => 'Parity'], new DateTimeImmutable());
    }
}
