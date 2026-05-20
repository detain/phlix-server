<?php

declare(strict_types=1);

namespace Phlix\Hub;

/**
 * HTTP response wrapper for Hub API communications.
 *
 * @description Wraps HTTP response data including status code, headers, and body
 *             with utility methods for determining success and extracting error codes.
 */
final class HttpResponse
{
    /**
     * @param int                 $statusCode HTTP status code (e.g., 200, 404, 500)
     * @param array<string, string> $headers   Associative array of response headers
     * @param array<string, mixed> $body    Parsed response body as associative array
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly array $body,
    ) {
    }

    /**
     * Determines if the HTTP response indicates success.
     *
     * @description Returns true if the status code is in the 2xx range.
     *
     * @return bool True for 2xx status codes, false otherwise
     */
    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Extracts the error code from the response body, if present.
     *
     * @description Checks for common error code locations in the response body.
     *
     * @return string|null The error code string if found, null otherwise
     */
    public function getErrorCode(): ?string
    {
        $errorCode = $this->body['error_code']
            ?? $this->body['errorCode']
            ?? $this->body['code']
            ?? $this->body['error']
            ?? null;

        return is_string($errorCode) ? $errorCode : null;
    }
}
