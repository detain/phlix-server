<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Workerman\Http\Client;

/**
 * Lightweight HTTP client for hub API communication.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking with cURL.
 *
 * @package Phlix\Hub
 * @since 0.11.0
 */
class HttpClient implements HttpClientInterface
{
    /**
     * Env var opting OUT of hub TLS certificate verification (mirrors the
     * `PHLIX_RELAY_TLS_VERIFY` idiom in {@see RelayConfig}). Verification is
     * ON unless this is explicitly set to a falsy value.
     */
    public const ENV_TLS_VERIFY = 'PHLIX_HUB_TLS_VERIFY';

    /**
     * Env var pointing at the CA bundle used to verify the hub certificate.
     *
     * Audit §5.2 anchor law: this file must hold CA/ROOT certificates — never
     * the hub's leaf/fullchain (leaves rotate every ~60 days and a
     * leaf-anchored bundle then breaks every request). Set-but-unreadable
     * refuses the request LOUD (audit §3.2 — never a silent system-store
     * re-anchor); unset keeps the tolerant default (system store).
     */
    public const ENV_TLS_CAFILE = 'PHLIX_HUB_TLS_CAFILE';

    /** System CA bundle default (same path the relay tunnel verifies against). */
    public const DEFAULT_TLS_CAFILE = '/etc/ssl/certs/ca-certificates.crt';

    /** @var string Base URL for all requests (no trailing slash). */
    private string $baseUrl;

    /** @var string|null Optional Bearer token for authenticated requests. */
    private ?string $bearerToken;

    /** @var int Request timeout in seconds. */
    private int $timeout;

    /** @var bool Whether to verify the hub's TLS certificate (default true). */
    private bool $tlsVerify;

    /** @var string CA bundle used when {@see $tlsVerify} is true. */
    private string $tlsCafile;

    /**
     * @var bool Whether {@see $tlsCafile} came from an explicit operator choice
     *           (constructor argument or env) rather than the shipped default.
     *           Explicit-but-unreadable refuses the request (audit §3.2); the
     *           default path keeps the historical silent system-store tolerance
     *           (its absence is a distro fact, not an operator mis-set).
     */
    private bool $cafileExplicit;

    /** @var string Where the explicit choice came from, named in the refusal text. */
    private string $cafileSource = '';

    /** @var string Optional explicit Host header value for co-located hub routing. */
    private string $hostOverride = '';

    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

    /**
     * Creates a new HttpClient.
     *
     * TLS verification is ON by default on every transport (async workerman
     * client and the synchronous cURL fallback alike). This client carries the
     * pairing claim POST, the heartbeat with the 7-day Bearer enrollment JWT,
     * enrollment renewal, and the JWKS fetch — turning verification off lets a
     * network-position attacker steal the enrollment JWT or serve a forged
     * enrollment_jwt / forged JWKS. Opt out ONLY for a hub with a self-signed
     * cert on a trusted network, via an explicit `$tlsVerify = false` or
     * `PHLIX_HUB_TLS_VERIFY=0` in the environment.
     *
     * @param string      $baseUrl      Base URL for all requests (e.g.
     *                                  `https://hub.example.com`).
     * @param string|null $bearerToken   Optional Bearer token for auth.
     * @param int         $timeout      Request timeout in seconds (default 30).
     * @param bool|null   $tlsVerify    Explicit TLS verification choice; null
     *                                  resolves from {@see ENV_TLS_VERIFY}
     *                                  (default true).
     * @param string|null $tlsCafile    Explicit CA bundle path (empty string =
     *                                  no opinion, same as null); null resolves
     *                                  from {@see ENV_TLS_CAFILE}, then
     *                                  {@see DEFAULT_TLS_CAFILE}. An explicit
     *                                  choice that is not a readable file
     *                                  refuses requests loudly (audit §3.2);
     *                                  the shipped default keeps the silent
     *                                  system-store tolerance.
     */
    public function __construct(
        string $baseUrl,
        ?string $bearerToken = null,
        int $timeout = 30,
        ?bool $tlsVerify = null,
        ?string $tlsCafile = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->bearerToken = $bearerToken;
        $this->timeout = $timeout;
        $this->tlsVerify = $tlsVerify ?? self::envTlsVerifyDefault();
        $envCafile = getenv(self::ENV_TLS_CAFILE) ?: '';
        $cafile = $tlsCafile !== null && $tlsCafile !== '' ? $tlsCafile : $envCafile;
        $this->cafileExplicit = $cafile !== '';
        $this->cafileSource = $tlsCafile !== null && $tlsCafile !== ''
            ? 'the $tlsCafile constructor argument'
            : self::ENV_TLS_CAFILE;
        $this->tlsCafile = $cafile !== '' ? $cafile : self::DEFAULT_TLS_CAFILE;
    }

    /**
     * Resolves the TLS-verify default from the environment.
     *
     * Mirrors RelayConfig::getEnvBool() (explicit truthy words enable, unset
     * defaults true) with one deliberate hardening: a value that is neither a
     * known truthy nor a known falsy word keeps verification ON — a typo in a
     * security switch must never silently disable TLS verification.
     */
    private static function envTlsVerifyDefault(): bool
    {
        $value = getenv(self::ENV_TLS_VERIFY);
        if ($value === false || trim($value) === '') {
            return true;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Builds the stream context options for hub requests.
     *
     * @return array{ssl: array<string, mixed>} The 'ssl' context section: a
     *         verified posture by default (verify_peer + verify_peer_name +
     *         CA bundle), or the explicit opt-out (verify_peer=false +
     *         allow_self_signed) when verification was disabled by config/env.
     *
     * @throws RuntimeException When verification is on and the OPERATOR-SET
     *         CA bundle is not a readable file (audit §3.2 refusal — never a
     *         silent drop to the system store).
     */
    public function tlsContext(): array
    {
        if (!$this->tlsVerify) {
            return ['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]];
        }

        if ($this->cafileExplicit && !is_readable($this->tlsCafile)) {
            // Audit §3.2 (wildcard-rotation cure): the historical shape here
            // was `is_file(...) ? set cafile : silently drop`. An operator
            // who set PHLIX_HUB_TLS_CAFILE (or passed $tlsCafile) and later
            // moved/deleted the bundle got their trust anchor discarded with
            // zero log lines — requests then silently re-anchored to the
            // system store, validating only publicly-signed hub certs. A
            // configured-but-unreadable anchor must never be dropped quiet:
            // refuse LOUD, name the knob, name both exits (fix the path — CA
            // roots only, never the hub's rotating leaf — or unset to mean
            // "system store"). The DEFAULT path keeps the silent tolerance:
            // its absence is a distro fact, and OpenSSL still verifies via
            // the system store (audit §5.1).
            throw new RuntimeException($this->cafileRefusalMessage());
        }

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        if (is_file($this->tlsCafile)) {
            $ssl['cafile'] = $this->tlsCafile;
        }

        return ['ssl' => $ssl];
    }

    /**
     * The refusal text for an explicit CA bundle that is not a readable file.
     *
     * Single-sourced so tests pin the exact operator-facing wording (names
     * the path, the source of the choice, both exits out, and the §5.2
     * root-not-leaf anchor law).
     */
    private function cafileRefusalMessage(): string
    {
        return 'TLS verification is on for hub requests but the explicitly configured CA bundle ('
            . $this->tlsCafile . ', set via ' . $this->cafileSource . ') is not a readable file.'
            . ' The request is refused instead of silently re-anchoring to the system trust store.'
            . ' Point ' . self::ENV_TLS_CAFILE . ' at an existing CA bundle — CA/root certificates'
            . ' only, never the hub\'s leaf/fullchain: leaves rotate every ~60 days and a'
            . ' leaf-anchored bundle breaks every request at rotation — or unset it to use the'
            . ' system trust store.';
    }

    /**
     * Whether this client verifies hub TLS certificates.
     */
    public function isTlsVerifyEnabled(): bool
    {
        return $this->tlsVerify;
    }

    /**
     * Sets an explicit Host header value for co-located hub routing.
     *
     * When the hub hostname resolves to the local machine's public IP,
     * connecting via 127.0.0.1 would set the Host header to "127.0.0.1"
     * which HAProxy cannot use for routing. This method allows forcing
     * the original hub hostname in the Host header while connecting
     * to 127.0.0.1.
     *
     * @param string $host The host name to use in the Host header (e.g. "hub.phlix.interserver.net").
     */
    public function setHostOverride(string $host): void
    {
        $this->hostOverride = $host;
    }

    /**
     * Gets the async HTTP client, lazy initialized.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => $this->timeout,
                'context' => $this->tlsContext(),
            ]);
        }
        return $this->asyncClient;
    }

    /**
     * Performs a GET request.
     *
     * @param string                     $path    Request path (e.g. `/api/v1/server-claims/new`).
     * @param array<string, string>      $headers Additional headers (merged with defaults).
     *
     * @return HttpResponse The parsed response.
     */
    public function get(string $path, array $headers = []): HttpResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * Performs a POST request with a JSON body.
     *
     * @param string                     $path    Request path.
     * @param array<string, mixed>       $body    JSON-serialisable body payload.
     * @param array<string, string>     $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function post(string $path, array $body, array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, $body, $headers);
    }

    /**
     * Performs a DELETE request.
     *
     * @param string                     $path    Request path.
     * @param array<string, string>      $headers Additional headers.
     *
     * @return HttpResponse The parsed response.
     */
    public function delete(string $path, array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    /**
     * Performs an HTTP request using workerman/http-client (non-blocking async).
     *
     * Uses coroutine context when available for true async execution, otherwise
     * falls back to a cooperative wait that allows event loop to process other tasks.
     * When not in a workerman context (e.g., unit tests), falls back to synchronous curl.
     *
     * @param string                     $method  HTTP method (GET, POST, etc.).
     * @param string                     $path    Request path.
     * @param array<string, mixed>|null  $body    JSON body (null for GET/DELETE).
     * @param array<string, string>      $headers Additional headers.
     *
     * @return HttpResponse Parsed response.
     *
     * @throws RuntimeException On HTTP errors.
     */
    private function request(string $method, string $path, ?array $body, array $headers): HttpResponse
    {
        $url = $this->buildUrl($path);

        if ($url === '') {
            throw new RuntimeException('Cannot perform HTTP request: empty URL');
        }

        $requestHeaders = $this->buildHeaders($headers);

        if ($body !== null) {
            $encodedBody = json_encode($body);
            if ($encodedBody === false) {
                throw new RuntimeException('json_encode failed');
            }
            $body = $encodedBody;
        }

        // https + Swoole event loop: the async client's TLS reads stall
        // (see EventLoopTls), so use blocking cURL for correctness.
        if (\Phlix\Common\Http\EventLoopTls::requiresBlockingCurl($url)) {
            return $this->requestCurl($method, $url, $body, $requestHeaders);
        }

        // SV-0.4: only take the async (event-loop cooperative) path when we are
        // actually inside a coroutine (getCid() > 0). requestCoroutine() lets
        // the hooked Workerman client suspend/resume the current coroutine,
        // which is only valid there. Outside a coroutine we must NOT spin or
        // touch a Channel (that would false-timeout) — fall back to blocking
        // cURL (unit tests, non-coroutine timer callbacks, plain CLI).
        if (
            \Phlix\Common\Runtime\WorkerContext::isEventLoopRunning()
            && \Phlix\Common\Runtime\WorkerContext::inCoroutine()
        ) {
            return $this->requestCoroutine($method, $url, $body, $requestHeaders);
        }

        return $this->requestCurl($method, $url, $body, $requestHeaders);
    }

    /**
     * Fallback synchronous cURL request for CLI/testing contexts.
     *
     * @param array<string, string> $headers
     */
    private function requestCurl(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // SRV-43: only http(s) may be dialled; an https origin may not be
        // redirected down to cleartext http.
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt(
            $ch,
            CURLOPT_REDIR_PROTOCOLS,
            stripos($url, 'https://') === 0 ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
        );
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $this->applyCurlTlsOptions($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(
            array_map(
                fn(string $k, string $v): string => "$k: $v",
                array_keys($headers),
                $headers
            )
        ));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method !== 'GET') {
            assert($method !== '');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);

        if ($rawResponse === false || $rawResponse === true) {
            throw new RuntimeException("cURL error: $curlError");
        }

        /** @var string $headerBlock */
        $headerBlock = substr($rawResponse, 0, $headerSize);
        $responseHeaders = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[trim($key)] = trim($value);
            }
        }

        /** @var string $responseBody */
        $responseBody = substr($rawResponse, $headerSize);
        /** @var array<string, mixed> $bodyArray */
        $bodyArray = json_decode($responseBody, true) ?? [];

        return new HttpResponse($statusCode, $responseHeaders, $bodyArray);
    }

    /**
     * Apply the configured TLS posture to the synchronous cURL transport.
     *
     * cURL verifies by default; the options are set explicitly anyway so the
     * async and sync transports can never silently diverge — and so the
     * opt-out (and custom CA bundle) configured for the async client applies
     * here too.
     *
     * @param \CurlHandle $ch Initialized cURL handle.
     */
    private function applyCurlTlsOptions(\CurlHandle $ch): void
    {
        if (!$this->tlsVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($this->tlsCafile !== '' && is_file($this->tlsCafile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->tlsCafile);
        }
    }

    /**
     * Build the URL from path, handling both relative and absolute URLs.
     */
    private function buildUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        if ($this->baseUrl === '' && $path === '') {
            return '';
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * Build request headers including protocol headers and auth.
     *
     * @param array<string, string> $headers Additional headers.
     * @return array<string, string> Complete headers array.
     */
    private function buildHeaders(array $headers): array
    {
        $requestHeaders = [
            'Accept-Phlix-Protocol' => 'v1',
            'Content-Type' => 'application/json',
        ];

        if ($this->bearerToken !== null) {
            $requestHeaders['Authorization'] = 'Bearer ' . $this->bearerToken;
        }

        if ($this->hostOverride !== '') {
            $requestHeaders['Host'] = $this->hostOverride;
        }

        foreach ($headers as $key => $value) {
            $requestHeaders[$key] = $value;
        }

        return $requestHeaders;
    }

    /**
     * Perform request in coroutine context (truly async).
     *
     * @param string $method HTTP method.
     * @param string $url Full URL.
     * @param string|null $body Request body.
     * @param array<string, string> $headers Request headers.
     * @return HttpResponse Parsed response.
     * @throws RuntimeException On errors.
     */
    private function requestCoroutine(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $client = $this->getAsyncClient();
        $options = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        /** @var ResponseInterface|null $response */
        $response = $client->request($url, $options);

        if ($response === null) {
            throw new RuntimeException('Server did not respond to heartbeat');
        }

        return $this->psr7ResponseToHttpResponse($response);
    }

    /**
     * Convert PSR-7 response to our HttpResponse.
     *
     * @param \Psr\Http\Message\ResponseInterface $response PSR-7 response
     * @return HttpResponse
     */
    private function psr7ResponseToHttpResponse(\Psr\Http\Message\ResponseInterface $response): HttpResponse
    {
        $statusCode = $response->getStatusCode();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $bodyContent = (string) $response->getBody();
        $bodyDecoded = json_decode($bodyContent, true);
        if (!is_array($bodyDecoded)) {
            $bodyDecoded = [];
        }

        return new HttpResponse($statusCode, $headers, $bodyDecoded);
    }
}
