<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Admin;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use Workerman\Coroutine;
use Workerman\Http\Client;

/**
 * Minimal S3-compatible object storage client using plain HTTP and AWS Signature V4.
 *
 * Uses workerman/http-client for non-blocking async HTTP that yields to the
 * event loop instead of blocking with cURL.
 *
 * Supports: upload, download, listObjects, deleteObject operations against
 * any S3-compatible service (AWS S3, MinIO, Backblaze B2, etc.).
 *
 * @package Phlix\Admin
 */
class S3Client
{
    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;

    /** @var Client|null Async HTTP client instance (lazy initialized). */
    private ?Client $asyncClient = null;

    /**
     * @param string $region AWS region (e.g., 'us-east-1')
     * @param string $accessKey AWS access key ID
     * @param string $secretKey AWS secret access key
     * @param string $endpoint S3 endpoint URL (empty for AWS S3, set for MinIO/Backblaze)
     */
    public function __construct(
        string $region,
        string $accessKey,
        string $secretKey,
        string $endpoint = '',
    ) {
        $this->region = $region;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * Gets the async HTTP client, lazy initialized.
     */
    private function getAsyncClient(): Client
    {
        if ($this->asyncClient === null) {
            $this->asyncClient = new Client([
                'timeout' => 60,
            ]);
        }
        return $this->asyncClient;
    }

    /**
     * Upload a file to S3 using PUT Object.
     *
     * @param string $bucket S3 bucket name
     * @param string $key Object key (path within bucket)
     * @param string $filePath Local file path to upload
     * @param string $checksum Expected SHA-256 checksum (checked after upload)
     * @return bool True on success
     */
    public function upload(string $bucket, string $key, string $filePath, string $checksum): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        $actualChecksum = hash_file('sha256', $filePath);
        if ($actualChecksum === false) {
            return false;
        }
        if (strtolower($actualChecksum) !== strtolower($checksum)) {
            return false;
        }

        $url = $this->buildUrl($bucket, $key);
        $headers = $this->buildAuthHeaders('PUT', $bucket, $key, $actualChecksum);
        $headers['Content-Length'] = (string) strlen($content);
        $headers['Content-Type'] = 'application/octet-stream';

        $response = $this->doRequest('PUT', $url, $content, $headers);

        return $response !== null && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * Download an object from S3.
     *
     * @param string $bucket S3 bucket name
     * @param string $key Object key
     * @param string $destination Local file path to write to
     * @return bool True on success
     */
    public function download(string $bucket, string $key, string $destination): bool
    {
        $url = $this->buildUrl($bucket, $key);
        $emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $headers = $this->buildAuthHeaders('GET', $bucket, $key, $emptyHash);

        $response = $this->doRequest('GET', $url, null, $headers);

        if ($response === null || $response->getStatusCode() !== 200) {
            return false;
        }

        $content = (string) $response->getBody();

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($destination, $content) !== false;
    }

    /**
     * List objects in a bucket with optional prefix filter.
     *
     * @param string $bucket S3 bucket name
     * @param string $prefix Filter objects by prefix
     * @return array<object> List of object metadata
     */
    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $url = $this->buildUrl($bucket, '/');
        $queryParams = ['list-type' => '2'];
        if ($prefix !== '') {
            $queryParams['prefix'] = $prefix;
        }
        $url .= '?' . http_build_query($queryParams);

        $emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $headers = $this->buildAuthHeaders('GET', $bucket, '/', $emptyHash, $queryParams);

        $response = $this->doRequest('GET', $url, null, $headers);

        if ($response === null || $response->getStatusCode() !== 200) {
            return [];
        }

        $body = (string) $response->getBody();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return [];
        }

        $objects = [];
        foreach ($xml->Contents as $item) {
            $objects[] = (object) [
                'key' => (string) $item->Key,
                'size' => (int) $item->Size,
                'lastModified' => (string) $item->LastModified,
                'etag' => trim((string) $item->ETag, '"'),
            ];
        }

        return $objects;
    }

    /**
     * Delete an object from S3.
     *
     * @param string $bucket S3 bucket name
     * @param string $key Object key to delete
     * @return bool True on success
     */
    public function deleteObject(string $bucket, string $key): bool
    {
        $url = $this->buildUrl($bucket, $key);
        $emptyHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $headers = $this->buildAuthHeaders('DELETE', $bucket, $key, $emptyHash);

        $response = $this->doRequest('DELETE', $url, null, $headers);

        return $response !== null && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * Build AWS v4 authorization headers for a request.
     *
     * @param string $method HTTP method
     * @param string $bucket Bucket name
     * @param string $key Object key (or '/' for bucket-level ops)
     * @param string $payloadHash SHA-256 payload hash
     * @param array<string, string> $queryParams Query parameters for signing
     * @return array<string, string> Headers array
     */
    private function buildAuthHeaders(string $method, string $bucket, string $key, string $payloadHash, array $queryParams = []): array
    {
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";

        $host = $this->getHost($bucket);
        $canonicalUri = '/' . rawurlencode($key);

        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalQueryString = implode('&', array_map(
            fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($queryParams),
            $queryParams
        ));

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            implode("\n", array_map(fn($k, $v) => strtolower($k) . ':' . trim($v), array_keys($canonicalHeaders), $canonicalHeaders)) . "\n",
            $signedHeaders,
            $payloadHash,
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $dateTime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = $this->signString($stringToSign, $date);

        return [
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    /**
     * Dispatch an HTTP request, using the async client when a Workerman event
     * loop is running and falling back to synchronous cURL otherwise.
     *
     * `Workerman\Http\Client` (and the `Timer` it uses internally for request
     * timeouts) requires an active Workerman event loop. Under PHPUnit (or any
     * plain CLI invocation) there is no running worker, so the async client
     * throws `RuntimeException: Timer can only be used in workerman running
     * environment`.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string|null $body Request body
     * @param array<string, string> $headers Request headers
     * @return ResponseInterface|null Response or null on error/timeout
     */
    private function doRequest(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        if ($this->isWorkermanContext()) {
            return $this->requestAsync($method, $url, $body, $headers);
        }

        return $this->requestCurl($method, $url, $body, $headers);
    }

    /**
     * Check if we're running inside a workerman worker context.
     *
     * @return bool True if in workerman context, false otherwise
     */
    private function isWorkermanContext(): bool
    {
        if (!class_exists('Workerman\Worker')) {
            return false;
        }
        // Worker::$_instance is set when running in worker context
        return defined('\Workerman\Worker::$_instance');
    }

    /**
     * Fallback synchronous cURL request for CLI/testing contexts where no
     * Workerman event loop is running.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string|null $body Request body
     * @param array<string, string> $headers Request headers
     * @return ResponseInterface|null Response or null on error
     */
    private function requestCurl(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        if ($url === '' || $method === '') {
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "$key: $value";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($responseBody)) {
            return null;
        }

        return new \Workerman\Http\Response($statusCode, [], $responseBody);
    }

    /**
     * Perform an async HTTP request with cooperative wait.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param string|null $body Request body
     * @param array<string, string> $headers Request headers
     * @return ResponseInterface|null Response or null on error/timeout
     */
    private function requestAsync(string $method, string $url, ?string $body, array $headers): ?ResponseInterface
    {
        $client = $this->getAsyncClient();

        $options = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $options['data'] = $body;
        }

        // State shared between callback and waiting loop
        $state = [
            'response' => null,
            'error' => null,
            'done' => false,
        ];

        $options['success'] = function (ResponseInterface $response) use (&$state) {
            $state['response'] = $response;
            $state['done'] = true;
        };

        $options['error'] = function (\Throwable $error) use (&$state) {
            $state['error'] = $error;
            $state['done'] = true;
        };

        // Initiate async request (non-blocking)
        $client->request($url, $options);

        // Cooperative wait: run event loop until done or timeout
        $maxWait = 60;
        $waited = 0;
        $interval = 0.001; // 1ms interval for event loop processing

        while (!$state['done'] && $waited < $maxWait) {
            usleep((int) ($interval * 1000000));
            $waited += $interval;
        }

        if ($state['error'] !== null) {
            return null;
        }

        return $state['response'];
    }

    /**
     * Sign a string with AWS Signature V4.
     */
    private function signString(string $stringToSign, string $date): string
    {
        $kDate = $this->hmacSha256('AWS4' . $this->secretKey, $date);
        $kRegion = $this->hmacSha256($kDate, $this->region);
        $kService = $this->hmacSha256($kRegion, 's3');
        $kSigning = $this->hmacSha256($kService, 'aws4_request');

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * Compute HMAC-SHA256.
     */
    private function hmacSha256(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    /**
     * Build the S3 endpoint URL.
     */
    private function buildUrl(string $bucket, string $key): string
    {
        if ($this->endpoint !== '') {
            return rtrim($this->endpoint, '/') . '/' . $bucket . '/' . ltrim($key, '/');
        }

        return "https://{$bucket}.s3.{$this->region}.amazonaws.com/" . ltrim($key, '/');
    }

    /**
     * Get the host header value.
     */
    private function getHost(string $bucket): string
    {
        if ($this->endpoint !== '') {
            $parsed = parse_url($this->endpoint);
            return $parsed['host'] ?? $bucket . '.s3.' . $this->region . '.amazonaws.com';
        }

        return $bucket . '.s3.' . $this->region . '.amazonaws.com';
    }
}
