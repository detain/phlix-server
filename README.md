# Phlex Media Server

A comprehensive media server platform built with PHP 8.3+, featuring real-time WebSocket communication, HTTP REST APIs, and support for multiple client platforms including Roku, Samsung Tizen, and Windows.

## Overview

Phlex Media Server provides a complete media management and streaming solution:

- **Media Library Management**: Organize and browse media collections with automatic scanning
- **User Authentication**: JWT-based auth with refresh tokens
- **Real-time SyncPlay**: Watch content together with friends
- **Live TV Support**: DVR and guide integration
- **DLNA Streaming**: Standard protocol support for compatible devices
- **Transcoding**: On-the-fly media conversion via FFmpeg with automatic quality selection
- **HLS Streaming**: Adaptive bitrate streaming for web clients with multi-quality playlists
- **WebSocket Events**: Real-time progress and notification delivery
- **Multi-Source Metadata**: Automatic metadata fetching from TMDB (movies), TVDB (TV series), Fanart.tv (artwork), and local NFO files with 24-hour cache and provider fallback
- **Content Filtering**: Parental controls with rating and genre-based filtering

## Architecture

```
src/
├── Server/
│   ├── Core/           # Application bootstrap and core
│   ├── Http/            # HTTP REST API layer
│   │   ├── Controllers/ # Request handlers
│   │   ├── Request.php  # HTTP request representation
│   │   ├── Response.php # HTTP response builder
│   │   └── Router.php  # Route dispatching
│   ├── WebSocket/       # Real-time communication
│   │   ├── Connection.php      # Client connection wrapper
│   │   ├── ConnectionPool.php  # Connection management
│   │   ├── MessageHandler.php  # Event routing
│   │   ├── WebSocketServer.php # Server implementation
│   │   └── Events.php          # Event type constants
│   └── WebPortal/       # Web portal (HTML UI)
│       ├── WebPortalRouter.php # REST API for portal
│       └── PageRenderer.php    # Smarty template rendering
├── Session/            # Playback session management
├── Media/              # Media library and metadata
│   ├── Library/        # Library management (LibraryManager, ItemRepository, MediaScanner)
│   ├── Metadata/      # Metadata fetching (TMDB, TVDB, Fanart, NFO providers)
│   ├── Transcoding/    # FFmpeg transcoding with EncodingHelper
│   └── Streaming/      # HLS streaming with adaptive bitrate
├── Auth/               # Authentication services
└── Common/             # Shared utilities

public/
├── index.php           # Web portal entry point
├── templates/          # Smarty templates
└── assets/             # Static assets (css, js)
```

## Requirements

- **PHP**: 8.3 or higher
- **MySQL**: 8.0+ or MariaDB 10.6+
- **Workerman**: 5.0+ (bundled via Composer)
- **FFmpeg**: For transcoding (optional)

## Features

### Foundation
- **PSR-11 DI container (PHP-DI 7)**: auto-wired services with provider-based
  composition; see `docs/dev/architecture-server.md`
- **PSR-14 event dispatcher (Tukio)**: playback, library-scan, and auth
  lifecycle events with typed `readonly` DTOs. Plugins subscribe by event
  class FQCN; see `docs/dev/event-reference.md`.

### Web Portal
- **Smarty-based Templates**: Server-side rendered HTML pages using Smarty
- **REST API Endpoints**: Complete API for library browsing, media info, and user data
- **JWT Authentication**: Integrated token-based auth with refresh support
- **Responsive Design**: CSS-first approach with utility classes
- **JavaScript Client**: ApiClient helper with auth, library, and player helpers
- **Continue Watching**: Track and display in-progress media
- **Library Browser**: Browse media by library with item counts

### Authentication & Security
- **JWT-based Authentication**: Stateless auth with access tokens (1 hour TTL) and refresh tokens (7 days TTL)
- **Secure Password Hashing**: Argon2ID for password storage
- **Multi-Device Sessions**: Track and manage sessions across devices
- **User Profiles**: Multiple profiles per account with parental controls
  - Up to 5 profiles per user account
  - Profile-specific content rating restrictions (G, PG, PG-13, R, NC-17, X, UNRATED)
  - PIN protection (4 or 6 digits) for profile settings
  - Genre-based filtering (allowed/blocked genre lists)
  - Daily watch time limits per profile
- **Content Rating Filters**: Age-based access restrictions
- **Audit Logging**: Complete security event logging

### SyncPlay - Group Watching
- **Synchronized Playback**: Watch content together with friends across devices with sub-second sync accuracy
- **Host-Controlled Playback**: Only the host can control play/pause/seek; all members receive synchronized commands
- **NTP-Style Time Sync**: Network time synchronization with latency compensation and drift correction
- **In-Group Chat**: Real-time messaging with typing indicators and message history
- **Playback Queue**: Host-managed queue with media info (title, thumbnail)
- **Host Election**: Automatic host election when current host leaves (oldest member becomes host)
- **Password Protection**: Optional password protection for private watch parties
- **Position Tolerance**: Configurable sync tolerance (default 2s) to prevent excessive seeking

### Session Management
- **Device Sessions**: Track authenticated devices with activity timestamps
- **Playback Progress**: Resume where you left off across sessions
- **Continue Watching**: Track items in progress per profile
- **Watch History**: Complete viewing history per profile with:
  - Automatic completion detection at 90% progress threshold
  - Watch time statistics (total, daily, by period)
  - Resume position tracking for seamless playback continuation

### Live TV & DVR
- **Multi-Tuner Support**: DVB-T, DVB-S, DVB-C, and ATSC tuner types
- **Channel Scanning**: Automatic discovery of broadcast services
- **Electronic Program Guide**: Full EPG with program info, categories, and search
- **DVR Scheduling**: Schedule recordings with priority management
- **Time-Shifting**: Pause and rewind live TV with buffer
- **Channel Lineups**: Custom channel lineups per user
- **Favorites**: Personal favorite channels per user
- **Storage Management**: Recording storage tracking and limits

## Installation

```bash
# Clone the repository
git clone https://github.com/your-org/phlex.git
cd phlex

# Install dependencies
composer install

# Configure environment
cp .env.example .env
# Edit .env with your database and service credentials

# Run database migrations
php scripts/migrate.php

# Start the development server
php start.php server
```

## Configuration

Configuration is managed via PHP files in `config/`:

```php
// config/server.php
return [
    'server' => [
        'name' => 'Phlex Media Server',
        'host' => '0.0.0.0',
        'port' => 8080,
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8097,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'phlex',
        'username' => 'phlex',
        'password' => 'secure-password',
    ],
    'debug' => false,
];
```

## API Reference

### HTTP Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| GET | `/system/info` | Server information |
| POST | `/api/v1/auth/register` | User registration |
| POST | `/api/v1/auth/login` | User login |
| POST | `/api/v1/auth/refresh` | Token refresh |
| GET | `/api/v1/auth/me` | Current user profile |
| GET | `/api/v1/sessions` | List user sessions |
| DELETE | `/api/v1/sessions/{id}` | End a session |
| POST | `/api/v1/sessions/{id}/progress` | Report playback progress |
| GET | `/api/v1/sessions/{id}/progress` | Get playback state |

### WebSocket Events

**Connection Events:**
- `connected` - Sent on successful connection
- `client_disconnected` - Broadcast when client disconnects

**Authentication Events:**
- `auth_request` - Request authentication
- `auth_success` - Authentication successful
- `auth_failure` - Authentication failed

**Playback Events:**
- `playback_start` - Playback started
- `playback_pause` - Playback paused
- `playback_stop` - Playback stopped
- `playback_progress` - Progress update
- `playback_seek` - Seek performed

**SyncPlay Events:**
- `syncplay_create_group` - Create watch group
- `syncplay_join_group` - Join watch group
- `syncplay_leave_group` - Leave watch group
- `syncplay_sync_state` - State synchronization

## Development

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
```

### Code Standards

This project follows PSR-12 coding standards and uses static analysis tools:

```bash
# Check code style
./vendor/bin/phpcs --standard=PSR12 src/

# Run static analysis
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/psalm
```

### Git Workflow

1. Create a feature branch: `git checkout -b feature/my-feature`
2. Make changes and commit: `git commit -am 'Add new feature'`
3. Push to remote: `git push origin feature/my-feature`
4. Create Pull Request on GitHub
5. After review, merge via squash-merge

## Contributing

1. Fork the repository
2. Create your feature branch
3. Ensure all tests pass (`./vendor/bin/phpunit`)
4. Follow PSR-12 coding standards
5. Submit a pull request

## License

Proprietary - All rights reserved.

## Support

For issues and feature requests, please use the GitHub issue tracker.

---

For detailed development documentation, see [DEVELOPER.md](DEVELOPER.md).
