<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use PHPUnit\Framework\TestCase;
use Phlix\Hub\HttpClient;
use Phlix\Hub\HttpResponse;
use RuntimeException;

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

    // ---- audit §3.2/§5.2: explicit CA anchor is never silently dropped -----

    /**
     * The §3.2 cure at the server's own TLS decision point. Historical defect:
     * `is_file(...) ? set cafile : silently drop` — an operator-set
     * PHLIX_HUB_TLS_CAFILE that rotates away (dated copy deleted, bundle
     * moved) fell back to the system trust store with ZERO log lines, turning
     * a private-CA posture into silent theater. Law: explicit choice that is
     * not a readable file = refusal that names the path, the source, the
     * system-store consequence, and both exits. These tests call
     * tlsContext() directly — deleting the guard call line reddens them
     * (call-site pin, hub-twin DD lesson (a)).
     */
    public function test_explicit_env_cafile_that_does_not_exist_refuses_loud(): void
    {
        $ghost = sys_get_temp_dir() . '/phlix-ca-ghost-' . bin2hex(random_bytes(6)) . '.pem';
        putenv(HttpClient::ENV_TLS_CAFILE . '=' . $ghost);
        $client = new HttpClient('https://hub.example.com');

        try {
            $client->tlsContext();
            $this->fail('an explicitly configured unreadable CA bundle must refuse, not fall through');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString($ghost, $message, 'refusal must name the path');
            $this->assertStringContainsString(HttpClient::ENV_TLS_CAFILE, $message, 'refusal must name the knob');
            $this->assertStringContainsString('not a readable file', $message);
            $this->assertStringContainsString('system trust store', $message, 'refusal must name the consequence it refuses');
            $this->assertStringContainsString('CA/root certificates', $message, '§5.2: root-not-leaf anchor law in the text');
            $this->assertStringContainsString('leaf/fullchain', $message);
            $this->assertStringContainsString('~60 days', $message);
        }
    }

    public function test_explicit_constructor_cafile_that_does_not_exist_names_its_source(): void
    {
        $ghost = sys_get_temp_dir() . '/phlix-ca-ghost-' . bin2hex(random_bytes(6)) . '.pem';
        $client = new HttpClient('https://hub.example.com', null, 30, true, $ghost);

        try {
            $client->tlsContext();
            $this->fail('a $tlsCafile argument pointing at nothing must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('$tlsCafile constructor argument', $e->getMessage());
        }
    }

    public function test_explicit_cafile_exists_but_unreadable_refuses_deterministically(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'phlix-ca-');
        $this->assertIsString($ca);
        chmod($ca, 0000);

        $client = new HttpClient('https://hub.example.com', null, 30, true, $ca);
        try {
            if (is_readable($ca)) {
                // Superuser venue: 0000 still reads, so the guard's predicate
                // (is_readable) is honestly true and the context passes the
                // bundle through. Deterministic assertion either way — never
                // a vacuous skip (hub-twin DD lesson (d)).
                $ssl = $client->tlsContext()['ssl'];
                $this->assertSame($ca, $ssl['cafile']);
            } else {
                $this->expectException(RuntimeException::class);
                $client->tlsContext();
            }
        } finally {
            chmod($ca, 0600);
            @unlink($ca);
        }
    }

    public function test_unset_cafile_keeps_the_system_store_tolerance(): void
    {
        // The explicit/default asymmetry: the SHIPPED default path is not an
        // operator choice, so its absence (odd distros, slim images) keeps
        // the historical silent system-store behavior — no throw either way
        // (audit §5.1: system store is the clean default).
        $client = new HttpClient('https://hub.example.com');

        $ssl = $client->tlsContext()['ssl'];

        $this->assertTrue($ssl['verify_peer']);
        if (is_file(HttpClient::DEFAULT_TLS_CAFILE)) {
            $this->assertSame(HttpClient::DEFAULT_TLS_CAFILE, $ssl['cafile']);
        } else {
            $this->assertArrayNotHasKey('cafile', $ssl, 'absent default must fall through to the system store');
        }
    }

    public function test_verification_optout_never_consults_the_cafile(): void
    {
        // An explicit verify opt-out is a different operator decision; the
        // anchor refusal must not ambush it.
        putenv(HttpClient::ENV_TLS_VERIFY . '=0');
        putenv(HttpClient::ENV_TLS_CAFILE . '=/nonexistent/phlix-ghost-ca.pem');
        $client = new HttpClient('https://hub.example.com');

        $ssl = $client->tlsContext()['ssl'];

        $this->assertFalse($ssl['verify_peer']);
        $this->assertArrayNotHasKey('cafile', $ssl);
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
