<?php

declare(strict_types=1);

namespace Phlix\Admin;

use Throwable;

/**
 * Minimal S3-compatible object storage client using plain HTTP and AWS Signature V4.
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

        $payloadHash = $actualChecksum;
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
        $canonicalQueryString = '';

        $canonicalRequest = implode("\n", [
            'PUT',
            $canonicalUri,
            $canonicalQueryString,
            implode("\n", array_map(fn($k, $v) => strtolower($k) . ':' . trim((string) $v), array_keys($canonicalHeaders), $canonicalHeaders)) . "\n",
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

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $this->buildUrl($bucket, $key);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $dateTime,
                'Authorization: ' . $authorization,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($content),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
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
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $host = $this->getHost($bucket);
        $canonicalUri = '/' . rawurlencode($key);

        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalQueryString = '';

        $canonicalRequest = implode("\n", [
            'GET',
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

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $this->buildUrl($bucket, $key);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $dateTime,
                'Authorization: ' . $authorization,
            ],
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $content === false) {
            return false;
        }

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
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $host = $this->getHost($bucket);
        $canonicalUri = '/';

        $queryParams = ['list-type' => '2'];
        if ($prefix !== '') {
            $queryParams['prefix'] = $prefix;
        }

        $canonicalQueryString = implode('&', array_map(
            fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($queryParams),
            $queryParams
        ));

        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'GET',
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

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $this->buildUrl($bucket, '/') . '?list-type=2';
        if ($prefix !== '') {
            $url .= '&prefix=' . rawurlencode($prefix);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $dateTime,
                'Authorization: ' . $authorization,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === '') {
            return [];
        }

        $xml = @simplexml_load_string((string) $response);
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
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

        $host = $this->getHost($bucket);
        $canonicalUri = '/' . rawurlencode($key);

        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];

        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonicalQueryString = '';

        $canonicalRequest = implode("\n", [
            'DELETE',
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

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $this->buildUrl($bucket, $key);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $dateTime,
                'Authorization: ' . $authorization,
            ],
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
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
