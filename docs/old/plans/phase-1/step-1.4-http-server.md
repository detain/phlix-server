# Step 1.4: HTTP Server Foundation

**Phase:** 1 - Core Media Server Foundation  
**Plan File:** step-1.4-http-server.md  
**Objective:** Implement HTTP request/response handling, request routing system, and middleware pipeline

---

## Overview

This step builds the HTTP foundation including Request and Response classes, a Router with middleware support, and the Application entry point. This is critical for all API endpoints.

**Prerequisites:** Step 1.3 must be completed first.

---

## Tasks

### 1.4.1 Create HTTP Request Class

Create `src/Server/Http/Request.php`:
```php
<?php

namespace Phlex\Server\Http;

class Request
{
    public string $method;
    public string $path;
    public string $queryString;
    public array $headers;
    public array $query;
    public array $body;
    public array $files;
    public string $remoteIp;
    public int $remotePort;
    public string $protocol;
    public ?string $bearerToken = null;

    public static function fromGlobals(): self
    {
        $request = new self();
        $request->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $request->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $request->queryString = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '';
        $request->headers = self::parseHeaders();
        $request->query = $_GET;
        $request->body = json_decode(file_get_contents('php://input'), true) ?? [];
        $request->files = $_FILES;
        $request->remoteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $request->remotePort = (int)($_SERVER['REMOTE_PORT'] ?? 0);
        $request->protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $request->bearerToken = $request->getBearerToken();
        
        return $request;
    }

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

    public function getHeader(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        if (isset($this->headers[$name])) {
            return $this->headers[$name];
        }
        $key = 'HTTP_' . $normalized;
        return $_SERVER[$key] ?? null;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

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

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    public function isJson(): bool
    {
        return str_contains($this->getHeader('Content-Type') ?? '', 'application/json');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]);
    }
}
```

### 1.4.2 Create HTTP Response Class

Create `src/Server/Http/Response.php`:
```php
<?php

namespace Phlex\Server\Http;

class Response
{
    public int $statusCode = 200;
    public array $headers = [];
    public string $body = '';
    public string $version = '1.1';

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(array $data, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/json';
        $this->body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    public function html(string $html, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'text/html; charset=utf-8';
        $this->body = $html;
        return $this;
    }

    public function text(string $text, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'text/plain; charset=utf-8';
        $this->body = $text;
        return $this;
    }

    public function xml(string $xml, int $statusCode = 200): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/xml; charset=utf-8';
        $this->body = $xml;
        return $this;
    }

    public function file(string $path, ?string $contentType = null, ?string $downloadName = null): self
    {
        if (!file_exists($path)) {
            return $this->status(404)->json(['error' => 'File not found']);
        }

        $this->statusCode = 200;
        $this->body = file_get_contents($path);
        
        if ($contentType) {
            $this->headers['Content-Type'] = $contentType;
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $this->headers['Content-Type'] = finfo_file($finfo, $path);
            finfo_close($finfo);
        }
        
        $this->headers['Content-Length'] = strlen($this->body);
        
        if ($downloadName) {
            $this->headers['Content-Disposition'] = 'attachment; filename="' . $downloadName . '"';
        }
        
        return $this;
    }

    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->statusCode = $statusCode;
        $this->headers['Location'] = $url;
        return $this;
    }

    public function noContent(int $statusCode = 204): self
    {
        $this->statusCode = $statusCode;
        $this->body = '';
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function send(): void
    {
        // Set status code header
        http_response_code($this->statusCode);
        
        // Send headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        
        // Send body
        echo $this->body;
    }

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
```

### 1.4.3 Create Router Class

Create `src/Server/Http/Router.php`:
```php
<?php

namespace Phlex\Server\Http;

class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $middleware = [];
    private array $groupMiddleware = [];
    private ?string $groupPrefix = null;

    public function get(string $path, callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function options(string $path, callable|array $handler): self
    {
        return $this->addRoute('OPTIONS', $path, $handler);
    }

    public function any(string $path, callable|array $handler): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->addRoute($method, $path, $handler);
        }
        return $this;
    }

    public function match(array $methods, string $path, callable|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }

    private function addRoute(string $method, string $path, callable|array $handler): self
    {
        $fullPath = $this->groupPrefix ? $this->groupPrefix . $path : $path;
        
        // Convert path parameters like {id} to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[$method][$pattern] = [
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
            'path' => $fullPath,
        ];
        
        return $this;
    }

    public function middleware(callable $middleware): self
    {
        $this->groupMiddleware[] = $middleware;
        return $this;
    }

    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;
        
        $this->groupPrefix = $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        
        $callback($this);
        
        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
        
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;
        
        if (!isset($this->routes[$method])) {
            return $this->notFound();
        }
        
        foreach ($this->routes[$method] as $pattern => $route) {
            if (preg_match($pattern, $path, $matches)) {
                // Extract path parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $request->pathParams = $params;
                
                // Apply middleware
                $middlewareHandler = $this->runMiddleware($route['middleware'], $request);
                if ($middlewareHandler instanceof Response) {
                    return $middlewareHandler;
                }
                
                // Call handler
                return $this->callHandler($route['handler'], $request, $params);
            }
        }
        
        return $this->notFound();
    }

    private function runMiddleware(array $middlewareStack, Request $request): ?Response
    {
        foreach ($middlewareStack as $middleware) {
            $result = $middleware($request);
            if ($result instanceof Response) {
                return $result;
            }
        }
        return null;
    }

    private function callHandler(callable|array $handler, Request $request, array $params): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = is_string($class) ? new $class() : $class;
            return $instance->$method($request, $params);
        }
        
        return $handler($request, $params);
    }

    private function notFound(): Response
    {
        return (new Response())
            ->status(404)
            ->json([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
            ]);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
```

### 1.4.4 Create Application Class

Create `src/Server/Core/Application.php`:
```php
<?php

namespace Phlex\Server\Core;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class Application
{
    private Router $router;
    private array $middleware = [];
    private array $config;
    private static ?Application $instance = null;

    public function __construct(string $configPath)
    {
        $this->config = include $configPath;
        $this->router = new Router();
        $this->loadRoutes();
        self::$instance = $this;
    }

    public static function getInstance(): ?Application
    {
        return self::$instance;
    }

    private function loadRoutes(): void
    {
        // Health check endpoint
        $this->router->get('/health', function(Request $request) {
            return (new Response())->json([
                'status' => 'ok',
                'timestamp' => time(),
                'version' => '1.0.0',
            ]);
        });

        // System info endpoint
        $this->router->get('/system/info', function(Request $request) {
            return (new Response())->json([
                'server' => $this->config['server']['name'] ?? 'Phlex Media Server',
                'version' => '1.0.0',
                'php_version' => PHP_VERSION,
                'workerman_version' => Workerman\Worker::VERSION,
            ]);
        });

        // API v1 routes
        $this->loadApiRoutes();
    }

    private function loadApiRoutes(): void
    {
        // Placeholder for API routes - will be populated in later phases
        $this->router->get('/api/v1', function(Request $request) {
            return (new Response())->json([
                'api' => 'Phlex Media Server',
                'version' => 'v1',
                'endpoints' => '/health, /system/info',
            ]);
        });
    }

    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        
        // Apply global middleware
        foreach ($this->middleware as $handler) {
            $result = $handler($request);
            if ($result instanceof Response) {
                $result->send();
                return;
            }
        }
        
        // Dispatch request
        try {
            $response = $this->router->dispatch($request);
            $response->send();
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function handleException(\Throwable $e): void
    {
        $logger = LoggerFactory::get(LogChannels::HTTP);
        $logger->error('Unhandled exception: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $response = (new Response())
            ->status(500)
            ->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
            ]);
        
        if ($this->config['debug'] ?? false) {
            $response->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
        
        $response->send();
    }

    public function getRouter(): Router
    {
        return $this->router;
    }
}
```

### 1.4.5 Create Unit Tests

Create `tests/unit/Server/Http/RequestTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Request;

class RequestTest extends TestCase
{
    public function testCanGetBearerToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token-123';
        
        $request = Request::fromGlobals();
        
        $this->assertEquals('test-token-123', $request->getBearerToken());
    }

    public function testGetHeaderReturnsNullWhenNotPresent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        
        unset($_SERVER['HTTP_X_CUSTOM_HEADER']);
        
        $request = Request::fromGlobals();
        
        $this->assertNull($request->getHeader('X-Custom-Header'));
    }

    public function testIsMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        
        $request = Request::fromGlobals();
        
        $this->assertFalse($request->isGet());
        $this->assertTrue($request->isPost());
        $this->assertFalse($request->isPut());
        $this->assertFalse($request->isDelete());
    }

    public function testGetClientIpWithForwardedHeader(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.1, 10.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $request = Request::fromGlobals();
        
        $this->assertEquals('192.168.1.1', $request->getClientIp());
    }
}
```

Create `tests/unit/Server/Http/ResponseTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Response;

class ResponseTest extends TestCase
{
    public function testCanCreateJsonResponse(): void
    {
        $response = (new Response())->json(['key' => 'value']);
        
        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('application/json', $response->headers['Content-Type']);
        $this->assertStringContainsString('"key"', $response->body);
    }

    public function testCanChainMethods(): void
    {
        $response = (new Response())
            ->status(201)
            ->header('X-Custom', 'value')
            ->json(['created' => true]);
        
        $this->assertEquals(201, $response->statusCode);
        $this->assertEquals('value', $response->headers['X-Custom']);
    }

    public function testCanCreateHtmlResponse(): void
    {
        $response = (new Response())->html('<h1>Hello</h1>');
        
        $this->assertEquals('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testCanRedirect(): void
    {
        $response = (new Response())->redirect('https://example.com', 301);
        
        $this->assertEquals(301, $response->statusCode);
        $this->assertEquals('https://example.com', $response->headers['Location']);
    }

    public function testNoContentResponse(): void
    {
        $response = (new Response())->noContent();
        
        $this->assertEquals(204, $response->statusCode);
        $this->assertEquals('', $response->body);
    }
}
```

Create `tests/unit/Server/Http/RouterTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Router;
use Phlex\Server\Http\Request;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testCanRegisterGetRoute(): void
    {
        $this->router->get('/test', function($req) {
            return (new Response())->json(['ok' => true]);
        });
        
        $routes = $this->router->getRoutes();
        
        $this->assertArrayHasKey('GET', $routes);
    }

    public function testCanRegisterMultipleHttpMethods(): void
    {
        $this->router->post('/test', fn() => new Response());
        $this->router->put('/test', fn() => new Response());
        $this->router->delete('/test', fn() => new Response());
        
        $routes = $this->router->getRoutes();
        
        $this->assertArrayHasKey('POST', $routes);
        $this->assertArrayHasKey('PUT', $routes);
        $this->assertArrayHasKey('DELETE', $routes);
    }

    public function testCanUsePathParameters(): void
    {
        $this->router->get('/users/{id}', function($req, $params) {
            return (new Response())->json($params);
        });
        
        // Create a mock request
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users/123';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);
        
        $this->assertEquals(200, $response->statusCode);
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $this->router->get('/exists', fn() => new Response());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/unknown';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);
        
        $this->assertEquals(404, $response->statusCode);
    }
}
```

---

## Verification

After completing all tasks:

1. Run the unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Server/Http/ --testdox
```

2. Verify all classes exist:
```bash
ls -la /home/sites/phlex/src/Server/Http/
ls -la /home/sites/phlex/src/Server/Core/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-1.4-http-server
git add .
git commit -m "Step 1.4: Implement HTTP server foundation with Request, Response, Router"
unset GITHUB_TOKEN
gh pr create --title "Step 1.4: HTTP Server Foundation" --body "Implements HTTP request/response handling, Router class with middleware support, and Application entry point."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 1.5: WebSocket Server** (`plans/phase-1/step-1.5-websocket-server.md`).
