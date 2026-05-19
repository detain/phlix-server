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
     * @property string $rawBody Raw request body (not JSON decoded, for SOAP/XML requests)
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

    /** @var string Raw request body (not JSON decoded, for SOAP/XML requests) */
    public string $rawBody;

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

    /** @var \Phlex\Hub\HubUserClaims|null Hub user claims (set by HubJwtMiddleware when using hub auth) */
    public ?\Phlex\Hub\HubUserClaims $hubUser = null;

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
        $request->method = self::serverString('REQUEST_METHOD', 'GET');
        $uri = self::serverString('REQUEST_URI', '/');
        $request->path = self::stringOr(parse_url($uri, PHP_URL_PATH), '/');
        $request->queryString = self::stringOr(parse_url($uri, PHP_URL_QUERY), '');
        $request->headers = self::parseHeaders();
        $request->query = self::stringKeyedArray($_GET);
        $request->files = self::stringKeyedArray($_FILES);

        $input = file_get_contents('php://input');
        $request->rawBody = $input !== false ? $input : '';
        if ($input !== false) {
            $decoded = json_decode($input, true);
            $request->body = is_array($decoded) ? self::stringKeyedArray($decoded) : [];
        } else {
            $request->body = [];
        }

        $request->remoteIp = self::serverString('REMOTE_ADDR', '0.0.0.0');
        $remotePort = $_SERVER['REMOTE_PORT'] ?? 0;
        $request->remotePort = is_numeric($remotePort) ? (int)$remotePort : 0;
        $request->protocol = self::serverString('SERVER_PROTOCOL', 'HTTP/1.1');
        $request->bearerToken = $request->getBearerToken();

        return $request;
    }

    /**
     * Read a string-valued entry from $_SERVER with a default fallback.
     */
    private static function serverString(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /**
     * Coerce a possibly mixed value to a non-empty string or fall back to a default.
     *
     * @param mixed $value
     */
    private static function stringOr(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    /**
     * Narrow a mixed iterable (superglobal) into a string-keyed array.
     *
     * @param array<array-key, mixed> $input
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
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
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $header = str_replace('_', '-', substr($key, 5));
                $headers[$header] = $value;
            }
        }
        // Also check for headers set via FastCGI
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
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
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
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

    /**
     * Gets a query parameter coerced to a string (or default if missing/non-scalar).
     *
     * @param string $key The query parameter name
     * @param string|null $default The fallback when the parameter is absent or non-scalar
     * @return string|null
     */
    public function queryString(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return $default;
    }

    /**
     * Gets a query parameter coerced to an int (or default if missing/non-numeric).
     *
     * @param string $key The query parameter name
     * @param int $default The fallback when the parameter is absent or non-numeric
     */
    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * Gets a path parameter as a string (returns default if missing).
     */
    public function pathParam(string $key, string $default = ''): string
    {
        return $this->pathParams[$key] ?? $default;
    }
}
