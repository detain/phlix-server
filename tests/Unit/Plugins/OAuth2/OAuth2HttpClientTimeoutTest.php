<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\OAuth2;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Runtime\WorkerContext;
use Phlix\Plugins\OAuth2\OAuth2HttpClient;
use Phlix\Plugins\Oidc\OidcHttpClient;

/**
 * Proves the bound on accepted blocking-I/O exception #2 actually FIRES.
 *
 * `OAuth2HttpClient::send()` routes to `requestCurl()` when there is no running
 * event loop / no coroutine, or when {@see \Phlix\Common\Http\EventLoopTls}
 * flags the URL. Production https OIDC traffic takes that branch. An "accepted
 * exception" with no enforced timeout is not bounded, so this file does not
 * assert that `CURLOPT_TIMEOUT` is *set* — it asserts that a request against a
 * peer which accepts the TCP connection and then never speaks actually RETURNS,
 * on time.
 *
 * ## Which branch runs, and why the scheme matters
 *
 * ⚠️ PHPUnit never enters a Swoole coroutine, so `WorkerContext::inCoroutine()`
 * is false and `send()` takes the cURL branch **for every URL here, http and
 * https alike** — asserted below rather than assumed. That is deliberate: the
 * cURL branch is the one whose bound is in question. The async branch is
 * unreachable from PHPUnit and this file claims no coverage of it.
 *
 * ⚠️ The **scheme decides which cURL option is under test**, and getting this
 * wrong makes the test vacuous. In libcurl `CURLOPT_CONNECTTIMEOUT` covers the
 * whole connection phase *including the TLS handshake*. Against a silent peer an
 * `https://` request therefore ends on `CURLOPT_CONNECTTIMEOUT` and passes even
 * with `CURLOPT_TIMEOUT` deleted — verified by mutation: deleting the
 * `CURLOPT_TIMEOUT` line left an https-only version of this test fully green.
 * A plain `http://` request has no handshake, so it completes the connection
 * phase from the listener's backlog and then waits for a response body, where
 * **only `CURLOPT_TIMEOUT` can end it**. Re-run under the same mutation, the
 * http case hung past 60 s. That is why the load-bearing case below is http.
 *
 * The peer is a listening socket that is never `accept()`ed: the kernel still
 * completes the TCP handshake from the backlog, so cURL gets a connection.
 *
 * ## How this file fails
 *
 * If the bound is gone, these tests do not report a failure — they **hang**, and
 * the CI job times out. That is the correct signal and not a flake: an unbounded
 * request has no deadline for the assertion to check. Do not "fix" a timeout
 * here by relaxing the bounds below; look for a missing `curl_setopt`.
 *
 * `CURLOPT_CONNECTTIMEOUT` is deliberately not pinned separately: with
 * `CURLOPT_TIMEOUT` present it is a narrower duplicate, so no case can
 * distinguish its removal. It is defence in depth, not the bound.
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

    private function silentUrl(string $scheme): string
    {
        return $scheme . '://127.0.0.1:' . $this->port . '/.well-known/openid-configuration';
    }

    public function test_the_curl_branch_is_the_one_under_test(): void
    {
        // send() is private; this is the condition it branches on. If PHPUnit
        // ever did run inside a coroutine, every timing assertion below would be
        // measuring the async path instead and would prove nothing about
        // CURLOPT_TIMEOUT.
        $this->assertFalse(
            WorkerContext::inCoroutine(),
            'PHPUnit is inside a coroutine — this file no longer covers the cURL branch.'
        );
    }

    public function test_response_wait_is_bounded_by_the_request_timeout(): void
    {
        // THE load-bearing case: plain http, so the connection phase succeeds and
        // the call can only be ended by CURLOPT_TIMEOUT.
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $response = $client->get($this->silentUrl('http'));
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response, 'A peer that never answers must yield a null response, not a hang.');
        $this->assertGreaterThanOrEqual(
            0.5,
            $elapsed,
            'Returned far too fast to have been the timeout — the request probably failed for '
            . 'an unrelated reason, so this case would not detect a missing CURLOPT_TIMEOUT.'
        );
        $this->assertLessThan(
            8.0,
            $elapsed,
            'The 1-second request timeout did not bound the response wait. CURLOPT_TIMEOUT is the '
            . 'only thing that ends this request; see docs/dev/BLOCKING_IO_EXCEPTIONS.md.'
        );
    }

    public function test_token_exchange_post_response_wait_is_bounded_too(): void
    {
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $response = $client->post($this->silentUrl('http'), 'grant_type=authorization_code&code=x');
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response);
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(8.0, $elapsed, 'The token-exchange POST response wait is unbounded.');
    }

    public function test_https_connection_phase_is_bounded(): void
    {
        // The production scheme. Weaker than the case above — a stalled TLS
        // handshake is ended by CURLOPT_CONNECTTIMEOUT as well — but it is the
        // shape production actually issues, so it is worth pinning that it
        // terminates at all rather than hanging the worker.
        $client = new OAuth2HttpClient(1);

        $started = hrtime(true);
        $response = $client->get($this->silentUrl('https'));
        $elapsed = (hrtime(true) - $started) / 1e9;

        $this->assertNull($response);
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(8.0, $elapsed);
    }

    public function test_oidc_client_inherits_the_same_bound(): void
    {
        // OidcHttpClient is an empty subclass; if the shared implementation ever
        // gets forked again (S48 review r1, Finding 6) this catches the fork.
        $client = new OidcHttpClient(1);

        $started = hrtime(true);
        $response = $client->get($this->silentUrl('http'));
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
