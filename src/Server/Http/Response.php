<?php

declare(strict_types=1);

namespace Phlix\Server\Http;

use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Represents an HTTP response in the Phlix Media Server.
 *
 * This class provides a fluent interface for building HTTP responses
 * with various content types, status codes, and headers.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description HTTP Response class with fluent builder pattern for content types.
 * @see Request For request representation
 *
 * @property int $statusCode The HTTP status code (200, 404, 500, etc.)
 * @property array<string, string> $headers Response headers as key-value pairs
 * @property string $body The response body content
 * @property string $version HTTP protocol version
 */
class Response
{
    /** @var int The HTTP status code (200, 404, 500, etc.) */
    public int $statusCode = 200;

    /** @var array<string, string> Response headers as key-value pairs */
    public array $headers = [];

    /**
     * @var list<array{
     *     name: string,
     *     value: string,
     *     maxAge: int|null,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httpOnly: bool,
     *     sameSite: string,
     * }>
     */
    public array $cookies = [];

    /** @var string The response body content */
    public string $body = '';

    /** @var string HTTP protocol version */
    public string $version = '1.1';

    /**
     * Sets the HTTP status code.
     *
     * @param int $code The HTTP status code
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->status(404)->json(['error' => 'Not found']);
     * ```
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Adds a response header.
     *
     * @param string $name The header name
     * @param string $value The header value
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->header('X-Custom-Header', 'value')->json(['ok' => true]);
     * ```
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Creates a JSON response.
     *
     * Automatically sets Content-Type to application/json and
     * encodes the data with pretty printing.
     *
     * @param array<string, mixed> $data The data to encode as JSON
     * @param int|null $statusCode Optional HTTP status code override
     * @return self For method chaining
     *
     * @throws \JsonException If JSON encoding fails
     *
     * @example
     * ```php
     * (new Response())->json(['user' => ['id' => 1, 'name' => 'John']]);
     * ```
     */
    public function json(array $data, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'application/json';
        $this->body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this;
    }

    /**
     * Creates an HTML response.
     *
     * @param string $html The HTML content
     * @param int|null $statusCode Optional HTTP status code override
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->html('<h1>Hello World</h1>');
     * ```
     */
    public function html(string $html, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        $this->body = $html;
        return $this;
    }

    /**
     * Creates a plain text response.
     *
     * @param string $text The text content
     * @param int|null $statusCode Optional HTTP status code override
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->text('Hello, World!');
     * ```
     */
    public function text(string $text, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'text/plain; charset=utf-8';
        $this->body = $text;
        return $this;
    }

    /**
     * Sets the raw response body content.
     *
     * @param string $content The body content
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->body('Raw content here');
     * ```
     */
    public function body(string $content): self
    {
        $this->body = $content;
        return $this;
    }

    /**
     * Creates an XML response.
     *
     * @param string $xml The XML content
     * @param int|null $statusCode Optional HTTP status code override
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->xml('<root><item>value</item></root>');
     * ```
     */
    public function xml(string $xml, ?int $statusCode = null): self
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        $this->headers['Content-Type'] = 'application/xml; charset=utf-8';
        $this->body = $xml;
        return $this;
    }

    /**
     * Creates a file download response.
     *
     * Sets Content-Type based on file extension or provided MIME type,
     * and optionally sets Content-Disposition for download.
     *
     * @param string $path Absolute path to the file to send
     * @param string|null $contentType Optional MIME type override
     * @param string|null $downloadName Optional download filename
     * @return self For method chaining, or 404 response if file not found
     *
     * @throws \RuntimeException If file cannot be read
     *
     * @example
     * ```php
     * (new Response())->file('/var/www/uploads/document.pdf', null, 'report.pdf');
     * ```
     */
    public function file(string $path, ?string $contentType = null, ?string $downloadName = null): self
    {
        if (!file_exists($path) || !is_readable($path)) {
            return $this->status(404)->json(['error' => 'File not found']);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return $this->status(500)->json(['error' => 'Failed to read file']);
        }

        $this->statusCode = 200;
        $this->body = $contents;

        if ($contentType) {
            $this->headers['Content-Type'] = $contentType;
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = false;
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
            }
            $this->headers['Content-Type'] = is_string($mime) ? $mime : 'application/octet-stream';
        }

        $this->headers['Content-Length'] = (string)strlen($this->body);

        if ($downloadName) {
            $this->headers['Content-Disposition'] = 'attachment; filename="' . $downloadName . '"';
        }

        return $this;
    }

    /**
     * Queue a Set-Cookie header on this response.
     *
     * The cookie is buffered until {@see send()} / {@see toWorkermanResponse()}
     * runs, so the call chains cleanly with `->json()` / `->html()` /
     * `->redirect()`. Pass `maxAge` in seconds; null omits Max-Age. To
     * delete a cookie, send the same name with an empty value and
     * `maxAge: 0`.
     *
     * @param string $name      Cookie name (RFC 6265).
     * @param string $value     Cookie value; URL-encoded by the encoder
     *                          on output.
     * @param int|null $maxAge  Lifetime in seconds; null = session cookie.
     * @param string $path      Default `/` so it applies site-wide.
     * @param string $domain    Default empty (host-only).
     * @param bool $secure      Send only over HTTPS.
     * @param bool $httpOnly    Block JS access via document.cookie.
     * @param string $sameSite  `Strict`, `Lax`, or `None`. Empty omits.
     *
     * @return self For method chaining.
     */
    public function cookie(
        string $name,
        string $value = '',
        ?int $maxAge = null,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'maxAge' => $maxAge,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $this;
    }

    /**
     * Convenience: clear a cookie by setting it empty with Max-Age=0.
     *
     * @param string $name   Cookie name to clear.
     * @param string $path   Must match the path used when setting it.
     * @param string $domain Must match the domain used when setting it.
     *
     * @return self For method chaining.
     */
    public function clearCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->cookie($name, '', 0, $path, $domain);
    }

    /**
     * Creates a redirect response.
     *
     * @param string $url The URL to redirect to
     * @param int $statusCode The redirect status code (301, 302, 307, 308)
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->redirect('https://example.com/new-location');
     * ```
     */
    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Location'] = $url;
        return $this;
    }

    /**
     * Creates a no-content response.
     *
     * Commonly used for successful DELETE operations.
     *
     * @param int $statusCode The status code (default 204 No Content)
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->noContent();
     * ```
     */
    public function noContent(int $statusCode = 204): self
    {
        $this->statusCode = $statusCode;
        $this->body = '';
        return $this;
    }

    /**
     * Adds multiple headers at once.
     *
     * @param array<string, string> $headers Associative array of header names to values
     * @return self For method chaining
     *
     * @example
     * ```php
     * (new Response())->withHeaders([
     *     'X-Custom-1' => 'value1',
     *     'X-Custom-2' => 'value2',
     * ])->json(['ok' => true]);
     * ```
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * Sends the response to the client.
     *
     * Outputs the HTTP status, all headers, and the response body.
     * This method should be called after building the response.
     *
     * @return void
     *
     * @example
     * ```php
     * (new Response())->status(200)->json(['success' => true])->send();
     * ```
     */
    public function send(): void
    {
        // Set status code header
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        // Send queued cookies. setcookie() emits one Set-Cookie header
        // per call; PHP itself handles URL-encoding the value.
        foreach ($this->cookies as $cookie) {
            /** @var array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: 'Lax'|'Strict'|'None'} $options */
            $options = [
                'path'     => $cookie['path'],
                'domain'   => $cookie['domain'],
                'secure'   => $cookie['secure'],
                'httponly' => $cookie['httpOnly'],
            ];
            if ($cookie['maxAge'] !== null) {
                $options['expires'] = $cookie['maxAge'] === 0 ? 0 : time() + $cookie['maxAge'];
            }
            // setcookie()'s `samesite` only accepts the canonical
            // values; normalise here so PHPStan accepts the union and
            // we don't pass through whatever string the caller stored.
            $sameSite = $cookie['sameSite'];
            if ($sameSite === 'Lax' || $sameSite === 'Strict' || $sameSite === 'None') {
                $options['samesite'] = $sameSite;
            }
            setcookie($cookie['name'], $cookie['value'], $options);
        }

        // Send body
        echo $this->body;
    }

    /**
     * Convert to a Workerman HTTP response — used when this server runs
     * as a long-running Workerman worker (see {@see \Phlix\Server\Core\Application::boot()}).
     */
    public function toWorkermanResponse(): WorkermanResponse
    {
        $wr = new WorkermanResponse($this->statusCode, $this->headers, $this->body);

        // Workerman's Response::cookie() builds a proper Set-Cookie
        // header and supports stacking multiple cookies — match the
        // semantics of PHP's setcookie() in CGI mode.
        foreach ($this->cookies as $cookie) {
            $wr->cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['maxAge'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly'],
                $cookie['sameSite'],
            );
        }

        return $wr;
    }

    /**
     * Converts the response to its string representation.
     *
     * Returns the full HTTP response as a string, useful for
     * testing or caching purposes.
     *
     * @return string The full HTTP response string
     */
    public function toString(): string
    {
        $response = "HTTP/{$this->version} {$this->statusCode} {$this->getStatusText()}\r\n";
        foreach ($this->headers as $name => $value) {
            $response .= "$name: $value\r\n";
        }
        $response .= "\r\n";
        $response .= $this->body;
        return $response;
    }

    /**
     * Gets the status text for the current status code.
     *
     * @return string The status text (e.g., "OK", "Not Found")
     */
    private function getStatusText(): string
    {
        $statusTexts = [
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $statusTexts[$this->statusCode] ?? 'Unknown';
    }
}
