<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Webhooks\Plugins\MqttPlugin;
use Phlix\Webhooks\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * The MQTT plugin fetches an operator-supplied broker host via a raw
 * file_get_contents() — the most exposed outbound surface. Verify the shared
 * SSRF guard blocks private/loopback/metadata brokers before any request.
 *
 * The DNS-resolution seam is injected so the suite stays offline; a blocked
 * broker returns false WITHOUT performing a network fetch.
 */
final class MqttPluginSsrfTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    private function event(): WebhookEvent
    {
        return new WebhookEvent('playback.started', ['title' => 'X'], new DateTimeImmutable());
    }

    public function test_send_blocks_loopback_broker(): void
    {
        $plugin = new MqttPlugin([
            'enabled' => true,
            'broker' => 'http://127.0.0.1:8080',
            'topic' => 'phlix/events',
        ]);

        self::assertFalse($plugin->send($this->event()));
    }

    public function test_send_blocks_cloud_metadata_broker(): void
    {
        $plugin = new MqttPlugin([
            'enabled' => true,
            'broker' => '169.254.169.254',
            'topic' => 'phlix/events',
        ]);

        self::assertFalse($plugin->send($this->event()));
    }

    public function test_send_blocks_private_resolved_broker(): void
    {
        SsrfGuard::setResolver(static fn (string $host): array => ['10.0.0.5']);

        $plugin = new MqttPlugin([
            'enabled' => true,
            'broker' => 'broker.internal',
            'topic' => 'phlix/events',
        ]);

        self::assertFalse($plugin->send($this->event()));
    }
}
