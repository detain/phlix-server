# Phlex Media Server - Comprehensive Implementation Plan

**Version:** 1.0  
**Date:** 2026-05-14  
**Technology Stack:** PHP 8.2+ / Workerman 5.x / Webman  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Findings](#2-research-findings)
3. [System Architecture](#3-system-architecture)
4. [Phase 1: Core Media Server Foundation](#phase-1-core-media-server-foundation)
5. [Phase 2: Media Library & Metadata System](#phase-2-media-library--metadata-system)
6. [Phase 3: Streaming & Transcoding Engine](#phase-3-streaming--transcoding-engine)
7. [Phase 4: Authentication & Session Management](#phase-4-authentication--session-management)
8. [Phase 5: Centralized Web Portal](#phase-5-centralized-web-portal)
9. [Phase 6: Client Applications](#phase-6-client-applications)
10. [Phase 7: Advanced Features](#phase-7-advanced-features)
11. [Testing Strategy](#testing-strategy)
12. [Redundancy & Review Cycles](#redundancy--review-cycles)
13. [Client-Specific Plans](#client-specific-plans)

---

## 1. Executive Summary

### Project Overview
Build a Plex/Emby/Jellyfin-compatible PHP-based streaming media server using Workerman/Webman for high-concurrency async operations, with a centralized web portal for authentication and remote access.

### Core Objectives
1. **Media Server** - Local media management, transcoding, and streaming
2. **Centralized Portal** - Cloud access for authentication and media browsing
3. **Multi-Platform Clients** - Samsung Smart TV, Roku, Windows, iOS/Android
4. **Enterprise-Grade** - High availability, redundancy, extensive testing

### Key Differentiators
- Pure PHP implementation using Workerman for async concurrency
- Unified authentication across local and remote access
- Built-in redundancy and verification cycles
- Comprehensive test coverage at every phase

---

## 2. Research Findings

### 2.1 Jellyfin Architecture Analysis

Based on examination of Jellyfin's open-source codebase (GPL-2.0, C#/.NET 10):

#### Core Server Components
```
jellyfin/
├── Jellyfin.Api/                 # REST API Controllers
│   ├── Controllers/
│   │   ├── DynamicHlsController.cs    # HLS streaming endpoints
│   │   ├── VideosController.cs         # Video playback control
│   │   ├── SessionController.cs        # Session management
│   │   ├── SyncPlayController.cs       # Group watching
│   │   ├── LibraryController.cs        # Media library operations
│   │   └── PlaystateController.cs      # Playback state
│   ├── WebSocketListeners/             # Real-time updates
│   └── Helpers/
│       ├── EncodingHelper.cs           # FFmpeg command building
│       └── FileStreamResponseHelpers.cs
│
├── MediaBrowser.Controller/       # Business logic layer
│   ├── Session/                   # Session management
│   ├── Library/                   # Media library operations
│   ├── Streaming/                 # Stream handling
│   ├── SyncPlay/                  # Group playback
│   ├── MediaEncoding/             # Transcoding orchestration
│   └── Entities/                  # Domain entities
│
├── MediaBrowser.MediaEncoding/    # Transcoding engine
│   ├── Encoder/
│   │   ├── MediaEncoder.cs        # FFmpeg wrapper
│   │   └── EncoderValidator.cs    # FFmpeg validation
│   ├── Transcoding/
│   │   ├── TranscodeManager.cs    # Transcode job management
│   │   └── TranscodingJob.cs      # Job state tracking
│   └── Subtitles/                 # Subtitle extraction/conversion
│
├── MediaBrowser.Model/            # Data transfer objects
│   ├── Dlna/                      # DLNA profiles and stream info
│   ├── Session/                   # Session DTOs
│   └── MediaInfo/                 # Media metadata
│
└── MediaBrowser.Providers/        # Metadata providers
    ├── Manager/
    │   ├── ProviderManager.cs     # Provider orchestration
    │   └── MetadataService.cs     # Generic metadata service
    └── Plugins/
        ├── Tmdb/                  # TheMovieDb integration
        └── Tvdb/                  # TVDB integration
```

#### Key API Endpoints
| Endpoint | Purpose |
|----------|---------|
| `GET /Videos/{itemId}/live.m3u8` | HLS dynamic streaming |
| `GET /Videos/{itemId}/main.m3u8` | Static HLS playlist |
| `GET /Sessions` | List active sessions |
| `POST /Sessions/Play` | Start playback |
| `POST /Playstate` | Control playback (pause/seek) |
| `GET /SyncPlay/Groups` | List SyncPlay groups |
| `POST /SyncPlay/{groupId}/Join` | Join group viewing |
| `GET /Library/VirtualFolders` | List media libraries |
| `GET /Items/{itemId}` | Get item metadata |
| `GET /Users/{userId}` | User information |

#### Session Management
```csharp
// From SessionManager.cs
public interface ISessionManager
{
    event EventHandler<PlaybackProgressEventArgs> PlaybackStart;
    event EventHandler<PlaybackProgressEventArgs> PlaybackStopped;
    event EventHandler<PlaybackProgressEventArgs> PlaybackProgress;

    Task<AuthenticationResult> AuthenticateNewSession(AuthenticationRequest request);
    IReadOnlyList<SessionInfoDto> GetSessions(Guid userId, string deviceId, ...);
    Task SendPlayCommand(SessionInfo session, PlayRequest request);
    Task SendProgressUpdate(SessionInfo session, ProgressRequest request);
}
```

#### Transcoding Pipeline
```csharp
// From TranscodeManager.cs
public async Task<TranscodingJob> StartFfMpeg(
    StreamState state,
    string outputPath,
    string commandLineArguments,
    ...
)
{
    // 1. Validate FFmpeg availability
    // 2. Create transcoding directory
    // 3. Spawn FFmpeg process
    // 4. Monitor process health
    // 5. Handle completion/failure
}
```

### 2.2 Plex/Emby Feature Comparison

| Feature | Plex | Emby | Jellyfin | Phlex (Target) |
|---------|------|------|----------|----------------|
| Media Library | ✓ | ✓ | ✓ | ✓ |
| Metadata Fetch | ✓ | ✓ | ✓ | ✓ |
| Transcoding | ✓ | ✓ | ✓ | ✓ |
| HLS Streaming | ✓ | ✓ | ✓ | ✓ |
| DLNA Support | ✓ | ✓ | ✓ | ✓ |
| Live TV | ✓ | ✓ | ✓ | ✓ |
| SyncPlay | ✗ | ✗ | ✓ | ✓ |
| Mobile Apps | ✓ | ✓ | ✓ | ✓ |
| Smart TV Apps | ✓ | ✓ | Limited | ✓ |
| User Sync | ✓ | ✓ | ✓ | ✓ |
| Remote Access | ✓ | ✓ | ✓ | ✓ |

### 2.3 Workerman/Webman Capabilities

From workerman/workerman repository analysis:

**Core Features:**
- Event-driven async I/O
- Multi-process architecture
- Built-in HTTP, WebSocket, TCP protocols
- Timer and cron support
- Hot reload
- Process monitoring

**Performance Benchmarks:**
- 10-100x faster than traditional PHP
- 100k+ concurrent connections per server
- Native support for long-running connections

**Relevant Components:**
```php
// Async HTTP client for API calls
Workerman\Protocols\Http\Request
Workerman\Protocols\Http\Response

// WebSocket for real-time updates
Workerman\Protocols\Websocket

// Timer for scheduled tasks
Workerman\Timer

// File monitoring
Workerman\Events\EventInterface
```

---

## 3. System Architecture

### 3.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Client Applications                       │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────┤
│ Samsung  │   Roku   │ Windows  │  iOS/    │  Web     │  Android │
│ Smart TV │    TV    │   App    │ Android  │  Portal  │   App    │
└────┬─────┴────┬─────┴────┬─────┴────┬─────┴────┬─────┴────┬─────┘
     │          │          │          │          │          │
     └──────────┴──────────┴────┬─────┴──────────┴──────────┘
                                │
                    ┌───────────▼───────────┐
                    │   API Gateway Layer   │
                    │   (Workerman HTTP)    │
                    └───────────┬───────────┘
                                │
         ┌──────────────────────┼──────────────────────┐
         │                      │                      │
    ┌────▼────┐           ┌─────▼─────┐          ┌────▼────┐
    │ Media   │           │  Auth &   │          │ Stream  │
    │ Server  │           │  Session  │          │ Control │
    │ Node 1  │     ...   │   Server  │    ...   │ Node N  │
    └────┬────┘           └─────┬─────┘          └────┬────┘
         │                      │                      │
         └──────────────────────┼──────────────────────┘
                                │
                    ┌───────────▼───────────┐
                    │    Shared Storage     │
                    │  (Database + Files)   │
                    └───────────────────────┘
```

### 3.2 Component Architecture

```
phlex/
├── src/
│   ├── Server/                     # Main Workerman server
│   │   ├── Core/
│   │   │   ├── Application.php     # Main entry point
│   │   │   ├── ServerCore.php      # Core server logic
│   │   │   └── ProcessManager.php  # Process orchestration
│   │   ├── Http/
│   │   │   ├── HttpServer.php      # HTTP handling
│   │   │   ├── RequestHandler.php  # Request routing
│   │   │   └── ResponseBuilder.php # Response construction
│   │   └── WebSocket/
│   │       ├── WebSocketServer.php
│   │       └── MessageHandler.php
│   │
│   ├── Media/                      # Media processing
│   │   ├── Library/
│   │   │   ├── LibraryManager.php
│   │   │   ├── MediaScanner.php
│   │   │   ├── ItemRepository.php
│   │   │   └── FolderWatcher.php
│   │   ├── Streaming/
│   │   │   ├── StreamManager.php
│   │   │   ├── HlsStreamer.php
│   │   │   ├── StreamState.php
│   │   │   └── QualitySelector.php
│   │   ├── Transcoding/
│   │   │   ├── TranscodeManager.php
│   │   │   ├── FfmpegRunner.php
│   │   │   ├── EncodingHelper.php
│   │   │   └── ThumbnailGenerator.php
│   │   └── Metadata/
│   │       ├── MetadataProvider.php
│   │       ├── TmdbProvider.php
│   │       ├── TvdbProvider.php
│   │       └── LocalProvider.php
│   │
│   ├── Session/                    # Session management
│   │   ├── SessionManager.php
│   │   ├── SessionHandler.php
│   │   ├── PlaybackController.php
│   │   └── SyncPlay/
│   │       ├── SyncPlayManager.php
│   │       ├── GroupState.php
│   │       └── TimeSync.php
│   │
│   ├── Auth/                       # Authentication
│   │   ├── AuthManager.php
│   │   ├── JwtHandler.php
│   │   ├── ApiKeyManager.php
│   │   └── PasswordReset.php
│   │
│   ├── Dlna/                       # DLNA support
│   │   ├── DlnaServer.php
│   │   ├── DeviceProfile.php
│   │   └── StreamBuilder.php
│   │
│   ├── LiveTv/                     # Live TV
│   │   ├── LiveTvManager.php
│   │   ├── ChannelManager.php
│   │   ├── GuideManager.php
│   │   └── Recorder.php
│   │
│   └── Common/                     # Shared utilities
│       ├── Database/
│       │   ├── Connection.php
│       │   └── QueryBuilder.php
│       ├── Cache/
│       │   └── RedisCache.php
│       ├── Logger/
│       │   └── StructuredLogger.php
│       └── Events/
│           └── EventDispatcher.php
│
├── config/
│   ├── server.php
│   ├── database.php
│   ├── cache.php
│   ├── ffmpeg.php
│   └── security.php
│
├── tests/
│   ├── unit/
│   ├── integration/
│   └── e2e/
│
└── public/
    └── index.php
```

---

## Phase 1: Core Media Server Foundation

### Step 1.1: Project Setup

**Objectives:**
- Initialize Workerman/Webman project structure
- Configure autoloading and dependencies
- Set up logging infrastructure

**Tasks:**

1.1.1 Create project structure
```
mkdir -p phlex/{src/{Server/{Core,Http,WebSocket},Media/{Library,Streaming,Transcoding,Metadata},Session/{Playback,SyncPlay},Auth,Dlna,LiveTv,Common/{Database,Cache,Logger,Events}},config,tests/{unit,integration,e2e},public}
```

1.1.2 Initialize Composer
```bash
cd phlex && composer init
composer require workerman/workerman:^5.0
composer require workerman/webman-framework:^2.0
composer require workerman/mysql:^8.0
composer require workerman/redis:^2.0
composer require symfony/yaml:^7.0
composer require monolog/monolog:^3.0
```

1.1.3 Create base configuration
```php
// config/server.php
return [
    'server' => [
        'name' => 'Phlex Media Server',
        'host' => '0.0.0.0',
        'port' => 8096,
        'context' => [],
        'ssl' => [
            'cert' => '/path/to/cert.pem',
            'key' => '/path/to/key.pem',
        ],
    ],
    'worker' => [
        'count' => 'auto',
        'stdout_file' => '/var/log/phlex/stdout.log',
        'pid_file' => '/var/run/phlex/pid',
    ],
    'process' => [
        'reloadable' => true,
        'reuse_port' => true,
    ],
];
```

1.1.4 Create application entry point
```php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Server\Core\Application;

$app = new Application();
$app->run();
```

1.1.5 Set up PSR-4 autoloading
```json
{
    "autoload": {
        "psr-4": {
            "Phlex\\": "src/"
        }
    }
}
```

**Verification:**
- [ ] Server starts without errors
- [ ] Basic HTTP request returns valid response
- [ ] Logs are written to configured location

---

### Step 1.2: Database Layer

**Objectives:**
- Implement async database connection pool
- Create schema migrations
- Build query builder

**Tasks:**

1.2.1 Create database configuration
```php
// config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'phlex',
            'username' => 'phlex',
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'pool_size' => 20,
            'timeout' => 5,
        ],
    ],
];
```

1.2.2 Create database connection class
```php
// src/Common/Database/Connection.php
namespace Phlex\Common\Database;

use Workerman\MySQL\Connection;

class ConnectionPool
{
    private static array $connections = [];
    private static string $configPath = '';

    public static function init(string $configPath): void
    {
        self::$configPath = $configPath;
    }

    public static function getConnection(string $name = 'mysql'): Connection
    {
        if (!isset(self::$connections[$name])) {
            $config = include self::$configPath;
            $connConfig = $config['connections'][$name];
            self::$connections[$name] = new Connection(
                $connConfig['host'],
                $connConfig['port'],
                $connConfig['username'],
                $connConfig['password'],
                $connConfig['database']
            );
        }
        return self::$connections[$name];
    }
}
```

1.2.3 Create base schema
```sql
-- migrations/001_initial_schema.sql

-- Users table
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    PRIMARY KEY (id),
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- User settings table
CREATE TABLE user_settings (
    user_id CHAR(36) PRIMARY KEY,
    max_streams INT DEFAULT 3,
    max_bitrate INT DEFAULT 100000000,
    preferred_audio_language VARCHAR(10) DEFAULT 'en',
    preferred_subtitle_language VARCHAR(10) DEFAULT 'en',
    subtitle_mode ENUM('always', 'only_foreign', 'none') DEFAULT 'only_foreign',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Media items table
CREATE TABLE media_items (
    id CHAR(36) PRIMARY KEY,
    library_id CHAR(36) NOT NULL,
    parent_id CHAR(36),
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'season', 'episode', 'music', 'album', 'artist', 'video', 'audio', 'book', 'photo') NOT NULL,
    path VARCHAR(1000) NOT NULL,
    metadata_json JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_library (library_id),
    INDEX idx_parent (parent_id),
    INDEX idx_type (type),
    FULLTEXT idx_name (name)
);

-- Media streams table
CREATE TABLE media_streams (
    id CHAR(36) PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    stream_index INT NOT NULL,
    stream_type ENUM('video', 'audio', 'subtitle') NOT NULL,
    codec VARCHAR(50),
    language VARCHAR(10),
    bitrate INT,
    width INT,
    height INT,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE
);

-- Libraries table
CREATE TABLE libraries (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'music', 'photo', 'video') NOT NULL,
    paths JSON NOT NULL,
    options JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sessions table
CREATE TABLE sessions (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    device_name VARCHAR(255),
    device_type VARCHAR(50),
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_device (device_id)
);

-- Playback state table
CREATE TABLE playback_state (
    id CHAR(36) PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    position_ticks BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    playback_status ENUM('playing', 'paused', 'stopped') DEFAULT 'stopped',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE
);

-- API keys table
CREATE TABLE api_keys (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_key_hash (key_hash)
);
```

**Verification:**
- [ ] Database connection pool works
- [ ] Schema migrations run successfully
- [ ] Basic CRUD operations work

---

### Step 1.3: Logging Infrastructure

**Objectives:**
- Set up structured logging
- Configure log rotation
- Create audit logging

**Tasks:**

1.3.1 Create logger configuration
```php
// config/logger.php
return [
    'default' => 'file',
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => '/var/log/phlex/app.log',
            'max_files' => 30,
            'level' => 'debug',
        ],
        'audit' => [
            'type' => 'rotating_file',
            'path' => '/var/log/phlex/audit.log',
            'max_files' => 90,
            'level' => 'info',
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => '/var/log/phlex/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
    ],
    'processors' => [
        'context' => true,
        'request_id' => true,
        'user_id' => true,
    ],
];
```

1.3.2 Create structured logger
```php
// src/Common/Logger/StructuredLogger.php
namespace Phlex\Common\Logger;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\ContextProcessor;
use Monolog\Processor\IntrospectionProcessor;

class StructuredLogger
{
    private Logger $logger;
    private string $channel;

    public function __construct(string $channel, array $config)
    {
        $this->channel = $channel;
        $this->logger = new Logger($channel);

        foreach ($config['handlers'] as $name => $handlerConfig) {
            $handler = new RotatingFileHandler(
                $handlerConfig['path'],
                $handlerConfig['max_files'],
                $this->mapLevel($handlerConfig['level'])
            );
            $this->logger->pushHandler($handler);
        }

        $this->logger->pushProcessor(new ContextProcessor());
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $context['channel'] = $this->channel;
        $this->logger->$level($message, $context);
    }
}
```

**Verification:**
- [ ] Logs are written correctly
- [ ] Log rotation works
- [ ] Context is included in logs

---

### Step 1.4: HTTP Server Foundation

**Objectives:**
- Implement HTTP request/response handling
- Create request routing system
- Build middleware pipeline

**Tasks:**

1.4.1 Create request class
```php
// src/Server/Http/Request.php
namespace Phlex\Server\Http;

class Request
{
    public string $method;
    public string $path;
    public array $headers;
    public array $query;
    public array $body;
    public string $remoteIp;
    public int $remotePort;

    public static function fromGlobals(): self
    {
        $request = new self();
        $request->method = $_SERVER['REQUEST_METHOD'];
        $request->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $request->headers = self::parseHeaders();
        $request->query = $_GET;
        $request->body = json_decode(file_get_contents('php://input'), true) ?? [];
        $request->remoteIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $request->remotePort = (int)($_SERVER['REMOTE_PORT'] ?? 0);
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
        return $headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization') ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
```

1.4.2 Create response class
```php
// src/Server/Http/Response.php
namespace Phlex\Server\Http;

class Response
{
    public int $statusCode = 200;
    public array $headers = [];
    public string $body = '';

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

    public function json(array $data): self
    {
        $this->headers['Content-Type'] = 'application/json';
        $this->body = json_encode($data);
        return $this;
    }

    public function html(string $html): self
    {
        $this->headers['Content-Type'] = 'text/html';
        $this->body = $html;
        return $this;
    }

    public function file(string $path, string $contentType): self
    {
        $this->headers['Content-Type'] = $contentType;
        $this->body = file_get_contents($path);
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
```

1.4.3 Create router
```php
// src/Server/Http/Router.php
namespace Phlex\Server\Http;

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): self
    {
        $this->routes[$method][$path] = $handler;
        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;

        if (isset($this->routes[$method][$path])) {
            return ($this->routes[$method][$path])($request);
        }

        // 404 Not Found
        return (new Response())->status(404)->json(['error' => 'Not Found']);
    }
}
```

1.4.4 Create application class
```php
// src/Server/Core/Application.php
namespace Phlex\Server\Core;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;

class Application
{
    private Router $router;
    private array $middleware = [];

    public function __construct()
    {
        $this->router = new Router();
        $this->loadRoutes();
    }

    private function loadRoutes(): void
    {
        // Health check
        $this->router->get('/health', fn() => new Response()->json(['status' => 'ok']));

        // System info
        $this->router->get('/system/info', [SystemController::class, 'info']);
    }

    public function run(): void
    {
        $request = Request::fromGlobals();

        // Apply middleware
        foreach ($this->middleware as $handler) {
            $response = $handler($request);
            if ($response instanceof Response) {
                $response->send();
                return;
            }
        }

        $response = $this->router->dispatch($request);
        $response->send();
    }
}
```

**Verification:**
- [ ] HTTP server responds to requests
- [ ] Routing works correctly
- [ ] Middleware pipeline functions

---

### Step 1.5: WebSocket Server

**Objectives:**
- Implement WebSocket handling
- Create message protocol
- Build real-time event system

**Tasks:**

1.5.1 Create WebSocket protocol handler
```php
// src/Server/WebSocket/MessageHandler.php
namespace Phlex\Server\WebSocket;

use Workerman\Protocols\Websocket;

class MessageHandler
{
    private array $callbacks = [];

    public function on(string $event, callable $callback): void
    {
        $this->callbacks[$event] = $callback;
    }

    public function handle(string $data, Connection $connection): void
    {
        $message = json_decode($data, true);
        if (!$message || !isset($message['type'])) {
            return;
        }

        $event = $message['type'];
        $payload = $message['data'] ?? [];

        if (isset($this->callbacks[$event])) {
            ($this->callbacks[$event])($connection, $payload);
        }
    }

    public function broadcast(string $event, array $data, array $exclude = []): void
    {
        $message = json_encode(['type' => $event, 'data' => $data]);
        foreach (Connection::all() as $connection) {
            if (!in_array($connection->id, $exclude)) {
                $connection->send($message);
            }
        }
    }
}
```

1.5.2 Create WebSocket server
```php
// src/Server/WebSocket/WebSocketServer.php
namespace Phlex\Server\WebSocket;

use Workerman\Worker;

class WebSocketServer
{
    private Worker $worker;
    private MessageHandler $handler;

    public function __construct(string $host, int $port)
    {
        $this->handler = new MessageHandler();
        $this->worker = new Worker("websocket://$host:$port");
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onMessage(Connection $connection, string $data): void
    {
        $this->handler->handle($data, $connection);
    }

    public function onClose(Connection $connection): void
    {
        $this->handler->broadcast('client_disconnected', ['id' => $connection->id]);
    }

    public function getHandler(): MessageHandler
    {
        return $this->handler;
    }

    public function run(): void
    {
        Worker::runAll();
    }
}
```

**Verification:**
- [ ] WebSocket connections established
- [ ] Messages sent/received correctly
- [ ] Events broadcast properly

---

## Phase 1 Review Step: Verification & Gap Analysis

**After completing Phase 1, conduct thorough review:**

1. **Unit Tests**
   - Run all unit tests for core components
   - Achieve >80% code coverage on Server/Core and Common/Database

2. **Integration Tests**
   - Test database connection pool
   - Test HTTP request/response cycle
   - Test WebSocket connection lifecycle

3. **Gap Analysis**
   - Identify any missed Jellyfin features
   - Document deviations from reference architecture
   - Plan corrective actions for Phase 2

4. **Documentation**
   - Update architecture diagrams
   - Document all configuration options
   - Create API documentation skeleton

---

## Phase 2: Media Library & Metadata System

### Step 2.1: Media Library Management

**Objectives:**
- Scan and index media files
- Manage library folders
- Handle file system events

**Tasks:**

2.1.1 Create library manager
```php
// src/Media/Library/LibraryManager.php
namespace Phlex\Media\Library;

class LibraryManager
{
    private Connection $db;
    private LibraryScanner $scanner;
    private FolderWatcher $watcher;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        LibraryScanner $scanner,
        FolderWatcher $watcher,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
        $this->watcher = $watcher;
        $this->logger = $logger;
    }

    public function createLibrary(string $name, string $type, array $paths, array $options = []): string
    {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths, options) VALUES (?, ?, ?, ?, ?)",
            [$id, $name, $type, json_encode($paths), json_encode($options)]
        );

        // Initial scan
        $this->scanLibrary($id);

        // Start watching for changes
        $this->watcher->watch($id, $paths);

        $this->logger->info('Library created', ['library_id' => $id, 'name' => $name]);

        return $id;
    }

    public function scanLibrary(string $libraryId): void
    {
        $library = $this->getLibrary($libraryId);
        $paths = json_decode($library['paths'], true);

        foreach ($paths as $path) {
            $this->scanner->scan($libraryId, $path, $library['type']);
        }
    }

    public function getLibrary(string $id): ?array
    {
        return $this->db->query("SELECT * FROM libraries WHERE id = ?", [$id])[0] ?? null;
    }

    public function getAllLibraries(): array
    {
        return $this->db->query("SELECT * FROM libraries");
    }
}
```

2.1.2 Create media scanner
```php
// src/Media/Library/MediaScanner.php
namespace Phlex\Media\Library;

class MediaScanner
{
    private array $namingOptions;

    public function __construct()
    {
        $this->namingOptions = [
            'video' => ['avi', 'mkv', 'mp4', 'mov', 'wmv', 'flv', 'webm'],
            'audio' => ['mp3', 'flac', 'aac', 'ogg', 'wav', 'm4a'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'],
        ];
    }

    public function scan(string $libraryId, string $path, string $type): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if ($this->isValidMediaFile($extension, $type)) {
                $this->processFile($libraryId, $file, $type);
            }
        }
    }

    private function isValidMediaFile(string $extension, string $type): bool
    {
        return in_array($extension, $this->namingOptions[$type] ?? []);
    }

    private function processFile(string $libraryId, \SplFileInfo $file, string $type): void
    {
        // Extract metadata based on type
        // Create media item record
        // Queue for metadata fetching
    }
}
```

2.1.3 Create folder watcher
```php
// src/Media/Library/FolderWatcher.php
namespace Phlex\Media\Library;

use Workerman\Timer;

class FolderWatcher
{
    private StructuredLogger $logger;
    private array $watchedPaths = [];

    public function watch(string $libraryId, array $paths): void
    {
        foreach ($paths as $path) {
            $this->watchedPaths[$path] = $libraryId;
        }

        // Poll for changes every 30 seconds
        Timer::add(30, function () {
            $this->checkForChanges();
        });
    }

    private function checkForChanges(): void
    {
        foreach ($this->watchedPaths as $path => $libraryId) {
            // Compare file mtimes with database
            // Trigger rescan if changes detected
        }
    }
}
```

**Verification:**
- [ ] Libraries can be created via API
- [ ] Media files are scanned and indexed
- [ ] New files detected within 60 seconds

---

### Step 2.2: Metadata Fetching

**Objectives:**
- Fetch metadata from external providers
- Parse media file metadata
- Store and cache metadata

**Tasks:**

2.2.1 Create metadata provider interface
```php
// src/Media/Metadata/MetadataProvider.php
namespace Phlex\Media\Metadata;

interface MetadataProviderInterface
{
    public function search(string $query, array $options = []): array;
    public function getDetails(string $id): array;
    public function getImages(string $id): array;
}
```

2.2.2 Create TMDB provider
```php
// src/Media/Metadata/TmdbProvider.php
namespace Phlex\Media\Metadata;

use Phlex\Common\Http\HttpClient;

class TmdbProvider implements MetadataProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.themoviedb.org/3';
    private HttpClient $http;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->http = new HttpClient();
    }

    public function search(string $query, array $options = []): array
    {
        $params = [
            'api_key' => $this->apiKey,
            'query' => $query,
            'language' => $options['language'] ?? 'en-US',
        ];

        $response = $this->http->get("$this->baseUrl/search/movie", $params);
        return $this->parseSearchResults($response);
    }

    public function getDetails(string $id): array
    {
        $params = ['api_key' => $this->apiKey];
        $response = $this->http->get("$this->baseUrl/movie/$id", $params);
        return $this->parseDetails($response);
    }

    public function getImages(string $id): array
    {
        $params = ['api_key' => $this->apiKey];
        $response = $this->http->get("$this->baseUrl/movie/$id/images", $params);
        return $this->parseImages($response);
    }
}
```

2.2.3 Create metadata manager
```php
// src/Media/Metadata/MetadataManager.php
namespace Phlex\Media\Metadata;

class MetadataManager
{
    private array $providers = [];
    private Connection $db;
    private Cache $cache;

    public function registerProvider(string $type, MetadataProviderInterface $provider): void
    {
        $this->providers[$type] = $provider;
    }

    public function refreshMetadata(string $itemId): void
    {
        $item = $this->db->query("SELECT * FROM media_items WHERE id = ?", [$itemId])[0];

        if (!$item) {
            return;
        }

        $type = $this->getMediaType($item['type']);
        if (!isset($this->providers[$type])) {
            return;
        }

        $provider = $this->providers[$type];

        // Search and match
        $results = $provider->search($item['name']);
        if (empty($results)) {
            return;
        }

        // Get best match
        $match = $this->findBestMatch($results, $item);
        if (!$match) {
            return;
        }

        // Fetch full details
        $details = $provider->getDetails($match['id']);
        $images = $provider->getImages($match['id']);

        // Update database
        $this->updateItemMetadata($itemId, $details, $images);
    }

    private function findBestMatch(array $results, array $item): ?array
    {
        // Fuzzy matching logic
        // Consider year, type, etc.
    }
}
```

**Verification:**
- [ ] TMDB API integration works
- [ ] Metadata stored correctly
- [ ] Cache reduces API calls

---

### Step 2.3: Item Repository

**Objectives:**
- CRUD operations for media items
- Query optimization
- Batch operations

**Tasks:**

2.3.1 Create item repository
```php
// src/Media/Library/ItemRepository.php
namespace Phlex\Media\Library;

class ItemRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(string $id): ?array
    {
        return $this->db->query(
            "SELECT * FROM media_items WHERE id = ?",
            [$id]
        )[0] ?? null;
    }

    public function findByPath(string $path): ?array
    {
        return $this->db->query(
            "SELECT * FROM media_items WHERE path = ?",
            [$path]
        )[0] ?? null;
    }

    public function getChildren(string $parentId): array
    {
        return $this->db->query(
            "SELECT * FROM media_items WHERE parent_id = ? ORDER BY name",
            [$parentId]
        );
    }

    public function getByType(string $libraryId, string $type, int $limit = 100, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT * FROM media_items WHERE library_id = ? AND type = ? LIMIT ? OFFSET ?",
            [$libraryId, $type, $limit, $offset]
        );
    }

    public function search(string $query, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT * FROM media_items WHERE MATCH(name) AGAINST(? IN BOOLEAN MODE) LIMIT ?",
            [$query, $limit]
        );
    }

    public function create(array $data): string
    {
        $id = $data['id'] ?? $this->generateUuid();
        $this->db->query(
            "INSERT INTO media_items (id, library_id, parent_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['library_id'],
                $data['parent_id'] ?? null,
                $data['name'],
                $data['type'],
                $data['path'],
                json_encode($data['metadata_json'] ?? [])
            ]
        );
        return $id;
    }

    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $values[] = $key === 'metadata_json' ? json_encode($value) : $value;
        }
        $values[] = $id;

        $this->db->query(
            "UPDATE media_items SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
    }

    public function delete(string $id): void
    {
        $this->db->query("DELETE FROM media_items WHERE id = ?", [$id]);
    }
}
```

**Verification:**
- [ ] All CRUD operations work
- [ ] Full-text search functions
- [ ] Batch operations complete

---

## Phase 2 Review Step: Verification & Gap Analysis

**After completing Phase 2, conduct thorough review:**

1. **Unit Tests**
   - Test library scanner with sample media files
   - Test metadata provider mocking
   - Test item repository operations

2. **Integration Tests**
   - End-to-end library creation and scanning
   - Metadata refresh workflow
   - Search functionality

3. **Gap Analysis**
   - Compare against Jellyfin library features
   - Identify missing metadata providers
   - Document any parsing limitations

---

## Phase 3: Streaming & Transcoding Engine

### Step 3.1: Stream Manager

**Objectives:**
- Manage active streams
- Track stream state
- Handle stream multiplexing

**Tasks:**

3.1.1 Create stream state
```php
// src/Media/Streaming/StreamState.php
namespace Phlex\Media\Streaming;

class StreamState
{
    public string $id;
    public string $mediaItemId;
    public string $sessionId;
    public string $userId;
    public int $positionTicks;
    public int $durationTicks;
    public string $status; // playing, paused, stopped
    public string $playMethod; // direct, transcode
    public array $requestedStreams = [];
    public array $actualStreams = [];
    public ?string $transcodePath;
    public float $startedAt;

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'media_item_id' => $this->mediaItemId,
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'position_ticks' => $this->positionTicks,
            'duration_ticks' => $this->durationTicks,
            'status' => $this->status,
            'play_method' => $this->playMethod,
            'requested_streams' => $this->requestedStreams,
            'actual_streams' => $this->actualStreams,
        ];
    }
}
```

3.1.2 Create stream manager
```php
// src/Media/Streaming/StreamManager.php
namespace Phlex\Media\Streaming;

class StreamManager
{
    private array $activeStreams = [];
    private Connection $db;
    private TranscodeManager $transcodeManager;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        TranscodeManager $transcodeManager,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->transcodeManager = $transcodeManager;
        $this->logger = $logger;
    }

    public function createStream(string $mediaItemId, string $sessionId, array $options = []): StreamState
    {
        $item = $this->getMediaItem($mediaItemId);
        if (!$item) {
            throw new \Exception("Media item not found");
        }

        $state = new StreamState();
        $state->id = $this->generateUuid();
        $state->mediaItemId = $mediaItemId;
        $state->sessionId = $sessionId;
        $state->durationTicks = $item['duration_ticks'] ?? 0;
        $state->status = 'playing';
        $state->startedAt = microtime(true);

        // Determine play method based on device capabilities
        $playMethod = $this->determinePlayMethod($item, $options);
        $state->playMethod = $playMethod;

        if ($playMethod === 'transcode') {
            $state->transcodePath = $this->transcodeManager->startTranscode($state, $options);
        }

        $this->activeStreams[$state->id] = $state;
        $this->logger->info('Stream created', ['stream_id' => $state->id]);

        return $state;
    }

    public function updatePosition(string $streamId, int $positionTicks): void
    {
        if (!isset($this->activeStreams[$streamId])) {
            return;
        }

        $this->activeStreams[$streamId]->positionTicks = $positionTicks;

        // Persist to database periodically
        $this->persistPlaybackState($streamId);
    }

    public function stopStream(string $streamId): void
    {
        if (!isset($this->activeStreams[$streamId])) {
            return;
        }

        $state = $this->activeStreams[$streamId];

        if ($state->transcodePath) {
            $this->transcodeManager->stopTranscode($state->transcodePath);
        }

        $this->persistPlaybackState($streamId);
        unset($this->activeStreams[$streamId]);

        $this->logger->info('Stream stopped', ['stream_id' => $streamId]);
    }

    public function getActiveStreams(): array
    {
        return array_values($this->activeStreams);
    }
}
```

3.1.3 Create quality selector
```php
// src/Media/Streaming/QualitySelector.php
namespace Phlex\Media\Streaming;

class QualitySelector
{
    public function selectQuality(array $deviceProfile, int $maxBitrate): array
    {
        // Sort by bandwidth
        usort($deviceProfile['DirectPlayProfiles'], function ($a, $b) {
            return ($b['MaxBitrate'] ?? PHP_INT_MAX) - ($a['MaxBitrate'] ?? PHP_INT_MAX);
        });

        foreach ($deviceProfile['DirectPlayProfiles'] as $profile) {
            if ($this->canDirectPlay($profile, $maxBitrate)) {
                return [
                    'method' => 'direct',
                    'container' => $profile['Container'],
                    'video_codec' => $profile['VideoCodec'] ?? null,
                    'audio_codec' => $profile['AudioCodec'] ?? null,
                ];
            }
        }

        // Fall back to transcoding
        return [
            'method' => 'transcode',
            'container' => 'ts',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ];
    }

    private function canDirectPlay(array $profile, int $maxBitrate): bool
    {
        $profileMaxBitrate = $profile['MaxBitrate'] ?? PHP_INT_MAX;
        return $profileMaxBitrate >= $maxBitrate;
    }
}
```

**Verification:**
- [ ] Streams can be created and tracked
- [ ] Stream state persists correctly
- [ ] Multiple concurrent streams supported

---

### Step 3.2: HLS Streaming

**Objectives:**
- Generate HLS playlists
- Segment media files
- Support adaptive bitrate

**Tasks:**

3.2.1 Create HLS streamer
```php
// src/Media/Streaming/HlsStreamer.php
namespace Phlex\Media\Streaming;

class HlsStreamer
{
    private string $segmentDir;
    private int $segmentDuration = 6; // seconds

    public function __construct(string $segmentDir)
    {
        $this->segmentDir = $segmentDir;
    }

    public function generatePlaylist(string $mediaItemId, array $options = []): string
    {
        $item = $this->getMediaItem($mediaItemId);
        $playlist = $this->buildMasterPlaylist($item, $options);

        return $playlist;
    }

    public function getSegment(string $mediaItemId, int $segmentNumber): string
    {
        $segmentPath = "$this->segmentDir/$mediaItemId/segment_{$segmentNumber}.ts";

        if (!file_exists($segmentPath)) {
            throw new \Exception("Segment not found");
        }

        return file_get_contents($segmentPath);
    }

    private function buildMasterPlaylist(array $item, array $options): string
    {
        $streams = $this->getMediaStreams($item['id']);

        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";

        foreach ($this->getQualityLevels($streams) as $level) {
            $playlist .= "#EXT-X-STREAM-INF:BANDWIDTH={$level['bandwidth']}";
            $playlist .= ",RESOLUTION={$level['width']}x{$level['height']}\n";
            $playlist .= "/hls/{$item['id']}/playlist_{$level['index']}.m3u8\n";
        }

        return $playlist;
    }

    private function getQualityLevels(array $streams): array
    {
        // Determine available quality levels based on source
        return [
            ['index' => 0, 'bandwidth' => 5000000, 'width' => 1920, 'height' => 1080],
            ['index' => 1, 'bandwidth' => 2500000, 'width' => 1280, 'height' => 720],
            ['index' => 2, 'bandwidth' => 1000000, 'width' = 854, 'height' => 480],
        ];
    }
}
```

3.2.2 Create segmenter
```php
// src/Media/Streaming/Segmenter.php
namespace Phlex\Media\Streaming;

class Segmenter
{
    private string $ffmpegPath;
    private string $segmentDir;

    public function __construct(string $ffmpegPath, string $segmentDir)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->segmentDir = $segmentDir;
    }

    public function segment(string $mediaPath, string $outputDir, int $duration = 6): void
    {
        $cmd = sprintf(
            '%s -i %s -c copy -f segment -segment_time %d -segment_list %s/playlist.m3u8 %s/segment_%%03d.ts',
            $this->ffmpegPath,
            escapeshellarg($mediaPath),
            $duration,
            escapeshellarg($outputDir),
            escapeshellarg($outputDir)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Segmentation failed: " . implode("\n", $output));
        }
    }

    public function getSegmentCount(string $outputDir): int
    {
        $files = glob("$outputDir/segment_*.ts");
        return count($files);
    }
}
```

**Verification:**
- [ ] HLS playlists generated correctly
- [ ] Segments created from source media
- [ ] Adaptive bitrate switching works

---

### Step 3.3: Transcoding Engine

**Objectives:**
- Integrate FFmpeg for transcoding
- Manage transcoding processes
- Handle subtitle extraction

**Tasks:**

3.3.1 Create FFmpeg runner
```php
// src/Media/Transcoding/FfmpegRunner.php
namespace Phlex\Media\Transcoding;

class FfmpegRunner
{
    private string $ffmpegPath;
    private string $ffprobePath;
    private StructuredLogger $logger;

    public function __construct(string $ffmpegPath, string $ffprobePath, StructuredLogger $logger)
    {
        $this->ffmpegPath = $ffmpegPath;
        $this->ffprobePath = $ffprobePath;
        $this->logger = $logger;
    }

    public function probe(string $mediaPath): array
    {
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_format -show_streams %s',
            $this->ffprobePath,
            escapeshellarg($mediaPath)
        );

        $output = shell_exec($cmd);
        return json_decode($output, true) ?? [];
    }

    public function transcode(array $input, array $output, callable $onProgress = null): int
    {
        $cmd = $this->buildCommand($input, $output);

        $this->logger->info('Starting transcode', ['command' => $cmd]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \Exception("Failed to start FFmpeg");
        }

        // Monitor progress
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($onProgress && $line) {
                $progress = $this->parseProgress($line, $input['duration']);
                $onProgress($progress);
            }
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    private function buildCommand(array $input, array $output): string
    {
        $cmd = sprintf('%s -i %s', $this->ffmpegPath, escapeshellarg($input['path']));

        // Video codec
        if (isset($output['video_codec'])) {
            $cmd .= sprintf(' -c:v %s', $output['video_codec']);
            if ($output['video_codec'] === 'libx264') {
                $cmd .= ' -preset medium -crf 23';
            }
        }

        // Audio codec
        if (isset($output['audio_codec'])) {
            $cmd .= sprintf(' -c:a %s', $output['audio_codec']);
        }

        // Resolution
        if (isset($output['width']) && isset($output['height'])) {
            $cmd .= sprintf(' -vf scale=%d:%d', $output['width'], $output['height']);
        }

        // Bitrate
        if (isset($output['video_bitrate'])) {
            $cmd .= sprintf(' -b:v %d', $output['video_bitrate']);
        }
        if (isset($output['audio_bitrate'])) {
            $cmd .= sprintf(' -b:a %d', $output['audio_bitrate']);
        }

        // Output
        $cmd .= ' ' . escapeshellarg($output['path']);

        return $cmd;
    }

    private function parseProgress(string $line, float $duration): float
    {
        // Parse time=hh:mm:ss.ms from FFmpeg output
        if (preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
            $time = (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (float)$matches[3];
            return $duration > 0 ? ($time / $duration) * 100 : 0;
        }
        return 0;
    }
}
```

3.3.2 Create transcode manager
```php
// src/Media/Transcoding/TranscodeManager.php
namespace Phlex\Media\Transcoding;

use Workerman\Timer;

class TranscodeManager
{
    private FfmpegRunner $ffmpeg;
    private EncodingHelper $encodingHelper;
    private string $transcodeDir;
    private array $activeJobs = [];
    private StructuredLogger $logger;

    public function __construct(
        FfmpegRunner $ffmpeg,
        EncodingHelper $encodingHelper,
        string $transcodeDir,
        StructuredLogger $logger
    ) {
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->logger = $logger;

        // Cleanup old transcodes periodically
        Timer::add(300, function () {
            $this->cleanupOldTranscodes();
        });
    }

    public function startTranscode(StreamState $state, array $options = []): string
    {
        $jobId = $this->generateUuid();
        $outputDir = "$this->transcodeDir/$jobId";
        mkdir($outputDir, 0755, true);

        $inputPath = $this->getMediaPath($state->mediaItemId);
        $inputInfo = $this->ffmpeg->probe($inputPath);

        $outputOptions = $this->encodingHelper->getTranscodeOptions(
            $inputInfo,
            $options['device_profile'] ?? []
        );

        $outputPath = "$outputDir/output.ts";

        $process = $this->ffmpeg->transcode(
            ['path' => $inputPath, 'duration' => $inputInfo['format']['duration'] ?? 0],
            array_merge($outputOptions, ['path' => $outputPath]),
            function ($progress) use ($jobId) {
                $this->updateJobProgress($jobId, $progress);
            }
        );

        $this->activeJobs[$jobId] = [
            'state' => $state,
            'output_dir' => $outputDir,
            'output_path' => $outputPath,
            'started_at' => time(),
            'progress' => 0,
        ];

        return $outputPath;
    }

    public function stopTranscode(string $jobId): void
    {
        if (!isset($this->activeJobs[$jobId])) {
            return;
        }

        $job = $this->activeJobs[$jobId];

        // Kill FFmpeg process if running
        $this->killProcess($jobId);

        // Cleanup files
        $this->removeDirectory($job['output_dir']);

        unset($this->activeJobs[$jobId]);

        $this->logger->info('Transcode stopped', ['job_id' => $jobId]);
    }

    private function cleanupOldTranscodes(): void
    {
        $maxAge = 3600; // 1 hour
        $now = time();

        foreach ($this->activeJobs as $jobId => $job) {
            if ($now - $job['started_at'] > $maxAge) {
                $this->stopTranscode($jobId);
            }
        }

        // Also cleanup orphaned directories
        $this->cleanupOrphanedTranscodes();
    }
}
```

3.3.3 Create encoding helper
```php
// src/Media/Transcoding/EncodingHelper.php
namespace Phlex\Media\Transcoding;

class EncodingHelper
{
    public function getTranscodeOptions(array $inputInfo, array $deviceProfile): array
    {
        $videoStream = $this->getVideoStream($inputInfo);
        $audioStream = $this->getAudioStream($inputInfo);

        $options = [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'video_bitrate' => $this->calculateVideoBitrate($videoStream, $deviceProfile),
            'audio_bitrate' => 128000,
        ];

        // Adjust for HDR content
        if ($this->isHdr($videoStream)) {
            $options['video_codec'] = 'libx265';
            $options['pix_fmt'] = 'yuv420p10le';
        }

        return $options;
    }

    public function calculateVideoBitrate(array $videoStream, array $deviceProfile): int
    {
        $sourceWidth = $videoStream['width'] ?? 1920;
        $sourceHeight = $videoStream['height'] ?? 1080;
        $sourceBitrate = $videoStream['bitrate'] ?? 5000000;

        $maxBitrate = $deviceProfile['MaxStreamingBitrate'] ?? 10000000;

        // Scale down if needed
        if ($sourceWidth > 1920 || $sourceBitrate > $maxBitrate) {
            return min($maxBitrate, $sourceBitrate);
        }

        return $sourceBitrate;
    }

    private function isHdr(array $stream): bool
    {
        $colorPrimaries = $stream['color_primaries'] ?? '';
        return in_array($colorPrimaries, ['bt2020', 'bt2020c', 'bt2100']);
    }
}
```

**Verification:**
- [ ] FFmpeg integration works
- [ ] Transcoding produces valid output
- [ ] Progress reporting functions

---

### Step 3.4: DLNA Support

**Objectives:**
- Implement DLNA server
- Create device profiles
- Support DLNA playback

**Tasks:**

3.4.1 Create DLNA server
```php
// src/Dlna/DlnaServer.php
namespace Phlex\Dlna;

use Phlex\Media\Library\ItemRepository;

class DlnaServer
{
    private ItemRepository $itemRepository;
    private Connection $db;

    public function __construct(ItemRepository $itemRepository, Connection $db)
    {
        $this->itemRepository = $itemRepository;
        $this->db = $db;
    }

    public function getServiceDescription(): string
    {
        return file_get_contents(__DIR__ . '/resources/ContentDirectory.xml');
    }

    public function browse(string $objectId): array
    {
        if ($objectId === '0') {
            // Root - return libraries
            return $this->getLibraries();
        }

        // Get children of object
        return $this->itemRepository->getChildren($objectId);
    }

    public function search(string $containerId, string $searchCriteria): array
    {
        // Parse DLNA search criteria
        // Execute search
        return $this->itemRepository->search($searchCriteria);
    }
}
```

3.4.2 Create device profile
```php
// src/Dlna/DeviceProfile.php
namespace Phlex\Dlna;

class DeviceProfile
{
    public string $name;
    public array $directPlayProfiles = [];
    public array $transcodingProfiles = [];
    public array $containerProfiles = [];
    public array $codecProfiles = [];

    public static function fromArray(array $data): self
    {
        $profile = new self();
        $profile->name = $data['Name'] ?? 'Generic';
        $profile->directPlayProfiles = $data['DirectPlayProfiles'] ?? [];
        $profile->transcodingProfiles = $data['TranscodingProfiles'] ?? [];
        return $profile;
    }

    public static function getDefault(): self
    {
        return self::fromArray([
            'Name' => 'Generic',
            'DirectPlayProfiles' => [
                [
                    'Container' => 'mkv,mp4,mov',
                    'Type' => 'Video',
                    'VideoCodec' => 'h264,hevc',
                    'AudioCodec' => 'aac,mp3,ac3',
                ],
            ],
            'TranscodingProfiles' => [
                [
                    'Container' => 'ts',
                    'Type' => 'Video',
                    'VideoCodec' => 'h264',
                    'AudioCodec' => 'aac',
                ],
            ],
        ]);
    }
}
```

**Verification:**
- [ ] DLNA discovery works
- [ ] Device profiles match correctly
- [ ] DLNA playback functions

---

## Phase 3 Review Step: Verification & Gap Analysis

**After completing Phase 3, conduct thorough review:**

1. **Unit Tests**
   - Test FFmpeg command generation
   - Test quality selection logic
   - Test HLS playlist generation

2. **Integration Tests**
   - Test end-to-end streaming
   - Test transcoding workflow
   - Test adaptive bitrate switching

3. **Gap Analysis**
   - Compare streaming features against Jellyfin
   - Identify missing codec support
   - Document performance characteristics

---

## Phase 4: Authentication & Session Management

### Step 4.1: User Authentication

**Objectives:**
- Implement user registration/login
- Create JWT authentication
- Handle password reset

**Tasks:**

4.1.1 Create auth manager
```php
// src/Auth/AuthManager.php
namespace Phlex\Auth;

class AuthManager
{
    private Connection $db;
    private JwtHandler $jwt;
    private PasswordReset $passwordReset;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        JwtHandler $jwt,
        PasswordReset $passwordReset,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->jwt = $jwt;
        $this->passwordReset = $passwordReset;
        $this->logger = $logger;
    }

    public function register(string $username, string $email, string $password): array
    {
        // Validate input
        $this->validateRegistration($username, $email, $password);

        // Check for existing user
        if ($this->userExists($username, $email)) {
            throw new \Exception("User already exists");
        }

        // Create user
        $id = $this->generateUuid();
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)",
            [$id, $username, $email, $passwordHash]
        );

        // Create default settings
        $this->db->query(
            "INSERT INTO user_settings (user_id) VALUES (?)",
            [$id]
        );

        $this->logger->info('User registered', ['user_id' => $id, 'username' => $username]);

        // Generate token
        return $this->createAuthResult($id, $username);
    }

    public function login(string $username, string $password): array
    {
        $user = $this->db->query(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        )[0] ?? null;

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->logger->warning('Failed login attempt', ['username' => $username]);
            throw new \Exception("Invalid credentials");
        }

        // Update last login
        $this->db->query(
            "UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?",
            [$user['id']]
        );

        $this->logger->info('User logged in', ['user_id' => $user['id']]);

        return $this->createAuthResult($user['id'], $user['username']);
    }

    private function createAuthResult(string $userId, string $username): array
    {
        $token = $this->jwt->encode([
            'user_id' => $userId,
            'username' => $username,
            'exp' => time() + 86400 * 7, // 7 days
        ]);

        return [
            'token' => $token,
            'user' => [
                'id' => $userId,
                'username' => $username,
            ],
        ];
    }
}
```

4.1.2 Create JWT handler
```php
// src/Auth/JwtHandler.php
namespace Phlex\Auth;

class JwtHandler
{
    private string $secret;
    private string $algorithm = 'HS256';

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function encode(array $payload): string
    {
        $header = ['alg' => $this->algorithm, 'typ' => 'JWT'];
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            "$headerEncoded.$payloadEncoded",
            $this->secret,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true)
        );

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
```

4.1.3 Create API key manager
```php
// src/Auth/ApiKeyManager.php
namespace Phlex\Auth;

class ApiKeyManager
{
    private Connection $db;
    private StructuredLogger $logger;

    public function __construct(Connection $db, StructuredLogger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function createKey(string $userId, string $name, ?int $expiresIn = null): array
    {
        $id = $this->generateUuid();
        $key = $this->generateSecureKey();
        $keyHash = hash('sha256', $key);

        $expiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

        $this->db->query(
            "INSERT INTO api_keys (id, user_id, key_hash, name, expires_at) VALUES (?, ?, ?, ?, ?)",
            [$id, $userId, $keyHash, $name, $expiresAt]
        );

        $this->logger->info('API key created', ['key_id' => $id, 'user_id' => $userId]);

        return [
            'id' => $id,
            'key' => $key, // Only returned once
            'name' => $name,
            'expires_at' => $expiresAt,
        ];
    }

    public function validateKey(string $key): ?string
    {
        $keyHash = hash('sha256', $key);

        $result = $this->db->query(
            "SELECT user_id FROM api_keys WHERE key_hash = ? AND (expires_at IS NULL OR expires_at > NOW())",
            [$keyHash]
        )[0] ?? null;

        return $result['user_id'] ?? null;
    }

    private function generateSecureKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
```

**Verification:**
- [ ] User registration works
- [ ] Login returns valid JWT
- [ ] API key authentication functions

---

### Step 4.2: Session Management

**Objectives:**
- Track user sessions
- Handle playback sessions
- Implement session events

**Tasks:**

4.2.1 Create session manager
```php
// src/Session/SessionManager.php
namespace Phlex\Session;

use Phlex\Media\Streaming\StreamManager;

class SessionManager
{
    private Connection $db;
    private StreamManager $streamManager;
    private StructuredLogger $logger;
    private array $activeSessions = [];

    public function __construct(
        Connection $db,
        StreamManager $streamManager,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->streamManager = $streamManager;
        $this->logger = $logger;
    }

    public function createSession(string $userId, array $deviceInfo): string
    {
        $id = $this->generateUuid();
        $now = time();

        // Store in memory for quick access
        $this->activeSessions[$id] = [
            'id' => $id,
            'user_id' => $userId,
            'device_id' => $deviceInfo['device_id'],
            'device_name' => $deviceInfo['device_name'] ?? 'Unknown',
            'device_type' => $deviceInfo['device_type'] ?? 'web',
            'created_at' => $now,
            'last_activity' => $now,
            'playback_state' => null,
        ];

        // Persist to database
        $this->db->query(
            "INSERT INTO sessions (id, user_id, device_id, device_name, device_type) VALUES (?, ?, ?, ?, ?)",
            [$id, $userId, $deviceInfo['device_id'], $deviceInfo['device_name'] ?? null, $deviceInfo['device_type'] ?? 'web']
        );

        $this->logger->info('Session created', ['session_id' => $id, 'user_id' => $userId]);

        return $id;
    }

    public function getSession(string $sessionId): ?array
    {
        return $this->activeSessions[$sessionId] ?? null;
    }

    public function getUserSessions(string $userId): array
    {
        return array_filter($this->activeSessions, function ($session) use ($userId) {
            return $session['user_id'] === $userId;
        });
    }

    public function updateActivity(string $sessionId): void
    {
        if (!isset($this->activeSessions[$sessionId])) {
            return;
        }

        $this->activeSessions[$sessionId]['last_activity'] = time();

        // Update database periodically (debounced)
        $this->debounceUpdate($sessionId);
    }

    public function endSession(string $sessionId): void
    {
        if (!isset($this->activeSessions[$sessionId])) {
            return;
        }

        // Stop any active playback
        $session = $this->activeSessions[$sessionId];
        if ($session['playback_state']) {
            $this->streamManager->stopStream($session['playback_state']['stream_id']);
        }

        unset($this->activeSessions[$sessionId]);

        $this->db->query("DELETE FROM sessions WHERE id = ?", [$sessionId]);

        $this->logger->info('Session ended', ['session_id' => $sessionId]);
    }

    public function broadcastSessionUpdate(string $userId, string $event, array $data): void
    {
        $userSessions = $this->getUserSessions($userId);

        foreach ($userSessions as $session) {
            // Send WebSocket message to session's WebSocket connection
            $this->sendToSession($session['id'], $event, $data);
        }
    }
}
```

4.2.2 Create playback controller
```php
// src/Session/PlaybackController.php
namespace Phlex\Session\Playback;

class PlaybackController
{
    private SessionManager $sessionManager;
    private StreamManager $streamManager;
    private PlaybackQueue $queue;
    private StructuredLogger $logger;

    public function __construct(
        SessionManager $sessionManager,
        StreamManager $streamManager,
        PlaybackQueue $queue,
        StructuredLogger $logger
    ) {
        $this->sessionManager = $sessionManager;
        $this->streamManager = $streamManager;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function play(string $sessionId, array $options): array
    {
        $session = $this->sessionManager->getSession($sessionId);
        if (!$session) {
            throw new \Exception("Session not found");
        }

        $itemId = $options['item_id'];
        $startPosition = $options['start_position_ticks'] ?? 0;

        // Create stream
        $stream = $this->streamManager->createStream($itemId, $sessionId, $options);

        // Update session
        $session['playback_state'] = [
            'stream_id' => $stream->id,
            'item_id' => $itemId,
            'position_ticks' => $startPosition,
        ];
        $this->sessionManager->updateSession($sessionId, $session);

        // Broadcast to other sessions
        $this->sessionManager->broadcastSessionUpdate(
            $session['user_id'],
            'PlaybackStarted',
            $stream->toArray()
        );

        return [
            'stream_info' => $stream->toArray(),
            'playback_commands' => ['Play', 'Pause', 'Stop', 'Seek'],
        ];
    }

    public function pause(string $sessionId): void
    {
        $session = $this->sessionManager->getSession($sessionId);
        if (!$session || !$session['playback_state']) {
            return;
        }

        $streamId = $session['playback_state']['stream_id'];
        $this->streamManager->updateStatus($streamId, 'paused');

        $this->sessionManager->broadcastSessionUpdate(
            $session['user_id'],
            'PlaybackPaused',
            ['stream_id' => $streamId]
        );
    }

    public function seek(string $sessionId, int $positionTicks): void
    {
        $session = $this->sessionManager->getSession($sessionId);
        if (!$session || !$session['playback_state']) {
            return;
        }

        $streamId = $session['playback_state']['stream_id'];
        $this->streamManager->updatePosition($streamId, $positionTicks);

        $session['playback_state']['position_ticks'] = $positionTicks;
        $this->sessionManager->updateSession($sessionId, $session);

        $this->sessionManager->broadcastSessionUpdate(
            $session['user_id'],
            'PlaybackSeeked',
            ['stream_id' => $streamId, 'position_ticks' => $positionTicks]
        );
    }
}
```

**Verification:**
- [ ] Sessions created correctly
- [ ] Playback state tracked
- [ ] Events broadcast to other sessions

---

## Phase 4 Review Step: Verification & Gap Analysis

**After completing Phase 4, conduct thorough review:**

1. **Unit Tests**
   - Test JWT encode/decode
   - Test password hashing
   - Test session creation

2. **Integration Tests**
   - Test complete login flow
   - Test session lifecycle
   - Test playback synchronization

3. **Gap Analysis**
   - Compare session features against Jellyfin
   - Identify missing auth methods
   - Document security measures

---

## Phase 5: Centralized Web Portal

### Step 5.1: Web Portal Architecture

**Objectives:**
- Create web-based management portal
- Implement user dashboard
- Build library browser

**Tasks:**

5.1.1 Create portal router
```php
// src/Portal/Router.php
namespace Phlex\Portal;

class Router
{
    private array $routes = [];

    public function __construct()
    {
        $this->loadRoutes();
    }

    private function loadRoutes(): void
    {
        // Auth routes
        $this->routes['GET /auth/login'] = [AuthController::class, 'loginForm'];
        $this->routes['POST /auth/login'] = [AuthController::class, 'login'];
        $this->routes['GET /auth/register'] = [AuthController::class, 'registerForm'];
        $this->routes['POST /auth/register'] = [AuthController::class, 'register'];
        $this->routes['POST /auth/logout'] = [AuthController::class, 'logout'];

        // Dashboard
        $this->routes['GET /'] = [DashboardController::class, 'index'];
        $this->routes['GET /dashboard'] = [DashboardController::class, 'index'];

        // Libraries
        $this->routes['GET /libraries'] = [LibraryController::class, 'index'];
        $this->routes['GET /libraries/{id}'] = [LibraryController::class, 'show'];
        $this->routes['POST /libraries'] = [LibraryController::class, 'create'];
        $this->routes['PUT /libraries/{id}'] = [LibraryController::class, 'update'];
        $this->routes['DELETE /libraries/{id}'] = [LibraryController::class, 'delete'];

        // Media items
        $this->routes['GET /items/{id}'] = [ItemController::class, 'show'];
        $this->routes['GET /items/{id}/play'] = [ItemController::class, 'play'];
        $this->routes['GET /browse/{parentId}'] = [BrowserController::class, 'browse'];

        // Settings
        $this->routes['GET /settings'] = [SettingsController::class, 'index'];
        $this->routes['PUT /settings'] = [SettingsController::class, 'update'];

        // Admin routes
        $this->routes['GET /admin/users'] = [AdminController::class, 'users'];
        $this->routes['GET /admin/sessions'] = [AdminController::class, 'sessions'];
        $this->routes['GET /admin/settings'] = [AdminController::class, 'settings'];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;

        // Find matching route
        foreach ($this->routes as $pattern => $handler) {
            [$routeMethod, $routePath] = explode(' ', $pattern, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            $params = $this->matchPath($routePath, $path);
            if ($params !== false) {
                $request->params = $params;
                return $this->executeHandler($handler, $request);
            }
        }

        return new Response(404, [], ['error' => 'Not Found']);
    }

    private function matchPath(string $pattern, string $path): array|false
    {
        // Convert {id} to regex
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    private function executeHandler(array $handler, Request $request): Response
    {
        [$class, $method] = $handler;
        $controller = new $class();

        return $controller->$method($request);
    }
}
```

5.1.2 Create dashboard controller
```php
// src/Portal/Controllers/DashboardController.php
namespace Phlex\Portal\Controllers;

class DashboardController
{
    private LibraryManager $libraryManager;
    private SessionManager $sessionManager;
    private ItemRepository $itemRepository;

    public function __construct(
        LibraryManager $libraryManager,
        SessionManager $sessionManager,
        ItemRepository $itemRepository
    ) {
        $this->libraryManager = $libraryManager;
        $this->sessionManager = $sessionManager;
        $this->itemRepository = $itemRepository;
    }

    public function index(Request $request): Response
    {
        $user = $request->user;
        $libraries = $this->libraryManager->getAllLibraries();
        $recentItems = $this->itemRepository->getRecent($user['id'], 10);
        $activeStreams = $this->sessionManager->getActiveStreamsForUser($user['id']);

        return new Response(200, [], [
            'view' => 'dashboard',
            'data' => [
                'libraries' => $libraries,
                'recent_items' => $recentItems,
                'active_streams' => $activeStreams,
                'user' => $user,
            ],
        ]);
    }
}
```

5.1.3 Create library controller
```php
// src/Portal/Controllers/LibraryController.php
namespace Phlex\Portal\Controllers;

class LibraryController
{
    private LibraryManager $libraryManager;

    public function __construct(LibraryManager $libraryManager)
    {
        $this->libraryManager = $libraryManager;
    }

    public function index(Request $request): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();

        return new Response(200, [], [
            'view' => 'libraries/index',
            'data' => ['libraries' => $libraries],
        ]);
    }

    public function show(Request $request): Response
    {
        $library = $this->libraryManager->getLibrary($request->params['id']);

        if (!$library) {
            return new Response(404, [], ['error' => 'Library not found']);
        }

        $items = $this->libraryManager->getLibraryItems($library['id']);

        return new Response(200, [], [
            'view' => 'libraries/show',
            'data' => [
                'library' => $library,
                'items' => $items,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $data = $request->body;

        $libraryId = $this->libraryManager->createLibrary(
            $data['name'],
            $data['type'],
            $data['paths'],
            $data['options'] ?? []
        );

        return new Response(201, [], [
            'library_id' => $libraryId,
            'redirect' => "/libraries/$libraryId",
        ]);
    }
}
```

**Verification:**
- [ ] Portal pages render correctly
- [ ] Navigation works
- [ ] User dashboard displays data

---

### Step 5.2: Media Browser

**Objectives:**
- Create media browsing interface
- Implement search functionality
- Build item detail views

**Tasks:**

5.2.1 Create browser controller
```php
// src/Portal/Controllers/BrowserController.php
namespace Phlex\Portal\Controllers;

class BrowserController
{
    private ItemRepository $itemRepository;
    private LibraryManager $libraryManager;

    public function browse(Request $request): Response
    {
        $parentId = $request->params['parentId'] ?? null;

        if ($parentId === 'root') {
            // Show libraries
            $libraries = $this->libraryManager->getAllLibraries();
            return $this->renderLibraries($libraries);
        }

        // Show folder contents
        $items = $parentId
            ? $this->itemRepository->getChildren($parentId)
            : $this->itemRepository->getRootItems();

        return $this->renderItems($items);
    }

    public function search(Request $request): Response
    {
        $query = $request->query['q'] ?? '';
        $type = $request->query['type'] ?? null;

        $results = $this->itemRepository->search($query, [
            'type' => $type,
            'limit' => 50,
        ]);

        return new Response(200, [], [
            'view' => 'search/results',
            'data' => [
                'query' => $query,
                'results' => $results,
                'count' => count($results),
            ],
        ]);
    }
}
```

5.2.2 Create item controller
```php
// src/Portal/Controllers/ItemController.php
namespace Phlex\Portal\Controllers;

class ItemController
{
    private ItemRepository $itemRepository;
    private StreamManager $streamManager;

    public function show(Request $request): Response
    {
        $item = $this->itemRepository->findById($request->params['id']);

        if (!$item) {
            return new Response(404, [], ['error' => 'Item not found']);
        }

        // Get additional info
        $similarItems = $this->itemRepository->getSimilar($item['id'], 6);
        $userData = $this->itemRepository->getUserData($item['id'], $request->user['id']);

        return new Response(200, [], [
            'view' => 'items/show',
            'data' => [
                'item' => $item,
                'similar_items' => $similarItems,
                'user_data' => $userData,
            ],
        ]);
    }

    public function play(Request $request): Response
    {
        $item = $this->itemRepository->findById($request->params['id']);

        if (!$item) {
            return new Response(404, [], ['error' => 'Item not found']);
        }

        // Determine best playback method
        $playbackInfo = $this->streamManager->getPlaybackInfo($item, [
            'device_profile' => $this->getDeviceProfile($request),
        ]);

        return new Response(200, [], [
            'view' => 'player',
            'data' => [
                'item' => $item,
                'playback_info' => $playbackInfo,
            ],
        ]);
    }
}
```

**Verification:**
- [ ] Browse view shows items
- [ ] Search returns results
- [ ] Item details display correctly

---

## Phase 5 Review Step: Verification & Gap Analysis

**After completing Phase 5, conduct thorough review:**

1. **Unit Tests**
   - Test route matching
   - Test controller logic
   - Test view rendering

2. **Integration Tests**
   - Test portal navigation
   - Test user flows
   - Test search functionality

3. **Gap Analysis**
   - Compare portal features against Plex/Emby
   - Identify missing UI components
   - Document user experience issues

---

## Phase 6: Client Applications

### Step 6.1: Client SDK Architecture

**Objectives:**
- Create unified API client
- Implement device profiles
- Build real-time communication

**Tasks:**

6.1.1 Create API client structure
```
clients/
├── shared/
│   ├── ApiClient.ts          # Base API client
│   ├── ApiClientInterface.ts # Client interface
│   ├── types/                # TypeScript types
│   └── utils/                # Utilities
├── web/
│   └── WebPlayer.ts          # Web player implementation
├── samsung-tv/
│   ├── TizenPlayer.ts        # Tizen TV player
│   └── app/                  # Tizen app project
├── roku/
│   ├── RokuPlayer.ts         # Roku player
│   └── channel/              # Roku channel project
├── windows/
│   └── WindowsPlayer/        # Windows desktop app
└── mobile/
    └── MobilePlayer/         # iOS/Android mobile app
```

6.1.2 Create TypeScript API client
```typescript
// clients/shared/ApiClient.ts
export class ApiClient {
    private baseUrl: string;
    private token: string | null = null;
    private deviceId: string;
    private deviceName: string;

    constructor(baseUrl: string, deviceId: string, deviceName: string) {
        this.baseUrl = baseUrl;
        this.deviceId = deviceId;
        this.deviceName = deviceName;
    }

    setToken(token: string): void {
        this.token = token;
    }

    async request<T>(method: string, path: string, body?: object): Promise<T> {
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
        };

        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        const response = await fetch(`${this.baseUrl}${path}`, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });

        if (!response.ok) {
            throw new Error(`API error: ${response.status}`);
        }

        return response.json();
    }

    // Authentication
    async login(username: string, password: string): Promise<AuthResult> {
        return this.request('POST', '/auth/login', { username, password });
    }

    // Sessions
    async getSessions(): Promise<SessionInfo[]> {
        return this.request('GET', '/Sessions');
    }

    // Playback
    async play(itemId: string, startPosition?: number): Promise<PlaybackInfo> {
        return this.request('POST', '/Sessions/Play', {
            item_id: itemId,
            start_position_ticks: startPosition,
        });
    }

    // Items
    async getItem(itemId: string): Promise<MediaItem> {
        return this.request('GET', `/Items/${itemId}`);
    }

    async getItems(parentId: string): Promise<MediaItem[]> {
        return this.request('GET', `/Items?parentId=${parentId}`);
    }

    // Libraries
    async getLibraries(): Promise<Library[]> {
        return this.request('GET', '/Library/VirtualFolders');
    }

    // WebSocket for real-time updates
    createWebSocket(): WebSocket {
        const ws = new WebSocket(`wss://${this.baseUrl}/ws`);
        ws.onmessage = (event) => {
            const message = JSON.parse(event.data);
            this.handleWebSocketMessage(message);
        };
        return ws;
    }

    private handleWebSocketMessage(message: any): void {
        // Handle session updates, playback progress, etc.
    }
}
```

**Verification:**
- [ ] API client connects to server
- [ ] Authentication works
- [ ] Real-time updates function

---

### Step 6.2: Samsung Smart TV (Tizen) App

**Objectives:**
- Create Tizen TV application
- Implement video player
- Support Samsung TV remote

**Tasks:**

6.2.1 Set up Tizen project
```
clients/samsung-tv/
├── app/
│   ├── index.html
│   ├── js/
│   │   ├── main.js
│   │   ├── player.js
│   │   ├── api.js
│   │   └── utils.js
│   ├── css/
│   │   └── style.css
│   └── config.xml
└── tizen/
    └── TvWidgetApp/
```

6.2.2 Create Tizen player
```typescript
// clients/samsung-tv/app/js/player.ts
export class TizenPlayer {
    private api: ApiClient;
    private videoElement: HTMLVideoElement;
    private currentItem: MediaItem | null = null;
    private playbackInfo: PlaybackInfo | null = null;

    constructor(api: ApiClient) {
        this.api = api;
        this.videoElement = document.getElementById('videoPlayer') as HTMLVideoElement;
        this.setupControls();
    }

    private setupControls(): void {
        // Map Tizen remote buttons
        document.addEventListener('keydown', (e) => {
            switch (e.key) {
                case 'Play':
                    this.play();
                    break;
                case 'Pause':
                    this.pause();
                    break;
                case 'Stop':
                    this.stop();
                    break;
                case 'ArrowLeft':
                    this.seek(-10);
                    break;
                case 'ArrowRight':
                    this.seek(10);
                    break;
                case 'ColorF0Red':
                    // Exit
                    break;
            }
        });
    }

    async loadItem(itemId: string): Promise<void> {
        this.currentItem = await this.api.getItem(itemId);
        this.playbackInfo = await this.api.getPlaybackInfo(itemId);

        // Update UI
        this.updateNowPlaying();
    }

    async play(): Promise<void> {
        if (!this.playbackInfo) return;

        const streamUrl = this.getStreamUrl();
        this.videoElement.src = streamUrl;
        await this.videoElement.play();
    }

    pause(): void {
        this.videoElement.pause();
    }

    stop(): void {
        this.videoElement.pause();
        this.videoElement.src = '';
        this.currentItem = null;
    }

    seek(seconds: number): void {
        const newTime = this.videoElement.currentTime + seconds;
        this.videoElement.currentTime = Math.max(0, Math.min(newTime, this.videoElement.duration));
    }

    private getStreamUrl(): string {
        if (this.playbackInfo.method === 'direct') {
            return `${this.api.baseUrl}/Videos/${this.currentItem.id}/stream`;
        } else {
            return `${this.api.baseUrl}/Videos/${this.currentItem.id}/live.m3u8`;
        }
    }

    private updateNowPlaying(): void {
        // Update on-screen display
    }
}
```

6.2.3 Create Tizen app config
```xml
<!-- clients/samsung-tv/app/config.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<widget xmlns="http://www.w3.org/ns/widgets" xmlns:tizen="http://tizen.org/ns/widgets" id="http://phlex.app" version="1.0.0">
    <access origin="*" subdomains="*"></access>
    <tizen:application id="phlex.app.player" package="phlex" required_version="2.3"/>
    <content src="index.html"/>
    <icon src="icon.png"/>
    <name>Phlex</name>
    <tizen:privilege name="http://tizen.org/privilege/internet"/>
    <tizen:privilege name="http://tizen.org/privilege/tv.inputdevice"/>
    <tizen:privilege name="http://tizen.org/privilege/network.get"/>
</widget>
```

**Verification:**
- [ ] Tizen app builds successfully
- [ ] Remote buttons map correctly
- [ ] Video playback functions

---

### Step 6.3: Roku TV App

**Objectives:**
- Create Roku channel
- Implement video player
- Support Roku remote

**Tasks:**

6.3.1 Set up Roku project
```
clients/roku/
├── source/
│   ├── Main.brs
│   ├── Api.brs
│   ├── Player.brs
│   └── Scene.brs
├── components/
│   ├── HomeScene.brs
│   ├── VideoPlayer.brs
│   └── Grid.brs
├── manifest
└── images/
```

6.3.2 Create Roku BrightScript API client
```brightscript
' clients/roku/source/Api.brs
function ApiClient(baseUrl as String) as Object
    obj = {
        baseUrl: baseUrl
        token: ""
        deviceId: CreateObject("roDeviceInfo").GetDeviceUniqueId()
        deviceName: "Roku TV"

        login: function(username as String, password as String) as Object
            return m.request("POST", "/auth/login", {username: username, password: password})
        end function

        getLibraries: function() as Object
            return m.request("GET", "/Library/VirtualFolders", {})
        end function

        getItems: function(parentId as String) as Object
            return m.request("GET", "/Items?parentId=" + parentId, {})
        end function

        play: function(itemId as String) as Object
            return m.request("POST", "/Sessions/Play", {item_id: itemId})
        end function

        request: function(method as String, path as String, body as Object) as Object
            http = CreateObject("roUrlTransfer")
            http.SetUrl(m.baseUrl + path)
            http.AddHeader("Content-Type", "application/json")
            if m.token <> "" then
                http.AddHeader("Authorization", "Bearer " + m.token)
            end if

            if method = "POST" then
                http.SetPostBody(FormatJson(body))
                http.Post()
            else
                http.Get()
            end if

            response = http.GetResponseBody()
            return ParseJson(response)
        end function
    }
    return obj
end function
```

6.3.3 Create Roku manifest
```ini
# clients/roku/manifest
title=Phlex
major_version=1
minor_version=0
build_version=1
mm_icon_focus_hd=images/icon-focus-hd.png
mm_icon_side_hd=images/icon-side-hd.png
splash_screen_sd=images/splash-sd.png
splash_screen_hd=images/splash-hd.png
splash_screen_fhd=images/splash-fhd.png
```

**Verification:**
- [ ] Roku channel builds successfully
- [ ] Remote buttons function
- [ ] Video playback works

---

### Step 6.4: Windows Desktop App

**Objectives:**
- Create Windows desktop application
- Implement native video player
- Support Windows-specific features

**Tasks:**

6.4.1 Set up Electron project
```
clients/windows/
├── package.json
├── src/
│   ├── main.ts
│   ├── preload.ts
│   ├── renderer/
│   │   ├── index.html
│   │   ├── App.tsx
│   │   └── player/
│   └── api/
└── electron-builder.yml
```

6.4.2 Create Electron main process
```typescript
// clients/windows/src/main.ts
import { app, BrowserWindow, ipcMain } from 'electron';
import * as path from 'path';

let mainWindow: BrowserWindow | null = null;

app.on('ready', () => {
    mainWindow = new BrowserWindow({
        width: 1920,
        height: 1080,
        webPreferences: {
            nodeIntegration: false,
            preload: path.join(__dirname, 'preload.js'),
        },
    });

    mainWindow.loadFile(path.join(__dirname, 'renderer/index.html'));
});

ipcMain.handle('get-app-path', () => {
    return app.getPath('userData');
});
```

6.4.3 Create Windows player component
```typescript
// clients/windows/src/renderer/player/WindowsPlayer.tsx
import React, { useRef, useEffect } from 'react';

export const WindowsPlayer: React.FC<{ playbackInfo: PlaybackInfo }> = ({ playbackInfo }) => {
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        if (videoRef.current) {
            videoRef.current.src = playbackInfo.url;
        }
    }, [playbackInfo]);

    return (
        <video
            ref={videoRef}
            controls
            autoPlay
            style={{ width: '100%', height: '100%' }}
        />
    );
};
```

**Verification:**
- [ ] Windows app builds successfully
- [ ] Window controls function
- [ ] Video playback works

---

### Step 6.5: Mobile App (iOS/Android)

**Objectives:**
- Create React Native mobile application
- Implement video player
- Support mobile-specific gestures

**Tasks:**

6.5.1 Set up React Native project
```
clients/mobile/
├── package.json
├── src/
│   ├── App.tsx
│   ├── screens/
│   │   ├── HomeScreen.tsx
│   │   ├── BrowseScreen.tsx
│   │   └── PlayerScreen.tsx
│   ├── components/
│   │   ├── MediaGrid.tsx
│   │   ├── VideoPlayer.tsx
│   │   └── RemoteButton.tsx
│   └── api/
│       └── ApiClient.ts
└── android/
```

6.5.2 Create mobile video player
```typescript
// clients/mobile/src/components/VideoPlayer.tsx
import React, { useRef } from 'react';
import { Video, View, StyleSheet } from 'react-native';
import { usePlaybackControls } from '../hooks/usePlaybackControls';

export const VideoPlayer: React.FC<{ playbackInfo: PlaybackInfo }> = ({ playbackInfo }) => {
    const videoRef = useRef<Video>(null);
    const { play, pause, seek } = usePlaybackControls(videoRef);

    return (
        <View style={styles.container}>
            <Video
                ref={videoRef}
                source={{ uri: playbackInfo.url }}
                style={styles.video}
                controls
                resizeMode="contain"
                onEnd={() => {}}
            />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#000',
    },
    video: {
        flex: 1,
    },
});
```

**Verification:**
- [ ] Mobile app builds for iOS and Android
- [ ] Gestures work correctly
- [ ] Video playback functions

---

## Phase 6 Review Step: Verification & Gap Analysis

**After completing Phase 6, conduct thorough review:**

1. **Unit Tests**
   - Test API client methods
   - Test player controls
   - Test UI components

2. **Integration Tests**
   - Test client-server communication
   - Test playback on each platform
   - Test remote control mapping

3. **Gap Analysis**
   - Compare client features against Plex/Jellyfin apps
   - Identify missing functionality
   - Document platform limitations

---

## Phase 7: Advanced Features

### Step 7.1: SyncPlay (Group Watching)

**Objectives:**
- Implement group playback synchronization
- Create SyncPlay manager
- Handle time synchronization

**Tasks:**

7.1.1 Create SyncPlay manager
```php
// src/Session/SyncPlay/SyncPlayManager.php
namespace Phlex\Session\SyncPlay;

class SyncPlayManager
{
    private Connection $db;
    private SessionManager $sessionManager;
    private array $groups = [];
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        SessionManager $sessionManager,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->sessionManager = $sessionManager;
        $this->logger = $logger;
    }

    public function createGroup(string $userId, string $name): array
    {
        $groupId = $this->generateUuid();
        $user = $this->getUser($userId);

        $this->groups[$groupId] = [
            'id' => $groupId,
            'name' => $name,
            'owner_id' => $userId,
            'owner_name' => $user['display_name'] ?? $user['username'],
            'members' => [],
            'state' => 'waiting', // waiting, playing, paused
            'current_item' => null,
            'position' => 0,
            'created_at' => time(),
        ];

        $this->logger->info('SyncPlay group created', ['group_id' => $groupId]);

        return $this->groups[$groupId];
    }

    public function joinGroup(string $groupId, string $sessionId): void
    {
        if (!isset($this->groups[$groupId])) {
            throw new \Exception("Group not found");
        }

        $session = $this->sessionManager->getSession($sessionId);
        $user = $this->getUser($session['user_id']);

        $this->groups[$groupId]['members'][$sessionId] = [
            'session_id' => $sessionId,
            'user_id' => $session['user_id'],
            'display_name' => $user['display_name'] ?? $user['username'],
            'joined_at' => time(),
            'last_position' => 0,
        ];

        // Notify all members
        $this->broadcastToGroup($groupId, 'MemberJoined', [
            'session_id' => $sessionId,
            'display_name' => $user['display_name'] ?? $user['username'],
        ]);
    }

    public function setGroupState(string $groupId, string $state, ?string $itemId = null, int $position = 0): void
    {
        if (!isset($this->groups[$groupId])) {
            return;
        }

        $this->groups[$groupId]['state'] = $state;
        $this->groups[$groupId]['current_item'] = $itemId;
        $this->groups[$groupId]['position'] = $position;

        $this->broadcastToGroup($groupId, 'GroupStateChanged', [
            'state' => $state,
            'item_id' => $itemId,
            'position' => $position,
        ]);
    }

    public function syncPosition(string $groupId, string $sessionId, int $position): void
    {
        if (!isset($this->groups[$groupId]['members'][$sessionId])) {
            return;
        }

        $this->groups[$groupId]['members'][$sessionId]['last_position'] = $position;

        // Check if position differs significantly from group
        $groupPosition = $this->groups[$groupId]['position'];
        if (abs($position - $groupPosition) > 2000) { // 2 seconds
            // Adjust playback speed to sync
            $this->adjustSync($groupId, $sessionId, $position);
        }
    }

    private function adjustSync(string $groupId, string $sessionId, int $position): void
    {
        $currentPosition = $this->groups[$groupId]['position'];
        $diff = $position - $currentPosition;

        // Send position correction to member
        $this->sendToMember($groupId, $sessionId, 'SyncPosition', [
            'position' => $currentPosition,
            'adjustment' => $diff,
        ]);
    }

    private function broadcastToGroup(string $groupId, string $event, array $data): void
    {
        $group = $this->groups[$groupId];
        foreach ($group['members'] as $sessionId => $member) {
            $this->sendToMember($groupId, $sessionId, $event, $data);
        }
    }

    private function sendToMember(string $groupId, string $sessionId, string $event, array $data): void
    {
        // Send WebSocket message to session
    }
}
```

**Verification:**
- [ ] Groups can be created
- [ ] Members can join
- [ ] Playback synchronizes correctly

---

### Step 7.2: Live TV Support

**Objectives:**
- Implement channel management
- Create guide data handling
- Support DVR recording

**Tasks:**

7.2.1 Create Live TV manager
```php
// src/LiveTv/LiveTvManager.php
namespace Phlex\LiveTv;

class LiveTvManager
{
    private Connection $db;
    private ChannelManager $channelManager;
    private GuideManager $guideManager;
    private Recorder $recorder;
    private StructuredLogger $logger;

    public function __construct(
        Connection $db,
        ChannelManager $channelManager,
        GuideManager $guideManager,
        Recorder $recorder,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->channelManager = $channelManager;
        $this->guideManager = $guideManager;
        $this->recorder = $recorder;
        $this->logger = $logger;
    }

    public function getChannels(): array
    {
        return $this->channelManager->getAllChannels();
    }

    public function getGuideData(string $channelId, \DateTime $start, \DateTime $end): array
    {
        return $this->guideManager->getPrograms($channelId, $start, $end);
    }

    public function startRecording(string $programId): string
    {
        $program = $this->guideManager->getProgram($programId);

        return $this->recorder->startRecording($program);
    }

    public function get Recordings(): array
    {
        return $this->recorder->getRecordings();
    }
}
```

**Verification:**
- [ ] Channels listed correctly
- [ ] Guide data displays
- [ ] Recording functions

---

## Phase 7 Review Step: Verification & Gap Analysis

**After completing Phase 7, conduct thorough review:**

1. **Unit Tests**
   - Test SyncPlay synchronization
   - Test Live TV operations
   - Test recording functionality

2. **Integration Tests**
   - Test group watching experience
   - Test Live TV playback
   - Test DVR functionality

3. **Gap Analysis**
   - Compare features against Jellyfin
   - Identify missing functionality
   - Document implementation notes

---

## Testing Strategy

### Unit Testing
```
tests/
├── unit/
│   ├── Server/
│   │   ├── Core/
│   │   │   ├── ApplicationTest.php
│   │   │   └── ProcessManagerTest.php
│   │   └── Http/
│   │       ├── RequestTest.php
│   │       ├── ResponseTest.php
│   │       └── RouterTest.php
│   ├── Media/
│   │   ├── Library/
│   │   │   ├── LibraryManagerTest.php
│   │   │   ├── ItemRepositoryTest.php
│   │   │   └── MediaScannerTest.php
│   │   ├── Streaming/
│   │   │   ├── StreamManagerTest.php
│   │   │   ├── HlsStreamerTest.php
│   │   │   └── QualitySelectorTest.php
│   │   └── Transcoding/
│   │       ├── TranscodeManagerTest.php
│   │       ├── FfmpegRunnerTest.php
│   │       └── EncodingHelperTest.php
│   ├── Session/
│   │   ├── SessionManagerTest.php
│   │   └── PlaybackControllerTest.php
│   └── Auth/
│       ├── AuthManagerTest.php
│       ├── JwtHandlerTest.php
│       └── ApiKeyManagerTest.php
```

### Integration Testing
```
tests/
├── integration/
│   ├── Media/
│   │   ├── LibraryScanTest.php
│   │   └── MetadataRefreshTest.php
│   ├── Streaming/
│   │   ├── PlaybackTest.php
│   │   └── TranscodeTest.php
│   ├── Auth/
│   │   ├── LoginFlowTest.php
│   │   └── SessionLifecycleTest.php
│   └── Portal/
│       ├── BrowseFlowTest.php
│       └── SearchFlowTest.php
```

### End-to-End Testing
```
tests/
├── e2e/
│   ├── streaming/
│   │   ├── PlayMovieTest.php
│   │   └── TranscodePlaybackTest.php
│   ├── client/
│   │   ├── WebClientTest.php
│   │   ├── SamsungTvTest.php
│   │   ├── RokuTest.php
│   │   └── MobileTest.php
│   └── syncplay/
│       └── GroupWatchTest.php
```

### Test Coverage Requirements
| Phase | Minimum Coverage |
|-------|------------------|
| Phase 1 | 80% |
| Phase 2 | 75% |
| Phase 3 | 80% |
| Phase 4 | 85% |
| Phase 5 | 70% |
| Phase 6 | 70% |
| Phase 7 | 75% |

---

## Redundancy & Review Cycles

### Phase Review Cycle

After each phase completion, perform the following:

1. **Code Review**
   - Security audit
   - Performance review
   - Code style compliance
   - Documentation completeness

2. **Testing**
   - Run all unit tests
   - Run integration tests
   - Run E2E tests
   - Measure coverage

3. **Gap Analysis**
   - Compare against reference (Jellyfin)
   - Identify missing features
   - Document deviations
   - Plan corrective actions

4. **Documentation Update**
   - Update architecture diagrams
   - Update API documentation
   - Update deployment guides

5. **Backlog Refinement**
   - Update task estimates
   - Re-prioritize remaining work
   - Identify risks

### Redundant Verification Steps

1. **After Step 1.1**: Verify server starts, test basic HTTP request
2. **After Step 1.2**: Test all database operations, verify schema
3. **After Step 1.3**: Verify logs are written and rotated
4. **After Step 1.4**: Test all HTTP methods and routes
5. **After Step 1.5**: Test WebSocket message delivery

6. **After Step 2.1**: Verify library scan, test file detection
7. **After Step 2.2**: Verify metadata fetch, test caching
8. **After Step 2.3**: Test all repository operations

9. **After Step 3.1**: Verify stream creation and tracking
10. **After Step 3.2**: Test HLS playlist generation
11. **After Step 3.3**: Verify transcoding works
12. **After Step 3.4**: Test DLNA discovery

13. **After Step 4.1**: Verify authentication flows
14. **After Step 4.2**: Test session management

15. **After Step 5.1**: Verify portal navigation
16. **After Step 5.2**: Test media browsing

17. **After Step 6.1**: Verify SDK functionality
18. **After Each Client Step**: Test on target platform

---

## Client-Specific Plans

### A. Samsung Smart TV Plan

**Timeline:** 8 weeks

**Phases:**
1. **Week 1-2**: Tizen SDK setup, project structure
2. **Week 3-4**: API client integration
3. **Week 5-6**: Video player implementation
4. **Week 7**: Remote control mapping
5. **Week 8**: Testing and certification

**Key Resources:**
- Tizen Studio
- Samsung TV SDK documentation
- Tizen Web Device API

**Deliverables:**
- Signed .wgt package
- Tizen store submission
- Test automation suite

---

### B. Roku Plan

**Timeline:** 6 weeks

**Phases:**
1. **Week 1**: Developer account, SDK setup
2. **Week 2**: BrightScript API client
3. **Week 3-4**: Scene graph implementation
4. **Week 5**: Video playback, remote handling
5. **Week 6**: Testing, screen capture, submission

**Key Resources:**
- Roku SDK
- BrightScript language
- Scene Graph documentation

**Deliverables:**
- Private channel for testing
- Production channel
- Test automation suite

---

### C. Windows App Plan

**Timeline:** 10 weeks

**Phases:**
1. **Week 1-2**: Electron setup, project structure
2. **Week 3-4**: Native API client
3. **Week 5-6**: Video player with native codecs
4. **Week 7-8**: Windows-specific features (jump lists, notifications)
5. **Week 9**: Testing on Windows 10/11
6. **Week 10**: Microsoft Store submission

**Key Resources:**
- Electron framework
- Windows Native APIs
- Microsoft Store requirements

**Deliverables:**
- MSIX package
- Microsoft Store listing
- Auto-update infrastructure

---

### D. Mobile App Plan

**Timeline:** 12 weeks

**Phases:**
1. **Week 1-2**: React Native setup
2. **Week 3-4**: Shared API client
3. **Week 5-6**: Navigation, library browsing
4. **Week 7-8**: Video player implementation
5. **Week 9-10**: Platform-specific features (iOS/Android)
6. **Week 11**: Testing on multiple devices
7. **Week 12**: App Store / Play Store submission

**Key Resources:**
- React Native
- Native video player components
- iOS/Android platform guidelines

**Deliverables:**
- iOS App Store listing
- Google Play Store listing
- Test automation suite

---

## Appendices

### A. API Documentation Structure
```
/api/v1/
├── auth/
│   ├── POST /auth/login
│   ├── POST /auth/register
│   ├── POST /auth/logout
│   └── POST /auth/refresh
├── users/
│   ├── GET /users/me
│   └── PUT /users/me
├── sessions/
│   ├── GET /Sessions
│   ├── POST /Sessions
│   └── DELETE /Sessions/{id}
├── libraries/
│   ├── GET /Library/VirtualFolders
│   ├── POST /Library/VirtualFolders
│   └── DELETE /Library/VirtualFolders/{id}
├── items/
│   ├── GET /Items
│   ├── GET /Items/{id}
│   └── GET /Items/{id}/PlaybackInfo
├── playback/
│   ├── POST /Sessions/Play
│   ├── POST /Playstate
│   └── GET /Videos/{id}/stream
├── syncplay/
│   ├── GET /SyncPlay/Groups
│   ├── POST /SyncPlay/Groups
│   └── POST /SyncPlay/Groups/{id}/Join
└── system/
    ├── GET /health
    └── GET /system/info
```

### B. Database Schema Reference
[As defined in Phase 1]

### C. WebSocket Events
```
// Client -> Server
SessionStarted
PlaybackStarted
PlaybackProgress
PlaybackStopped
SyncPlayRequest

// Server -> Client
SessionUpdated
PlaybackNewCommand
PlaybackProgress
SyncPlayStateChanged
LibraryChanged
```

### D. FFmpeg Transcoding Reference
```
// H.264 Direct Play
ffmpeg -i input.mkv -c copy -f matroska output.mkv

// H.264 Transcode
ffmpeg -i input.mkv -c:v libx264 -preset medium -crf 23 -c:a aac -b:a 128k -f mpegts output.ts

// HLS Segment
ffmpeg -i input.ts -c copy -f segment -segment_time 6 -segment_list playlist.m3u8 segment_%03d.ts

// Subtitle Burn-in
ffmpeg -i input.mkv -vf "ass=subtitles.ass" -c:a copy output.mkv
```

### E. Glossary
- **Transcode**: Convert media from one format to another
- **Direct Play**: Stream original file without conversion
- **HLS**: HTTP Live Streaming protocol
- **DLNA**: Digital Living Network Alliance
- **Codec**: Compressor/decompressor for audio/video
- **Container**: File format holding encoded streams
- **Bitrate**: Data rate of encoded content
- **Ticks**: 100-nanosecond intervals (Plex/Jellyfin time unit)

---

**Document Version:** 1.0  
**Last Updated:** 2026-05-14  
**Status:** Draft for Review
