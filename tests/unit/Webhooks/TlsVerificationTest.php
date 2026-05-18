<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Webhooks;

use Phlex\Webhooks\Plugins\AbstractNotificationPlugin;
use Phlex\Webhooks\Plugins\ApprisePlugin;
use Phlex\Webhooks\Plugins\DiscordPlugin;
use Phlex\Webhooks\Plugins\MqttPlugin;
use Phlex\Webhooks\Plugins\NtfyPlugin;
use Phlex\Webhooks\Plugins\PushoverPlugin;
use Phlex\Webhooks\Plugins\SlackPlugin;
use Phlex\Webhooks\Plugins\TelegramPlugin;
use Phlex\Webhooks\WebhookEvent;
use Phlex\Webhooks\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Verifies that the webhook dispatcher and every notification plugin
 * build outbound stream contexts with TLS peer verification enabled.
 *
 * Without this, a MITM on the path between Phlex and Discord / Slack /
 * Telegram / etc. could tamper with notification payloads in flight or
 * forge responses. See post-O.7 wave 1 security audit, findings L.1 + L.2.
 */
final class TlsVerificationTest extends TestCase
{
    public function test_webhook_dispatcher_ssl_options_enable_peer_verification(): void
    {
        $db = $this->createMock(Connection::class);
        $dispatcher = new WebhookDispatcher($db);

        $ssl = $dispatcher->buildSslContextOptions([]);

        self::assertTrue($ssl['verify_peer']);
        self::assertTrue($ssl['verify_peer_name']);
        self::assertTrue($ssl['SNI_enabled']);
        self::assertSame(WebhookDispatcher::DEFAULT_CA_BUNDLE, $ssl['cafile']);
    }

    public function test_webhook_dispatcher_ssl_options_honors_ca_bundle_override(): void
    {
        $db = $this->createMock(Connection::class);
        $dispatcher = new WebhookDispatcher($db);

        $ssl = $dispatcher->buildSslContextOptions(['ca_bundle' => '/etc/phlex/private-ca.crt']);

        self::assertSame('/etc/phlex/private-ca.crt', $ssl['cafile']);
    }

    /**
     * @return array<string, array{class-string<AbstractNotificationPlugin>}>
     */
    public static function notificationPluginProvider(): array
    {
        return [
            'discord' => [DiscordPlugin::class],
            'slack' => [SlackPlugin::class],
            'telegram' => [TelegramPlugin::class],
            'ntfy' => [NtfyPlugin::class],
            'pushover' => [PushoverPlugin::class],
            'apprise' => [ApprisePlugin::class],
            'mqtt' => [MqttPlugin::class],
        ];
    }

    /**
     * @param class-string<AbstractNotificationPlugin> $pluginClass
     *
     * @dataProvider notificationPluginProvider
     */
    public function test_notification_plugin_ssl_options_enable_peer_verification(string $pluginClass): void
    {
        $plugin = new $pluginClass(['enabled' => true]);

        $method = new ReflectionMethod($plugin, 'buildSslContextOptions');
        $method->setAccessible(true);
        $ssl = $method->invoke($plugin, []);

        self::assertIsArray($ssl);
        self::assertTrue($ssl['verify_peer']);
        self::assertTrue($ssl['verify_peer_name']);
        self::assertTrue($ssl['SNI_enabled']);
        self::assertNotEmpty($ssl['cafile']);
    }

    public function test_notification_plugin_ssl_options_honors_per_provider_ca_bundle(): void
    {
        $plugin = new DiscordPlugin(['enabled' => true]);

        $method = new ReflectionMethod($plugin, 'buildSslContextOptions');
        $method->setAccessible(true);
        $ssl = $method->invoke($plugin, ['ca_bundle' => '/etc/phlex/private-ca.crt']);

        self::assertSame('/etc/phlex/private-ca.crt', $ssl['cafile']);
    }

    public function test_abstract_notification_default_ca_bundle_constant(): void
    {
        self::assertSame(
            '/etc/ssl/certs/ca-certificates.crt',
            AbstractNotificationPlugin::DEFAULT_CA_BUNDLE
        );
    }
}
