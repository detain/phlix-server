<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpResponse;

class HttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(HttpClient::ENV_TLS_VERIFY);
        putenv(HttpClient::ENV_TLS_CAFILE);
        parent::tearDown();
    }

    public function test_hub_http_client_verifies_tls_by_default(): void
    {
        $client = new HttpClient('https://hub.example.com');

        $this->assertTrue($client->isTlsVerifyEnabled());

        $ssl = $client->tlsContext()['ssl'];
        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertArrayNotHasKey('allow_self_signed', $ssl);
    }

    public function test_hub_tls_verify_can_be_opted_out_via_env(): void
    {
        putenv(HttpClient::ENV_TLS_VERIFY . '=0');
        $client = new HttpClient('https://hub.example.com');

        $this->assertFalse($client->isTlsVerifyEnabled());

        $ssl = $client->tlsContext()['ssl'];
        $this->assertFalse($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
        $this->assertTrue($ssl['allow_self_signed']);
    }

    public function test_hub_tls_verify_typo_keeps_verification_on(): void
    {
        // A mistyped opt-out must not silently disable TLS verification.
        putenv(HttpClient::ENV_TLS_VERIFY . '=flase');
        $client = new HttpClient('https://hub.example.com');

        $this->assertTrue($client->isTlsVerifyEnabled());
    }

    public function test_hub_tls_cafile_is_configurable_via_env(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'phlix-ca-');
        $this->assertIsString($ca);

        putenv(HttpClient::ENV_TLS_CAFILE . '=' . $ca);
        $client = new HttpClient('https://hub.example.com');

        $ssl = $client->tlsContext()['ssl'];
        $this->assertSame($ca, $ssl['cafile']);

        @unlink($ca);
    }

    public function test_constructor_overrides_beat_env(): void
    {
        putenv(HttpClient::ENV_TLS_VERIFY . '=1');
        $client = new HttpClient('https://hub.example.com', null, 30, false);

        $this->assertFalse($client->isTlsVerifyEnabled());

        $ca = tempnam(sys_get_temp_dir(), 'phlix-ca-');
        $this->assertIsString($ca);
        $explicit = new HttpClient('https://hub.example.com', null, 30, true, $ca);
        $this->assertSame($ca, $explicit->tlsContext()['ssl']['cafile']);
        @unlink($ca);
    }
    public function test_httpResponse_isSuccess_true_for_2xx(): void
    {
        $response = new HttpResponse(200, [], ['ok' => true]);
        $this->assertTrue($response->isSuccess());

        $response = new HttpResponse(201, [], []);
        $this->assertTrue($response->isSuccess());

        $response = new HttpResponse(204, [], []);
        $this->assertTrue($response->isSuccess());
    }

    public function test_httpResponse_isSuccess_false_for_non_2xx(): void
    {
        $response = new HttpResponse(400, [], ['error' => 'BAD_REQUEST']);
        $this->assertFalse($response->isSuccess());

        $response = new HttpResponse(401, [], ['error' => 'UNAUTHORIZED']);
        $this->assertFalse($response->isSuccess());

        $response = new HttpResponse(500, [], ['error' => 'INTERNAL_ERROR']);
        $this->assertFalse($response->isSuccess());
    }

    public function test_httpResponse_getErrorCode_returns_error_field(): void
    {
        $response = new HttpResponse(400, [], ['error' => 'SERVER_KEY_INVALID', 'message' => 'Bad key']);
        $this->assertEquals('SERVER_KEY_INVALID', $response->getErrorCode());
    }

    public function test_httpResponse_getErrorCode_returns_null_when_no_error(): void
    {
        $response = new HttpResponse(200, [], ['ok' => true]);
        $this->assertNull($response->getErrorCode());
    }

    public function test_httpResponse_body_is_array(): void
    {
        $response = new HttpResponse(200, [], ['keys' => [['kty' => 'OKP']]]);
        $keys = $response->body['keys'];
        $this->assertIsArray($keys);
        $this->assertIsArray($keys[0]);
        $this->assertEquals('OKP', $keys[0]['kty']);
    }

    public function test_httpResponse_headers_are_accessible(): void
    {
        $response = new HttpResponse(200, ['content-type' => 'application/json', 'cache-control' => 'public'], []);
        $this->assertEquals('application/json', $response->headers['content-type']);
        $this->assertEquals('public', $response->headers['cache-control']);
    }
}
