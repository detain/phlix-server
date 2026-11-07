<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks;

use Phlix\Webhooks\WebhookDispatcher;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Verifies that the webhook dispatcher builds outbound stream contexts with
 * TLS peer verification enabled.
 *
 * Without this, a MITM on the path between Phlix and a subscription endpoint
 * could tamper with webhook payloads in flight or forge responses. See
 * post-O.7 wave 1 security audit, findings L.1 + L.2 — which also covered the
 * notification-plugin family; that family was retired (never wired into any
 * serving path) and its legs went with it, leaving this live dispatcher
 * coverage. The retirement itself is pinned by
 * {@see \Phlix\Tests\Unit\Webhooks\NotificationPluginFamilyRetirementTest}.
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

        $ssl = $dispatcher->buildSslContextOptions(['ca_bundle' => '/etc/phlix/private-ca.crt']);

        self::assertSame('/etc/phlix/private-ca.crt', $ssl['cafile']);
    }
}
