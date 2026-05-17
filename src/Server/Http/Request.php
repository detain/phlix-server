<?php

declare(strict_types=1);

namespace Phlex\Server\Http;

/**
 * Represents an HTTP request in the Phlex Media Server.
 *
 * This class encapsulates all information about an incoming HTTP request
 * including the HTTP method, URI, headers, query parameters, and body.
 * It provides utility methods for common request operations.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description HTTP Request class that encapsulates request data from globals.
 * @see Response For response generation
 * @see Router For request routing
 *
 * @property string $method The HTTP method (GET, POST, PUT, DELETE, etc.)
 * @property string $path The request URI path (without query string)
 * @property string $queryString The raw query string portion of the URI
 * @property array<string, string> $headers All HTTP headers as key-value pairs
 * @property array<string, mixed> $query Query parameters from the URL
 * @property array<string, mixed> $body Parsed request body (JSON decoded)
 * @property array<string, mixed> $files Uploaded files
 * @property string $remoteIp Client IP address
 * @property int $remotePort Client port number
 * @property string $protocol HTTP protocol version
 * @property string|null $bearerToken Extracted Bearer token from Authorization header
 * @property string|null $userId Authenticated user ID (set by auth middleware)
 * @property array<string, string> $pathParams Extracted path parameters from route patterns
 */
class Request
{
    /** @var string The HTTP method (GET, POST, PUT, DELETE, etc.) */
    public string $method;

    /** @var string The request URI path (without query string) */
    public string $path;

    /** @var string The raw query string portion of the URI */
    public string $queryString;

    /** @var array<string, string> All HTTP headers as key-value pairs */
    public array $headers;

    /** @var array<string, mixed> Query parameters from the URL */
    public array $query;

    /** @var array<string, mixed> Parsed request body (JSON decoded) */
    public array $body;

    /** @var array<string, mixed> Uploaded files */
    public array $files;

    /** @var string Client IP address */
    public string $remoteIp;

    /** @var int Client port number */
    public int $remotePort;

    /** @var string HTTP protocol version */
    public string $protocol;

    /** @var string|null Extracted Bearer token from Authorization header */
    public ?string $bearerToken = null;

    /** @var string|null Authenticated user ID (set by auth middleware) */
    public ?string $userId = null;

    /** @var array<string, string> Extracted path parameters from route patterns */
    public array $pathParams = [];

    /**
     * Creates a Request instance from PHP global variables.
     *
     * This is the primary method for creating a Request object from
     * the current HTTP request. It extracts method, path, headers,
     * query parameters, and body from their respective global sources.
     *
     * @return self A new Request instance populated from globals
     *
     * @example
     * ```php
     * $request = Request::fromGlobals();
     * echo $request->method; // "GET"
     * echo $request->path;   // "/users/123"
     * ```
     */
    public static function fromGlobals(): self
    {
        $request = new self();
        $request->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $request->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $request->queryString = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '';
        $request->headers = self::parseHeaders();
        $request->query = $_GET;
        $request->files = $_FILES;

        $input = file_get_contents('php://input');
        $request->body = $input !== false ? (json_decode($input, true) ?? []) : [];

        $request->remoteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $request->remotePort = (int)($_SERVER['REMOTE_PORT'] ?? 0);
        $request->protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $request->bearerToken = $request->getBearerToken();

        return $request;
    }

    /**
     * Parses HTTP headers from PHP $_SERVER superglobal.
     *
     * Extracts all HTTP_* headers and also handles Content-Type and
     * Content-Length headers that may be set via FastCGI.
     *
     * @return array<string, string> Associative array of header name to value
     */
    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        // Also check for headers set via FastCGI
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['CONTENT-LENGTH'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /**
     * Gets a specific HTTP header value.
     *
     * Searches headers case-insensitively, first checking the
     * parsed headers array, then falling back to $_SERVER.
     *
     * @param string $name The header name to retrieve
     * @return string|null The header value, or null if not found
     */
    public function getHeader(string $name): ?string
    {
        // Case-insensitive lookup in parsed headers
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        // Fall back to server array
        $normalized = strtoupper(str_replace('-', '_', $name));
        $key = 'HTTP_' . $normalized;
        return $_SERVER[$key] ?? null;
    }

    /**
     * Extracts the Bearer token from the Authorization header.
     *
     * @return string|null The bearer token string, or null if not present
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Gets the client's real IP address.
     *
     * Respects X-Forwarded-For header when behind a proxy or
     * load balancer, returning the first IP in the chain.
     *
     * @return string The client's IP address
     *
     * @description Handles proxy scenarios by checking X-Forwarded-For header.
     */
    public function getClientIp(): string
    {
        // Check for forwarded headers (proxy/load balancer)
        $forwardedFor = $this->getHeader('X-Forwarded-For');
        if ($forwardedFor) {
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }
        return $this->remoteIp;
    }

    /**
     * Checks if the request method is GET.
     *
     * @return bool True if method is GET, false otherwise
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Checks if the request method is POST.
     *
     * @return bool True if method is POST, false otherwise
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Checks if the request method is PUT.
     *
     * @return bool True if method is PUT, false otherwise
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Checks if the request method is DELETE.
     *
     * @return bool True if method is DELETE, false otherwise
     */
    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /**
     * Checks if the request Content-Type is JSON.
     *
     * @return bool True if Content-Type contains application/json
     */
    public function isJson(): bool
    {
        return str_contains($this->getHeader('Content-Type') ?? '', 'application/json');
    }

    /**
     * Gets a body parameter with optional default value.
     *
     * @param string $key The parameter key to retrieve
     * @param mixed $default Default value if key is not present
     * @return mixed The parameter value or default
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Checks if a body parameter exists.
     *
     * @param string $key The parameter key to check
     * @return bool True if key exists in body
     */
    public function has(string $key): bool
    {
        return isset($this->body[$key]);
    }
}
