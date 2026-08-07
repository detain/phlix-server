<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\OAuth2\OAuth2HttpClient;
use Phlix\Plugins\Oidc\OidcHttpClient;

/**
 * Proves the bound on accepted blocking-I/O exception #2 actually FIRES.
 *
 * `OAuth2HttpClient::send()` routes to `requestCurl()` when there is no running
 * event loop / no coroutine, or when {@see \Phlix\Common\Http\EventLoopTls}
 * flags the URL. Production https OIDC traffic takes that branch. An "accepted
 * exception" with no enforced timeout is not bounded, so this test does not
 * assert that `CURLOPT_TIMEOUT` is *set* — it asserts that a request against a
 * peer which accepts the TCP connection and then never speaks actually RETURNS,
 * on time.
 *
 * ⚠️ PHPUnit never enters a Swoole coroutine, so the branch taken here is the
 * cURL one via `!WorkerContext::inCoroutine()`. That is deliberate: the cURL
 * branch is precisely the one whose bound is in question. The async branch is
 * unreachable from PHPUnit and is not what this file claims to cover.
 *
 * The peer is a listening socket that is never `accept()`ed: the kernel still
 * completes the TCP handshake from the backlog, so cURL gets a connection and
 * then hangs waiting for the TLS ServerHello — i.e. `CURLOPT_CONNECTTIMEOUT`
 * is satisfied and only `CURLOPT_TIMEOUT` can end the call. Dropping
 * `CURLOPT_TIMEOUT` therefore hangs this test rather than silently passing it.
 *
 * @group slow
 */
final class OAuth2HttpClientTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('Cannot bind a loopback listener: ' . $errstr);
        }

        $name = stream_socket_get_name($server, false);
        if ($name === false) {
            fclose($server);
            $this->markTestSkipped('Cannot read the loopback listener address.');
        }

        $this->server = $server;
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $this->port);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        $this->server = null;
        parent::tearDown();
    }

    private function silentUrl(): string
    {
        return 'https://127.0.0.1:' . $this->port . '/.well-known/openid-configuration';
    }

    public function test_get_against_a_silent_peer_returns_null_on_the_configured_timeout(): void
    {
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $response = $client->get($this->silentUrl());
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response, 'A peer that never answers must yield a null response, not a hang.');
        $this->assertGreaterThanOrEqual(
            0.5,
            $elapsed,
            'Returned far too fast to have been the timeout — the request probably failed for '
            . 'an unrelated reason, so this test would not detect a missing CURLOPT_TIMEOUT.'
        );
        $this->assertLessThan(
            8.0,
            $elapsed,
            'The 1-second request timeout did not bound the call. CURLOPT_TIMEOUT is the only '
            . 'thing that ends this request; see docs/dev/BLOCKING_IO_EXCEPTIONS.md.'
        );
    }

    public function test_post_against_a_silent_peer_is_bounded_too(): void
    {
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $response = $client->post($this->silentUrl(), 'grant_type=authorization_code&code=x');
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response);
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(8.0, $elapsed, 'The token-exchange POST is unbounded.');
    }

    public function test_oidc_client_inherits_the_same_bound(): void
    {
        // OidcHttpClient is an empty subclass; if the shared implementation ever
        // gets forked again (S48 review r1, Finding 6) this catches the fork.
        $client = new OidcHttpClient(1);

        $started = hrtime(true);
        $response = $client->get($this->silentUrl());
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response);
        $this->assertLessThan(8.0, $elapsed);
    }

    public function test_empty_url_is_rejected_without_touching_the_network(): void
    {
        // Control: proves the elapsed-time assertions above are measuring a real
        // network wait, not a cheap early return that every case would pass.
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $this->assertNull($client->get(''));
        $this->assertLessThan(0.2, (hrtime(true) - $started) / 1e9);
    }
}
