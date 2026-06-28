<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\MetadataHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * F2: every outbound metadata request must verify the TLS peer certificate and
 * hostname. Asserts the stream-context builder emits the hardened `ssl` block.
 */
final class MetadataHttpClientTlsTest extends TestCase
{
    public function test_stream_context_enables_tls_peer_verification(): void
    {
        $options = MetadataHttpClient::buildStreamContextOptions(['timeout' => 10]);

        self::assertArrayHasKey('ssl', $options);
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertTrue($options['ssl']['verify_peer_name']);
        self::assertFalse($options['ssl']['allow_self_signed']);
    }

    public function test_stream_context_preserves_http_block(): void
    {
        $http = ['timeout' => 7, 'ignore_errors' => true, 'header' => 'X-Test: 1'];
        $options = MetadataHttpClient::buildStreamContextOptions($http);

        self::assertSame($http, $options['http']);
    }
}
