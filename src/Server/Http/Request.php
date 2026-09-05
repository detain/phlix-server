<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use Phlix\Common\Http\PageLimit;
use Phlix\Common\Http\TrustedProxyResolver;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Represents an HTTP request in the Phlix Media Server.
 *
 * This class encapsulates all information about an incoming HTTP request
 * including the HTTP method, URI, headers, query parameters, and body.
 * It provides utility methods for common request operations.
 *
 * @author Phlix Media Server Team
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
    /**
     * Typed-property defaults: tests routinely build a Request via
     * `new Request()` and only set the few fields they care about. We
     * default every public field to a safe empty value so accessing
     * unset properties returns the obvious "no data" answer instead of
     * throwing under PHP 7.4+'s typed-property uninitialised guard.
     *
     * Production callers use fromGlobals() / fromWorkerman() which
     * overwrite every field, so defaults have no observable effect
     * outside the test stub path.
     */

    /** @var string The HTTP method (GET, POST, PUT, DELETE, etc.) */
    public string $method = 'GET';

    /** @var string The request URI path (without query string) */
    public string $path = '/';

    /** @var string The raw query string portion of the URI */
    public string $queryString = '';

    /** @var array<string, string> All HTTP headers as key-value pairs */
    public array $headers = [];

    /** @var array<string, mixed> Query parameters from the URL */
    public array $query = [];

    /** @var array<string, mixed> Parsed request body (JSON decoded) */
    public array $body = [];

    /** @var string Raw request body (not JSON decoded, for SOAP/XML requests) */
    public string $rawBody = '';

    /** @var array<string, mixed> Uploaded files */
    public array $files = [];

    /** @var string Client IP address */
    public string $remoteIp = '0.0.0.0';

    /** @var int Client port number */
    public int $remotePort = 0;

    /** @var string HTTP protocol version */
    public string $protocol = 'HTTP/1.1';

    /** @var string|null Extracted Bearer token from Authorization header */
    public ?string $bearerToken = null;

    /** @var array<string, string> Parsed `Cookie` header (name → raw value) */
    public array $cookies = [];

    /** @var string|null Authenticated user ID (set by auth middleware) */
    public ?string $userId = null;

    /**
     * The profile this request runs as (S80).
     *
     * Set by {@see RequestAuthenticator::authenticate()} from the signed
     * `profile_id` JWT claim, AFTER that claim has been re-verified against
     * {@see $userId}. Null means either "unauthenticated" or "the account has no
     * resolvable profile".
     *
     * ⚠ **Never assign this from request input.** It is derived from a token this
     * server signed, and it is the value profile-scoped reads and writes are keyed
     * on; taking it from a body, query string or path parameter would let user A
     * name user B's profile and read B's favorites. Anything that genuinely needs
     * to act on a caller-named profile must go through
     * {@see \Phlix\Access\ProfileAccessPolicy} or
     * {@see \Phlix\Auth\UserProfileManager::resolveProfileIdForUser()}, both of
     * which re-derive ownership.
     *
     * @var string|null
     */
    public ?string $profileId = null;

    /** @var \Phlix\Hub\HubUserClaims|null Hub user claims (set by HubJwtMiddleware when using hub auth) */
    public ?\Phlix\Hub\HubUserClaims $hubUser = null;

    /** @var array<string, string> Extracted path parameters from route patterns */
    public array $pathParams = [];

    /**
     * Reachability guard: dynamic READS of undeclared properties are rejected (S427).
     *
     * Provenance. S271 removed two dead `$request->jsonBody ?? []` reads in
     * BackupController — reads of a property that never existed here, whose coalesce
     * silently fed `[]` where the populated `->body` belongs. S271 declined a
     * throwing `__get` in its own commit for fear of turning hot-path
     * null-coalesced reads into catch-fed 500s. S427 re-opened that call with a
     * tokenized census of all 1,756 tracked PHP files: 331 property reads on typed
     * Request roots, EVERY one on a declared member; 1,037 dynamic writes, none;
     * dynamic-name accesses (`->$k`/`->{$expr}`), none; guarded (`??`/isset/empty)
     * reads of undeclared names outside the two S271 sites, none. With a zero
     * live-read denominator the throw can only ever fire on a would-be S271 bug.
     *
     * Semantics this guard deliberately preserves. PHP consults `__isset()` (below)
     * before `__get()` for `isset()`, `empty()`, AND null-coalescing reads, so the
     * S271 shape itself — `$request->jsonBody ?? []` — still answers `[]` without
     * ever reaching the throw. Only UNguarded reads of names that are not declared
     * members fail, loudly, converting today's silent-null bug class into a
     * LogicException at its exact call site. (A bare-`?:` elvis read DOES evaluate
     * the property and therefore throws; the census found zero elvis sites.)
     *
     * What static analysis could not do: PHPStan level 9 flags a DIRECT undefined
     * read (`property.notFound`) but is provably blind to the `?? $default` and
     * `isset()` shapes and to dynamic-name reads it cannot constant-fold — exactly
     * the shapes that let `jsonBody` survive to production. A runtime guard is the
     * only enforcement point for the whole class. (S427-reachability-guard@4b620f59)
     *
     * @throws \LogicException Always, naming the offending property.
     */
    public function __get(string $name): mixed
    {
        throw new \LogicException(
            "Dynamic read of undefined property Phlix\\Server\\Http\\Request::\${$name} — Request declares no magic"
            . ' properties (S427 reachability guard, closing the S271 silent-null bug class).'
            . ' Declared members: method, path, queryString, headers, query, body, rawBody, files,'
            . ' remoteIp, remotePort, protocol, bearerToken, cookies, userId, profileId, hubUser, pathParams.'
            . ' Use ->body for the decoded request body, input()/has() for body+query lookups, and'
            . ' getHeader()/getCookie() for headers and cookies.'
        );
    }

    /**
     * Pairs with {@see __get()} so existence tests on undeclared names answer false
     * without ever calling `__get()` — the mechanism that keeps every
     * `?? $default` / `isset()` / `empty()` site (including both S271 mutation
     * arms in BackupController) behaviorally identical to before the guard.
     * Declared members never reach here: they are public and always initialized.
     */
    public function __isset(string $name): bool
    {
        return false;
    }

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
        $request->cookies = self::stringStringArray($_COOKIE);

        return $request;
    }

    /**
     * Build a Request from a Workerman HTTP request (long-running daemon mode).
     *
     * Mirrors {@see self::fromGlobals()} but pulls everything from the
     * Workerman request object rather than PHP superglobals. Optionally
     * accepts the TcpConnection so remote-IP/port get populated.
     */
    public static function fromWorkerman(WorkermanRequest $wr, ?TcpConnection $conn = null): self
    {
        $request = new self();
        $request->method = $wr->method();
        $request->path = $wr->path();

        $queryString = parse_url($wr->uri(), PHP_URL_QUERY);
        $request->queryString = is_string($queryString) ? $queryString : '';

        $request->headers = self::collectHeadersFromWorkerman($wr);
        $request->query = self::collectArrayFromWorkerman($wr->get());
        $request->files = self::collectArrayFromWorkerman($wr->file());

        $rawBody = $wr->rawBody();
        $request->rawBody = $rawBody;
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            $request->body = is_array($decoded) ? self::stringKeyedArray($decoded) : [];
        } else {
            $request->body = self::collectArrayFromWorkerman($wr->post());
        }

        $request->remoteIp = $conn?->getRemoteIp() ?? '0.0.0.0';
        $request->remotePort = $conn?->getRemotePort() ?? 0;
        $request->protocol = $wr->protocolVersion() !== ''
            ? 'HTTP/' . $wr->protocolVersion()
            : 'HTTP/1.1';
        $request->bearerToken = $request->getBearerToken();

        $rawCookies = $wr->cookie();
        $request->cookies = is_array($rawCookies) ? self::stringStringArray($rawCookies) : [];

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeadersFromWorkerman(WorkermanRequest $wr): array
    {
        $out = [];
        $raw = $wr->header();
        if (!is_array($raw)) {
            return $out;
        }
        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                // Match parseHeaders()'s upper-case convention so getHeader()
                // case-insensitive lookups work the same way under both modes.
                $out[strtoupper(str_replace('_', '-', $key))] = $value;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectArrayFromWorkerman(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
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
     * Narrow a mixed iterable down to `array<string, string>`, dropping
     * any entries whose key or value isn't a string. Used for cookies,
     * which PHP / Workerman both surface as string-keyed arrays but
     * with loose value typing.
     *
     * @param array<array-key, mixed> $input
     * @return array<string, string>
     */
    private static function stringStringArray(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && is_string($value)) {
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
     * Returns a cookie value by name, or null when the cookie isn't set.
     *
     * @param string $name The cookie name (case-sensitive — RFC 6265).
     *
     * @return string|null The raw (URL-decoded by the runtime) value,
     *                     or null if the request didn't carry it.
     */
    public function getCookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
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
     * Gets the raw, UNTRUSTED client IP from the leftmost `X-Forwarded-For` entry.
     *
     * WARNING: the leftmost XFF entry is fully client-controlled (the shipped
     * nginx front APPENDS the connecting address rather than overwriting the
     * header), so this value is TRIVIALLY SPOOFABLE. It MUST NOT be used for any
     * security decision — rate-limit keys, authorization, audit trust, etc. Use
     * {@see getTrustedClientIp()} for anything that must resist forgery. This
     * accessor is retained only for non-security, best-effort display/logging.
     *
     * @return string The client-supplied (untrusted) IP, or the peer address.
     *
     * @description Returns the leftmost X-Forwarded-For entry (untrusted).
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
     * Resolve the REAL client IP for security-sensitive keys (rate limiting) in a
     * trusted-proxy-aware way (SV-4.15 HIGH fix).
     *
     * Delegates to {@see TrustedProxyResolver}, which walks `X-Forwarded-For`
     * RIGHT-TO-LEFT past trusted-proxy hops (default: loopback, matching the
     * shipped nginx/HAProxy front) and returns the first untrusted address — so a
     * forged leftmost XFF value can no longer mint a fresh rate-limit bucket. The
     * returned value is always a validated IP (≤45 chars), so it can never
     * overflow the `rate_limit_buckets.rate_key` VARCHAR(191) primary key.
     *
     * @param TrustedProxyResolver|null $resolver Optional resolver (inject a
     *        configured one in tests); defaults to the `TRUSTED_PROXIES`-env one.
     *
     * @return string The trusted client IP.
     */
    public function getTrustedClientIp(?TrustedProxyResolver $resolver = null): string
    {
        $resolver ??= new TrustedProxyResolver();
        return $resolver->resolve(
            $this->remoteIp,
            $this->getHeader('X-Forwarded-For'),
            $this->getHeader('X-Real-IP'),
        );
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
     * Gets a pagination page size, CLAMPED to the hard server-side ceiling.
     *
     * Use this — never {@see queryInt()} — for any value that ends up bound to
     * a `LIMIT ?`. `queryInt()` performs no bounds checking at all, so an
     * unclamped `?limit=` reaching a query is a memory-exhaustion vector
     * against a **resident** Workerman worker: one request can OOM the process
     * serving every other user.
     *
     * The ceiling ({@see PageLimit::MAX}) is a hard compile-time maximum, not a
     * configurable default a client may exceed.
     *
     * @param string $key     The query parameter name (usually `limit`).
     * @param int    $default Page size when the parameter is absent/non-numeric.
     *
     * @return int A page size guaranteed to satisfy `PageLimit::MIN <= n <= PageLimit::MAX`.
     *
     * @since 1.3.0
     */
    public function queryPageSize(string $key = 'limit', int $default = PageLimit::DEFAULT): int
    {
        return PageLimit::clamp($this->query[$key] ?? null, $default);
    }

    /**
     * Gets a pagination row offset, clamped to a non-negative integer.
     *
     * @param string $key     The query parameter name (usually `offset`).
     * @param int    $default Offset when the parameter is absent/non-numeric.
     *
     * @return int A non-negative row offset.
     *
     * @since 1.3.0
     */
    public function queryOffset(string $key = 'offset', int $default = 0): int
    {
        return PageLimit::clampOffset($this->query[$key] ?? null, $default);
    }

    /**
     * Gets a path parameter as a string (returns default if missing).
     */
    public function pathParam(string $key, string $default = ''): string
    {
        return $this->pathParams[$key] ?? $default;
    }
}
