<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use Workerman\Http\Client;

/**
 * Async HTTP client for webhook deliveries.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking. Falls back to synchronous cURL when not
 * running in a Workerman context (e.g., CLI, testing).
 *
 * @autowire
 */
class WebhookHttpClient
{
    /** Default timeout for webhook requests in seconds. */
    private const DEFAULT_TIMEOUT = 10;

    /** Maximum response body length to store (truncate if longer). */
    private const MAX_RESPONSE_BODY_LENGTH = 65535;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /** @var Client|null Async HTTP client instance (lazy initialized) */
    private ?Client $asyncClient = null;

    /**
     * @param int $timeout Request timeout in seconds (default: 10)
     */
    public function __construct(int $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->timeout = $timeout;
    }

    /**
     * Performs an async POST request to the webhook URL.
     *
     * @param string $url Target webhook URL
     * @param string $eventType Event type header value
     * @param string $deliveryId Delivery ID header value
     * @param array<string, mixed> $payload JSON-serializable payload
     *
     * @return array{success: bool, response_code: int|null, response_body: string|null, error: string|null}
     */
    public function post(string $url, string $eventType, string $deliveryId, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Phlix-Event' => $eventType,
            'X-Phlix-Delivery' => $deliveryId,
        ];

        // https + Swoole event loop: async TLS reads stall (see EventLoopTls),
        // so those requests must take the blocking cURL path.
        return \Phlix\Common\Runtime\WorkerContext::isEventLoopRunning() && !\Phlix\Common\Http\EventLoopTls::requiresBlockingCurl($url)
            ? $this->postAsync($url, $headers, $jsonPayload)
            : $this->postCurl($url, $headers, $jsonPayload);
    }

    /**
     * Performs an async HTTP POST request with Channel-based cooperative wait.
     *
     * @param string $url Target URL
     * @param array<string, string> $headers Request headers
     * @param string $body Request body
     *
     * @return array{success: bool, response_code: int|null, response_body: string|null, error: string|null}
     */
    private function postAsync(string $url, array $headers, string $body): array
    {
        $client = $this->getAsyncClient();

        $options = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body,
        ];

        // Channel-based wait: push is called by success/error callbacks,
        // and pop() yields to the coroutine scheduler until done or timeout.
        $channel = new \Swoole\Coroutine\Channel(1);

        $state = [
            'response' => null,
            'error' => null,
        ];

        $options['success'] = function (ResponseInterface $response) use (&$state, $channel): void {
            $state['response'] = $response;
            $channel->push(true);
        };

        $options['error'] = function (Throwable $error) use (&$state, $channel): void {
            $state['error'] = $error;
            $channel->push(true);
        };

        $client->request($url, $options);

        // Yield to event loop via Channel pop with timeout
        $channel->pop((float) $this->timeout);

        if ($state['error'] !== null) {
            return [
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => $state['error']->getMessage(),
            ];
        }

        if ($state['response'] === null) {
            return [
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => 'Request timed out',
            ];
        }

        $responseCode = $state['response']->getStatusCode();
        $responseBody = (string) $state['response']->getBody();

        return [
            'success' => $responseCode >= 200 && $responseCode < 300,
            'response_code' => $responseCode,
            'response_body' => $this->truncateResponseBody($responseBody),
            'error' => null,
        ];
    }

    /**
     * Fallback synchronous cURL POST for CLI/testing contexts.
     *
     * @param string $url Target URL
     * @param array<string, string> $headers Request headers
     * @param string $body Request body
     *
     * @return array{success: bool, response_code: int|null, response_body: string|null, error: string|null}
     */
    private function postCurl(string $url, array $headers, string $body): array
    {
        if ($url === '') {
            return [
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => 'Empty URL',
            ];
        }

        $ch = curl_init();
        if ($ch === false) {
            return [
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => 'Failed to initialize cURL',
            ];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "$key: $value";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        $responseBody = curl_exec($ch);
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'success' => false,
                'response_code' => null,
                'response_body' => null,
                'error' => $curlError !== '' ? $curlError : 'cURL request failed',
            ];
        }

        return [
            'success' => $responseCode >= 200 && $responseCode < 300,
            'response_code' => $responseCode,
            'response_body' => $this->truncateResponseBody(is_string($responseBody) ? $responseBody : ''),
            'error' => null,
        ];
    }

    /**
     * Gets the async HTTP client, lazy initialized.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => $this->timeout,
            ]);
        }
        return $this->asyncClient;
    }

    /**
     * Truncate response body to maximum allowed length.
     */
    private function truncateResponseBody(string $body): string
    {
        if (strlen($body) <= self::MAX_RESPONSE_BODY_LENGTH) {
            return $body;
        }
        return substr($body, 0, self::MAX_RESPONSE_BODY_LENGTH) . '... (truncated)';
    }
}
