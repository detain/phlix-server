# Developer Documentation

This document provides detailed information for developers working on the Phlex Media Server project.

## Table of Contents

1. [Development Environment Setup](#development-environment-setup)
2. [Architecture Overview](#architecture-overview)
3. [Coding Standards](#coding-standards)
4. [Testing Guide](#testing-guide)
5. [Git Workflow](#git-workflow)

---

## Development Environment Setup

### Prerequisites

- PHP 8.3+ with extensions: `mysql`, `pdo`, `pdo_mysql`, `json`, `gd`, `zip`, `fileinfo`
- MySQL 8.0+ or MariaDB 10.6+
- Composer 2.x
- Git

### Local Setup

```bash
# Clone the repository
git clone https://github.com/your-org/phlex.git
cd phlex

# Install PHP dependencies
composer install

# Create a local configuration
cp config/server.php.example config/server.php
cp config/database.php.example config/database.php

# Edit configuration files with your local settings
vim config/server.php
vim config/database.php

# Run the test suite to verify setup
./vendor/bin/phpunit --testsuite Unit
```

### Starting the Server

```bash
# Start HTTP server (port 8080)
php start.php server

# Start WebSocket server (port 8097) in separate terminal
php start.php websocket

# Or start both together with Workerman
php start.php both
```

---

## Architecture Overview

### Request Lifecycle

```
Client Request
    ↓
Request::fromGlobals()
    ↓
Application::run() → Middleware Stack
    ↓
Router::dispatch()
    ↓
Route Handler (Controller)
    ↓
Response::send()
    ↓
Client Response
```

### Core Components

#### Request (`src/Server/Http/Request.php`)

Represents an HTTP request with:
- HTTP method, path, headers, query parameters
- Body parsing (JSON)
- Bearer token extraction
- Client IP detection (with proxy support)

```php
$request = Request::fromGlobals();
$userId = $request->userId;           // Set by auth middleware
$bearerToken = $request->bearerToken; // From Authorization header
$body = $request->body;               // JSON decoded body
$params = $request->pathParams;         // From route pattern
```

#### Response (`src/Server/Http/Response.php`)

Fluent HTTP response builder:

```php
(new Response())
    ->status(201)
    ->header('X-Custom', 'value')
    ->json(['user' => ['id' => 1]])
    ->send();
```

#### Router (`src/Server/Http/Router.php`)

Route registration and dispatch:

```php
$router->get('/users/{id}', [UserController::class, 'show']);
$router->post('/users', [UserController::class, 'create']);
$router->group('/api/v1', function($r) {
    $r->get('/items', handler);
}, [authMiddleware()]);
```

### WebSocket Architecture

```
Client connects → WebSocketServer::onConnect()
    ↓
Connection wrapper created → added to ConnectionPool
    ↓
Messages handled by MessageHandler
    ↓
Events dispatched to registered callbacks
    ↓
Broadcasts via sendToUser() / sendToSession() / broadcast()
```

### Connection Interface

All WebSocket connections implement `ConnectionInterface`:

```php
interface ConnectionInterface
{
    public function getId(): string;
    public function send(string|array $data): void;
    public function sendMessage(string $type, array $data = []): void;
    public function close(): void;
    public function getUserId(): ?string;
    public function setSessionId(?string $sessionId): void;
    // ... session data management methods
}
```

---

## Web Portal Architecture

The Web Portal provides an HTML-based interface for media browsing and playback using Smarty templates.

### Component Overview

```
public/
├── index.php              # Entry point, request routing, auth
├── templates/             # Smarty templates (future)
└── assets/               # Static assets
    ├── css/               # Stylesheets
    └── js/                # JavaScript (api-client, player, app)

src/Server/WebPortal/
├── WebPortalRouter.php    # REST API endpoints for portal
└── PageRenderer.php       # Smarty-based HTML page rendering
```

### Request Flow

```
Browser Request → public/index.php
    ↓
Check Authorization header for Bearer token
    ↓
[API routes /api/*] → WebPortalRouter → JSON response
[Page routes /*]  → PageRenderer → Smarty → HTML response
```

### WebPortalRouter (`WebPortalRouter`)

REST API endpoints for the web portal:

| Endpoint | Method | Description | Auth |
|----------|--------|-------------|------|
| `/api/v1/libraries` | GET | List all libraries with item counts | No |
| `/api/v1/libraries/{id}` | GET | Get library details | No |
| `/api/v1/libraries/{id}/items` | GET | Get items in library | No |
| `/api/v1/media/{id}` | GET | Get media item with streams | No |
| `/api/v1/media/{id}/playback` | GET | Get playback info | No |
| `/api/v1/users/me/continue-watching` | GET | User's continue watching list | Yes |
| `/api/v1/users/me/recently-watched` | GET | User's recently watched items | Yes |
| `/api/v1/users/me/settings` | GET/PUT | User settings | Yes |

```php
$router = new WebPortalRouter(
    $libraryManager,
    $itemRepository,
    $sessionManager,
    $playbackController,
    $authManager
);

// Dispatch request to appropriate handler
$response = $router->dispatch($request);
```

### PageRenderer (`PageRenderer`)

Renders Smarty templates to HTML:

```php
$renderer = new PageRenderer(
    '/path/to/templates',
    $libraryManager,
    $itemRepository,
    $playbackController
);

// Render pages
$response = $renderer->renderHome($request);
$response = $renderer->renderLibrary($request, ['id' => 'lib_123']);
$response = $renderer->renderLogin($request);
```

### Template Variables

**Home Page (`home/index.tpl`)**:
- `current_page`: string ('home')
- `user`: array (display_name, etc.)
- `libraries`: array (library data with items sub-array, max 3)
- `recently_added`: array (recently added media items)
- `continue_watching`: array (items in progress, if authenticated)

**Library Page (`library/index.tpl`)**:
- `current_page`: string ('library')
- `library`: array (library data)
- `items`: array (media items, max 100)

**Login Page (`auth/login.tpl`)**:
- Standard Smarty variables for auth form

### JavaScript Client

The web portal uses `api-client.js` for client-side API communication:

```javascript
// Initialize the API client
const api = new ApiClient();

// Authentication helpers
Auth.isLoggedIn();           // Check if logged in
Auth.getUser();              // Get stored user data
Auth.setUser(user);          // Store user data
Auth.logout();              // Clear auth and redirect

// Library browsing
Library.getItems();          // Get library items
Library.getItem(id);         // Get single item
Library.getContinueWatching(); // Get continue watching list

// Playback
Player.getPlaybackInfo(id);   // Get playback info
Player.reportProgress(...);  // Report playback progress
```

### Static Assets

| Path | Description |
|------|-------------|
| `public/assets/css/main.css` | Main stylesheet |
| `public/assets/css/components.css` | Component styles |
| `public/assets/css/player.css` | Player-specific styles |
| `public/assets/js/api-client.js` | API client with auth helpers |
| `public/assets/js/app.js` | Application logic |
| `public/assets/js/player.js` | Media player logic |

---

## Media Library Architecture

The Media Library system manages media files, metadata fetching, and streaming.

### Component Overview

```
src/Media/
├── Library/
│   ├── LibraryManager.php    # Library CRUD and scan coordination
│   ├── ItemRepository.php    # Media item data access with content filtering
│   ├── MediaScanner.php      # Filesystem scanning and parsing
│   └── FolderWatcher.php      # Directory change detection
├── Metadata/
│   ├── MetadataManager.php  # Provider coordination and refresh
│   ├── MetadataProviderInterface.php  # Provider contract
│   ├── MetadataHttpClient.php # HTTP client with caching
│   ├── TmdbProvider.php      # TMDB movie metadata
│   ├── TvdbProvider.php      # TVDB series metadata
│   ├── FanartProvider.php    # Fanart.tv artwork
│   └── LocalNfoProvider.php   # Local NFO file parsing
├── Transcoding/
│   ├── TranscodeManager.php   # Transcoding job management
│   ├── FfmpegRunner.php      # FFmpeg process execution
│   └── EncodingHelper.php    # Encoding profile definitions
└── Streaming/
    ├── StreamManager.php     # Stream session management
    ├── HlsStreamer.php       # HLS segment generation
    ├── StreamState.php       # Playback state tracking
    └── QualitySelector.php   # Quality profile selection
```

---

## Streaming Architecture

The Streaming system provides adaptive bitrate playback via HLS (HTTP Live Streaming) with quality selection based on device capabilities.

### Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| `StreamManager` | Session lifecycle, playback control, state persistence |
| `StreamState` | Playback state container (position, status, duration) |
| `HlsStreamer` | HLS playlist generation, segment management |
| `QualitySelector` | Device profile matching, codec compatibility |

### Streaming Flow

```
Client Request → StreamManager::createStream()
    ↓
QualitySelector::selectQuality() - Determines direct vs transcode
    ↓
[Direct Play] → Build direct stream URL
[Transcode] → Start TranscodeManager job
    ↓
StreamState persisted → Client receives playlist URL
```

### StreamManager (`StreamManager`)

The main entry point for playback session management:

```php
// Create a stream session
$state = $streamManager->createStream($mediaItemId, $sessionId, $userId, [
    'device_profile' => 'mobile-high',
]);

// Control playback
$streamManager->play($state->id);
$streamManager->pause($state->id);
$streamManager->seek($state->id, $positionTicks);
$streamManager->stop($state->id);

// Query state
$state = $streamManager->getStream($streamId);
$activeCount = $streamManager->getActiveStreamCount();
```

### StreamState (`StreamState`)

Encapsulates all playback state information:

```php
// State properties
$state->id;           // Unique stream identifier
$state->mediaItemId;  // Media item being played
$state->positionTicks; // Current position (100-ns ticks)
$state->durationTicks; // Total duration (100-ns ticks)
$state->status;       // 'stopped' | 'playing' | 'paused'
$state->playMethod;  // 'direct' | 'transcode'

// State transitions
$state->play();   // Start/resume playback
$state->pause(); // Pause playback
$state->stop();   // Stop playback
$state->seek(600000000); // Seek to 60 seconds

// Convenience methods
$seconds = $state->getPositionSeconds();
$progress = $state->getProgressPercent(); // 0-100
```

### HLS Streaming (`HlsStreamer`)

Generates HLS playlists and manages segment files:

```php
// Generate master playlist (adaptive bitrate)
$levels = [
    ['bandwidth' => 5000000, 'width' => 1920, 'height' => 1080, 'name' => '1080p'],
    ['bandwidth' => 2500000, 'width' => 1280, 'height' => 720, 'name' => '720p'],
];
$playlist = $hlsStreamer->generateMasterPlaylist($jobId, $levels);

// Generate variant playlist for quality level
$segments = [['duration' => 6.0], ['duration' => 6.0]];
$variant = $hlsStreamer->generateVariantPlaylist($jobId, 0, $segments, 6);

// Segment management
$hlsStreamer->segmentExists($jobId, 0, 5);  // Check exists
$hlsStreamer->getSegmentContent($jobId, 0, 5); // Get content
$hlsStreamer->getSegmentCount($jobId, 0);   // Count segments

// Cleanup
$hlsStreamer->cleanupJob($jobId);
```

### Quality Selection (`QualitySelector`)

Matches source capabilities with device profiles:

```php
$selector = new QualitySelector();

// Device profiles built-in:
// - generic: 4K max, all codecs
// - mobile-low: 480p max, H.264 only
// - mobile-high: 720p max, H.264/H.265
// - web: 1080p max, H.264/VP9
// - tv-4k: 4K max, all codecs

$sourceInfo = $ffmpegRunner->probe('/path/to/video.mkv');
$result = $selector->selectQuality($sourceInfo, 'web');

if ($result['method'] === 'direct') {
    // Stream without transcoding
    $container = $result['container'];
    $videoCodec = $result['video_codec'];
} else {
    // Transcode with these parameters
    $videoCodec = $result['video_codec'];    // e.g., 'libx264'
    $audioCodec = $result['audio_codec'];   // e.g., 'aac'
    $resolution = $result['max_resolution']; // e.g., [1920, 1080]
}

// Custom profiles
$selector->registerProfile('custom-tv', [
    'max_bitrate' => 20000000,
    'max_resolution' => [1920, 1080],
    'direct_play' => ['h264', 'h265'],
    'transcode' => ['h264'],
]);
```

---

## Transcoding Architecture

The Transcoding system converts media files to optimal formats for streaming using FFmpeg.

### Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| `TranscodeManager` | Job lifecycle, concurrency management, database persistence |
| `FfmpegRunner` | FFmpeg/FFprobe process execution, command building |
| `EncodingHelper` | Encoding parameter calculation based on source/profile |

### Transcoding Flow

```
TranscodeManager::startTranscode()
    ↓
Create output directory
    ↓
FFmpegRunner::probe() - Analyze source
    ↓
EncodingHelper::getEncodingParams() - Calculate optimal settings
    ↓
FFmpegRunner::transcode() - Execute FFmpeg
    ↓
Update database with status
    ↓
Return job ID for tracking
```

### TranscodeManager (`TranscodeManager`)

Manages transcoding jobs with concurrency limits:

```php
// Start a transcode job
$jobId = $transcodeManager->startTranscode($streamState, [
    'device_profile' => 'mobile-high',
]);

// Check status
$status = $transcodeManager->getTranscodeStatus($jobId);
// ['id' => $jobId, 'status' => 'running', 'output_path' => '...']

// Cancel a job
$transcodeManager->stopTranscode($jobId);

// Cleanup stale jobs (older than 1 hour)
$transcodeManager->cleanupStaleJobs(3600);

// Monitor concurrency
$activeCount = $transcodeManager->getActiveTranscodeCount();
```

### FfmpegRunner (`FfmpegRunner`)

Low-level FFmpeg process execution:

```php
$runner = new FfmpegRunner(
    '/usr/bin/ffmpeg',
    '/usr/bin/ffprobe',
    '/var/transcodes'
);

// Probe for source info
$info = $runner->probe('/path/to/video.mkv');
// ['streams' => [...], 'format' => [...]]

// Transcode
$success = $runner->transcode('/input.mkv', '/output.mp4', [
    'video_codec' => 'libx264',
    'audio_codec' => 'aac',
    'width' => 1920,
    'height' => 1080,
    'preset' => 'medium',
    'crf' => 23,
]);

// Build command without executing
$cmd = $runner->buildTranscodeCommand('/input.mkv', '/output.mp4', $params);

// Utilities
$runner->generateThumbnail('/video.mkv', '/thumb.jpg', 30);
$runner->extractSubtitle('/video.mkv', '/subs.srt', 0);
$runner->isAvailable();  // Check FFmpeg installed
$runner->getVersion();  // Get FFmpeg version
```

### EncodingHelper (`EncodingHelper`)

Calculates optimal encoding parameters:

```php
$helper = new EncodingHelper();

$params = $helper->getEncodingParams($sourceInfo, $profile);
// Returns encoding parameters for FFmpegRunner

// Direct play (no transcoding needed):
// ['method' => 'direct', 'video_codec' => 'h264', 'audio_codec' => 'aac']

// Transcode required:
/*
[
    'method' => 'transcode',
    'video_codec' => 'libx264',
    'audio_codec' => 'aac',
    'width' => 1920,
    'height' => 1080,
    'preset' => 'medium',
    'crf' => 23,
    'audio_bitrate' => '192k',
    'audio_channels' => 2,
    'audio_sample_rate' => 48000,
    'container' => 'ts',
    'format' => 'mpegts'
]
*/
```

### HLS Segment Generation

After transcoding, HLS segments are generated for adaptive streaming:

```php
// Complete flow
$jobId = $transcodeManager->startTranscode($streamState, $options);
$status = $transcodeManager->getTranscodeStatus($jobId);

// Generate HLS playlists
$hlsStreamer->savePlaylist($jobId, $hlsStreamer->generateMasterPlaylist($jobId, $levels), 'playlist.m3u8');
$hlsStreamer->savePlaylist($jobId, $hlsStreamer->generateVariantPlaylist($jobId, 0, $segments, 6), 'stream_0.m3u8');

// Serve to client
$playlistUrl = $hlsStreamer->getPlaylistUrl($jobId);       // Master playlist
$variantUrl = $hlsStreamer->getVariantPlaylistUrl($jobId, 0); // Variant
```

---

### Library Manager (`LibraryManager`)

The `LibraryManager` is the main interface for library operations:

```php
// Create a new library
$libraryId = $libraryManager->createLibrary('Movies', 'video', ['/mnt/media/movies']);

// Get library with decoded paths/options
$library = $libraryManager->getLibrary($libraryId);
// Returns: ['id' => '...', 'name' => 'Movies', 'type' => 'video', 'paths' => ['/mnt/media/movies'], 'options' => [...]]

// Update library properties
$libraryManager->updateLibrary($libraryId, ['name' => 'My Movies']);

// Scan library for new files
$libraryManager->scanLibrary($libraryId);

// Rescan (clear existing items first)
$libraryManager->rescanLibrary($libraryId);

// Delete library
$libraryManager->deleteLibrary($libraryId);
```

### Item Repository (`ItemRepository`)

The `ItemRepository` provides comprehensive data access for media items:

```php
// Basic queries
$item = $itemRepository->findById('item-123');
$item = $itemRepository->findByPath('/mnt/media/movie.mkv');
$children = $itemRepository->findByParent('parent-id');

// Filtering
$movies = $itemRepository->getByType($libraryId, 'movie', 100, 0);
$recent = $itemRepository->getRecentlyAdded($libraryId, 20);

// Searching
$results = $itemRepository->search('action movie');  // Full-text
$fuzzy = $itemRepository->searchFuzzy('action');       // LIKE pattern

// Content rating filtering
$safe = $itemRepository->getByMaxRating($libraryId, 'PG-13');

// Genre filtering
$action = $itemRepository->getByAllowedGenres($libraryId, ['Action', 'Adventure']);
$noKids = $itemRepository->getExcludingGenres($libraryId, ['Horror']);

// Streaming
$streams = $itemRepository->getItemStreams('item-123');
$itemRepository->addStream('item-123', ['stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264']);
```

### Media Scanner (`MediaScanner`)

The `MediaScanner` discovers files from filesystem directories:

```php
$scanner->scan($libraryId, '/mnt/media/movies', 'video');
```

Supported file extensions by type:
- **video**: mkv, mp4, avi, mov, wmv, flv, webm, m4v, mpg, mpeg, ts
- **audio**: mp3, flac, aac, ogg, wav, m4a, wma, alac, opus
- **image**: jpg, jpeg, png, gif, bmp, webp, tiff, tif

Naming convention parsing:
- **Movies**: `Movie Name (2024)` or `Movie.Name.2024`
- **Series**: `Series S01E01` or `Series - S01E01 - Episode Title`

### Folder Watcher (`FolderWatcher`)

The `FolderWatcher` detects filesystem changes via checksum comparison:

```php
// Start watching paths
$watcher->watch($libraryId, ['/mnt/media/movies']);

// Check for changes (call periodically)
$changes = $watcher->checkForChanges();
foreach ($changes as $change) {
    echo "Change in {$change['path']} for library {$change['library_id']}";
}

// Stop watching
$watcher->unwatch($libraryId);
```

---

## LiveTV Architecture

The LiveTV system provides DVR (Digital Video Recorder) and live TV streaming capabilities with support for multiple tuner types, electronic program guides, and time-shifting.

### Component Overview

```
src/LiveTv/
├── LiveTvManager.php   # Main orchestrator for tuner discovery, scanning, tuning
├── ChannelManager.php # Channel CRUD, lineups, favorites
├── GuideManager.php   # Electronic Program Guide (EPG) data management
└── Recorder.php       # DVR scheduling, recording, time-shifting
```

### Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| `LiveTvManager` | Tuner discovery, channel scanning, channel tuning, orchestration |
| `ChannelManager` | Channel lifecycle, lineups, favorites, visibility management |
| `GuideManager` | EPG data, program info, guide caching, import/export |
| `Recorder` | Recording lifecycle, storage management, time-shifting |

### Tuner Types

The system supports multiple DVB tuner standards:

| Type | Constant | Description |
|------|----------|-------------|
| DVB-Terrestrial | `TUNER_TYPE_DVB_T` | Free-to-air terrestrial (EU, AU) |
| DVB-Satellite | `TUNER_TYPE_DVB_S` | Satellite dishes |
| DVB-Cable | `TUNER_TYPE_DVB_C` | Cable TV |
| ATSC | `TUNER_TYPE_ATSC` | North American digital TV |

### Tuner Status Flow

```
IDLE → SCANNING → IDLE
       ↓
     TUNING → STREAMING → IDLE
                  ↓
               ERROR → IDLE
```

### LiveTvManager (`LiveTvManager`)

The main entry point for LiveTV operations:

```php
// Discover available tuners on the system
$tuners = $liveTvManager->discoverTuners();

// Scan for channels using a specific tuner
$channels = $liveTvManager->scanChannels('dvb_0', [
    'frequencies' => [474000000, 498000000, 522000000]
]);

// Tune to a channel and get stream URL
$result = $liveTvManager->tuneToChannel('channel_123');
// ['id' => 'tune_req_abc', 'channel_id' => 'channel_123', 'stream_url' => '/livetv/abc/stream']

// Stop tuning and release tuner
$liveTvManager->stopTuning($result['id']);

// Get the ChannelManager for channel operations
$channelManager = $liveTvManager->getChannelManager();

// Get the GuideManager for EPG operations
$guideManager = $liveTvManager->getGuideManager();

// Get the Recorder for DVR operations
$recorder = $liveTvManager->getRecorder();
```

### ChannelManager (`ChannelManager`)

Manages channel data and user preferences:

```php
// Create a channel
$channel = $channelManager->createChannel([
    'name' => 'BBC One',
    'number' => 1,
    'type' => ChannelManager::TYPE_TV,
    'frequency' => 474000000,
]);

// Get all visible channels
$channels = $channelManager->getAllChannels('number', 'ASC');

// Get channels by type (TV, radio, data)
$radioChannels = $channelManager->getChannelsByType(ChannelManager::TYPE_RADIO);

// Update a channel
$channelManager->updateChannel($channelId, ['name' => 'BBC One HD']);

// Hide/delete a channel
$channelManager->hideChannel($channelId);
$channelManager->deleteChannel($channelId);

// Restore a hidden/deleted channel
$channelManager->restoreChannel($channelId);

// Manage favorites
$channelManager->addToFavorites($channelId, $userId);
$favorites = $channelManager->getFavoriteChannels($userId);
$isFav = $channelManager->isFavorite($channelId, $userId);

// Manage lineups
$lineup = $channelManager->createLineup('My TV', $userId, [$ch1, $ch2]);
$channelManager->addChannelToLineup($lineupId, $channelId, $position);
$channels = $channelManager->getLineupChannels($lineupId);
```

### GuideManager (`GuideManager`)

Electronic Program Guide (EPG) functionality:

```php
// Get current program on a channel
$current = $guideManager->getCurrentProgram($channelId);

// Get programs for a time range
$programs = $guideManager->getProgramsForChannel($channelId, time(), time() + 86400);

// Get guide for next 7 days
$guide = $guideManager->getChannelGuide($channelId, 7);

// Search programs
$results = $guideManager->searchPrograms('News');

// Get programs by category
$sports = $guideManager->getProgramsByCategory(GuideManager::CATEGORY_SPORTS);

// Get upcoming episodes for a series
$episodes = $guideManager->getUpcomingBySeries($seriesId);

// Add/update a program
$guideManager->upsertProgram([
    'channel_id' => 'ch_1',
    'title' => ' Evening News',
    'start_time' => strtotime('today 6pm'),
    'end_time' => strtotime('today 6:30pm'),
    'category' => GuideManager::CATEGORY_NEWS,
]);

// Import guide data from external source
$result = $guideManager->importGuideData($externalData);
// ['imported' => 150, 'errors' => []]

// Clean up old programs (retention policy)
$deleted = $guideManager->cleanupOldPrograms(7); // Keep 7 days

// Cache management
$guideManager->setCacheTtl(3600);
$stats = $guideManager->getCacheStats();
// ['entries' => 50, 'ttl' => 3600]
```

### Recorder (`Recorder`)

DVR recording and time-shifting:

```php
// Schedule a recording
$recording = $recorder->scheduleRecording([
    'channel_id' => 'ch_1',
    'program_id' => $programId,
    'title' => 'My Show',
    'start_time' => strtotime('today 8pm'),
    'end_time' => strtotime('today 9pm'),
    'priority' => Recorder::PRIORITY_NORMAL,
]);

// Get recordings
$all = $recorder->getAllRecordings();
$upcoming = $recorder->getUpcomingRecordings(10);
$byChannel = $recorder->getRecordingsForChannel($channelId);
$userRecordings = $recorder->getUserRecordings($userId);

// Recording lifecycle
$recorder->startRecording($recordingId);
$recorder->stopRecording($recordingId);
$recorder->cancelRecording($recordingId);
$recorder->deleteRecording($recordingId);

// Update priority
$recorder->updatePriority($recordingId, Recorder::PRIORITY_HIGH);

// Time-shifting (pause/rewind live TV)
$timeShift = $recorder->startTimeShift($sessionId, $channelId);
// ['time_shift_id' => 'ts_abc', 'stream_url' => '/livetv/timeshift/abc/stream', 'buffer_start' => ..., 'buffer_end' => ...]

$position = $recorder->getTimeShiftPosition($sessionId);
$recorder->seekTimeShift($sessionId, time() - 300); // Seek 5 minutes back
$recorder->stopTimeShift($sessionId);

// Storage management
$stats = $recorder->getStorageStats();
// ['used_bytes' => 5368709120, 'available_bytes' => 10737418240, 'max_bytes' => 17179869184, ...]
$used = $recorder->getUsedStorageBytes();
$available = $recorder->getAvailableStorageBytes();
```

### EPG Data Structure

Program data stored in `livetv_programs` table:

```json
{
    "program_id": "prog_abc123",
    "channel_id": "ch_1",
    "title": "Evening News",
    "description": "Latest news coverage...",
    "start_time": 1700000000,
    "end_time": 1700001800,
    "duration": 1800,
    "category": "news",
    "series_id": null,
    "episode_number": null,
    "episode_title": null,
    "rating_system": "tv",
    "rating": "TV-G",
    "year": null,
    "series_episode": null,
    "is_repeat": false,
    "is_film": false
}
```

---

## Metadata Fetching System

### Metadata Manager (`MetadataManager`)

The `MetadataManager` coordinates metadata fetching from multiple providers:

```php
// Register providers
$manager->registerProvider('tmdb', new TmdbProvider($apiKey), ['movie']);
$manager->registerProvider('tvdb', new TvdbProvider($apiKey), ['series', 'episode']);
$manager->registerProvider('fanart', new FanartProvider($apiKey), ['series']);
$manager->registerProvider('local', new LocalNfoProvider('/mnt/media'), ['movie', 'series', 'episode']);

// Set priority (optional)
$manager->setProviderPriority('movie', ['local', 'tmdb', 'fanart']);

// Refresh single item
$success = $manager->refreshItemMetadata('item-123', force: false);

// Refresh entire library with progress
$refreshed = $manager->refreshLibraryMetadata($libraryId, function($current, $total) {
    echo "Progress: $current/$total\n";
});
```

### Provider Priority

Default provider priority by media type:
- **movie**: `['tmdb', 'local']`
- **series**: `['tvdb', 'fanart', 'local']`
- **episode**: `['tvdb', 'local']`
- **artist**: `['musicbrainz', 'local']`
- **album**: `['musicbrainz', 'local']`

### Metadata Provider Interface

All providers implement `MetadataProviderInterface`:

```php
interface MetadataProviderInterface
{
    // Search for media by query
    public function search(string $query, array $options = []): array;

    // Get detailed metadata
    public function getDetails(string $externalId, array $options = []): array;

    // Get images (posters, backdrops, etc.)
    public function getImages(string $externalId): array;

    // Get provider name aliases
    public function getProviders(): array;
}
```

### TMDB Provider (`TmdbProvider`)

Movie metadata from The Movie Database:

```php
$provider = new TmdbProvider($apiKey);

// Search movies
$results = $provider->search('The Matrix', ['language' => 'en-US']);

// Get details
$details = $provider->getDetails('603'); // TMDB movie ID
// Returns: name, overview, year, genres, actors, director, runtime_ticks, etc.

// Get images
$images = $provider->getImages('603');
// Returns: ['posters' => [...], 'backdrops' => [...], 'logos' => [...]]
```

### TVDB Provider (`TvdbProvider`)

TV series metadata from TheTVDB:

```php
$provider = new TvdbProvider($apiKey, 'eng');

// Search series
$results = $provider->search('Breaking Bad');

// Get series details
$details = $provider->getDetails('81179'); // TVDB series ID
// Returns: name, overview, year, genres, actors, episodes, season_count, etc.

// Get episode
$episode = $provider->getEpisode('81179', 5, 16); // series ID, season, episode
```

### Fanart Provider (`FanartProvider`)

Artwork from Fanart.tv:

```php
$provider = new FanartProvider($apiKey);

// Get TV show artwork
$images = $provider->getTvShowImages($tvdbId);
// Returns: ['posters' => [...], 'banners' => [...], 'season_posters' => [...], ...]

// Get movie artwork
$images = $provider->getMovieImages($imdbId);
```

### Local NFO Provider (`LocalNfoProvider`)

Local NFO file parser (XBMC/Kodi format):

```php
$provider = new LocalNfoProvider('/mnt/media');

// Parse movie NFO
$details = $provider->parseMovieNfo('/mnt/media/movie.nfo');

// Parse TV show NFO
$details = $provider->parseTvShowNfo('/mnt/media/tvshow.nfo');

// Auto-detect and parse directory
$result = $provider->parseDirectory('/mnt/media/MovieName');
// Returns: ['type' => 'movie'|'tvshow', 'metadata' => [...]]
```

### Metadata HTTP Client (`MetadataHttpClient`)

Shared HTTP client with caching for all metadata providers:

```php
$client = new MetadataHttpClient(
    'https://api.themoviedb.org/3',
    'your-api-key',
    10  // timeout in seconds
);

// GET request with automatic caching
$response = $client->get('/search/movie', ['query' => 'The Matrix']);

// Clear cache when needed
$client->clearCache();
```

### Provider Interface Contract

All metadata providers implement `MetadataProviderInterface`:

```php
interface MetadataProviderInterface
{
    // Search for media by query string
    // Returns: array<int, array{id: string, title: string, overview?: string, ...}>
    public function search(string $query, array $options = []): array;

    // Get detailed metadata for a specific external ID
    // Returns: array<string, mixed> with name, overview, year, genres, actors, etc.
    public function getDetails(string $externalId, array $options = []): array;

    // Get images (posters, backdrops, banners) for an external ID
    // Returns: array<string, array<int, array{url: string, width?: int, height?: int}>>
    public function getImages(string $externalId): array;

    // Get the provider names this implementation handles
    // Returns: array<string> e.g., ['tmdb'] or ['tvdb', 'thetvdb']
    public function getProviders(): array;
}
```

### Response Format Specifications

#### Search Response Format
```php
[
    'id' => '603',                    // Provider-specific ID (string)
    'title' => 'The Matrix',          // Primary title
    'original_title' => 'The Matrix', // Original language title
    'overview' => 'A computer...',   // Description/synopsis
    'poster_path' => '/path/to.jpg', // Poster image path (may be null)
    'backdrop_path' => '/path/to.jpg', // Backdrop image path (movies only)
    'first_aired' => '1999-03-31',   // Release date (YYYY-MM-DD)
    'vote_average' => 8.2,          // Average rating (TMDB)
    'vote_count' => 12000,          // Number of votes
]
```

#### Details Response Format
```php
[
    'name' => 'The Matrix',                    // Display name
    'original_name' => 'The Matrix',           // Original title
    'overview' => 'A computer hacker...',     // Full description
    'year' => 1999,                           // Release year (int)
    'first_aired' => '1999-03-31',           // Release date
    'premiered' => '1999-03-31',             // Premier date
    'rating' => 8.7,                         // Content rating (TVDB)
    'official_rating' => 'R',               // MPAA rating
    'vote_average' => 8.2,                   // TMDB vote average
    'vote_count' => 12000,                  // Vote count
    'runtime' => 136,                       // Runtime in minutes
    'runtime_ticks' => 816000000000,        // Runtime in 100-nanosecond ticks
    'mpaa' => 'R',                           // MPAA rating (movies)
    'tagline' => 'The fight for the future', // Movie tagline
    'status' => 'Ended',                     // Series status (TV shows)
    'episode_run_time' => 45,               // Episode runtime (TV shows)
    'genre' => ['Action', 'Sci-Fi'],        // Genres (TVDB pipe-separated parsed)
    'genres' => ['Action', 'Sci-Fi'],        // Genres (TMDB array format)
    'genre' => ['Action', 'Sci-Fi'],        // Genres (array)
    'studio' => 'Warner Bros.',            // Production studio
    'studios' => ['Warner Bros.'],          // Studios array
    'budget' => 63000000,                   // Movie budget (TMDB)
    'revenue' => 466000000,                // Movie revenue (TMDB)
    'imdb_id' => 'tt0133093',              // IMDB ID
    'tmdb_id' => 603,                       // TMDB ID
    'tvdb_id' => 81179,                    // TVDB ID
    'actors' => [                           // Cast members
        ['name' => 'Keanu Reeves', 'role' => 'Neo', 'order' => 0],
        ['name' => 'Laurence Fishburne', 'role' => 'Morpheus', 'order' => 1],
    ],
    'director' => 'The Wachowskis',        // Director name (TMDB)
    'directors' => ['The Wachowskis'],     // Directors array (NFO)
    'credits' => ['The Wachowskis'],       // Writers (NFO)
    'episodes' => [                        // TV show episodes
        ['id' => '1234', 'name' => 'Pilot', 'season_number' => 1, 'episode_number' => 1, ...]
    ],
    'episode_count' => 100,               // Total episode count
    'season_count' => 4,                  // Season count
    'external_ids' => [                    // External provider IDs
        'tmdb' => '603',
        'imdb' => 'tt0133093',
    ],
]
```

#### Images Response Format
```php
[
    'posters' => [                          // Poster images
        [
            'url' => 'https://image.tmdb.org/t/p/w500/abc.jpg',
            'url_original' => 'https://image.tmdb.org/t/p/original/abc.jpg',
            'width' => 1000,
            'height' => 1500,
            'language' => 'en',             // ISO 639-1 language code (may be null)
            'rating' => 5.2,               // Fanart.tv rating (may be null)
            'likes' => 100,                // Fanart.tv likes count
            'type' => 'poster',            // Image type
            'filename' => 'poster.jpg',    // Local NFO filename (local provider only)
        ]
    ],
    'backdrops' => [...],                  // Background images
    'logos' => [...],                      // Logo/clear art images
    'banners' => [...],                    // Banner images (TVDB, Fanart.tv)
    'thumbs' => [...],                     // Thumbnail images
    'season_posters' => [...],            // Season-specific posters
    'season_thumbs' => [...],             // Season thumbnails
    'hd_logos' => [...],                  // HD logo overlays (Fanart.tv)
    'clear_arts' => [...],                // Clear art overlays (Fanart.tv)
]
```

### Caching Strategy

Metadata providers use multiple caching layers:

| Layer | Duration | Purpose |
|-------|----------|---------|
| In-memory | Request lifecycle | Avoid duplicate API calls within same request |
| HTTP Client cache | Request lifecycle | MetadataHttpClient instance caching |
| Database | 24 hours (configurable) | Persistent metadata storage per item |

**Cache Invalidation:**
- Force refresh: `$manager->refreshItemMetadata($itemId, force: true)`
- Direct database update via `metadata_json` column
- Library rescan clears and rebuilds metadata

### Error Handling

All providers return empty arrays on failure:
```php
// These all return [] on error
$provider->search('query');      // Network failure, API error
$provider->getDetails('123');    // Invalid ID, API error
$provider->getImages('123');      // No images found, API error
```

The MetadataManager implements cascading fallback:
```php
// If TMDB fails, try next provider in priority
$manager->setProviderPriority('movie', ['tmdb', 'local']);
$success = $manager->refreshItemMetadata($itemId);
// Tries TMDB first, falls back to local NFO if TMDB fails
```

### Metadata Structure

Metadata is stored as JSON in the `metadata_json` column:

```json
{
    "name": "Movie Title",
    "year": 2024,
    "rating": "PG-13",
    "genres": ["Action", "Adventure"],
    "external_ids": {
        "tmdb": "12345",
        "imdb": "tt1234567"
    },
    "details": {
        "tmdb": { "overview": "...", "runtime_ticks": 720000000000 },
        "local": { "plot": "..." }
    },
    "images": {
        "tmdb": { "posters": [...], "backdrops": [...] },
        "fanart": { "banners": [...], "logos": [...] }
    },
    "metadata_refreshed_at": "2024-01-15T10:30:00+00:00",
    "metadata_provider": "tmdb"
}
```

---

## Authentication Architecture

The Phlex Media Server uses JWT-based authentication with refresh tokens for secure stateless authentication across multiple devices.

### Component Overview

| Component | Responsibility |
|-----------|---------------|
| `AuthManager` | Orchestrates authentication workflows (register, login, token refresh) |
| `JwtHandler` | Creates and validates JWT access and refresh tokens |
| `UserRepository` | User data access with password hashing and verification |
| `UserProfileManager` | Multi-profile support per account with parental controls |
| `WatchHistory` | Per-profile watch history and progress tracking |

### Authentication Flow

```
User Registration:
    Client → POST /api/v1/auth/register
        → AuthManager::register()
            → UserRepository::create() [Argon2ID password hashing]
            → JwtHandler::createAccessToken()
            → JwtHandler::createRefreshToken()
        ← { access_token, refresh_token, user }

User Login:
    Client → POST /api/v1/auth/login
        → AuthManager::login()
            → UserRepository::verifyPassword()
            → AuditLogger::logLogin()
            → JwtHandler::createAccessToken()
            → JwtHandler::createRefreshToken()
        ← { access_token, refresh_token, user }

Token Refresh:
    Client → POST /api/v1/auth/refresh
        → AuthManager::refreshToken()
            → JwtHandler::isRefreshToken()
            → JwtHandler::validateToken()
            → JwtHandler::createAccessToken()
            → JwtHandler::createRefreshToken()
        ← { access_token, refresh_token, user }

API Request (with access token):
    Client → GET /api/v1/resource [Authorization: Bearer <token>]
        → JwtHandler::validateToken()
        → Extract user_id from payload
        → Return protected resource
```

### JWT Token Structure

**Access Token Claims:**
```json
{
    "iss": "phlex",
    "sub": "user-uuid-123",
    "iat": 1700000000,
    "exp": 1700003600,
    "type": "access"
}
```

**Refresh Token Claims:**
```json
{
    "iss": "phlex",
    "sub": "user-uuid-123",
    "iat": 1700000000,
    "exp": 1700600000,
    "type": "refresh",
    "jti": "unique-token-id-for-revocation"
}
```

### AuthManager (`AuthManager`)

Main entry point for authentication operations:

```php
// Register new user
$result = $authManager->register('username', 'email@example.com', 'password123');

// Login
$result = $authManager->login('username', 'password123', 'device-uuid');

// Refresh tokens
$result = $authManager->refreshToken($refreshToken);

// Validate access token
$info = $authManager->validateAccessToken($accessToken);
// Returns: ['user_id' => '...', 'expires_at' => ...]
```

### JwtHandler (`JwtHandler`)

Handles JWT token creation and validation:

```php
$handler = new JwtHandler(
    'your-256-bit-secret',
    'HS256',
    3600,      // Access token TTL: 1 hour
    604800     // Refresh token TTL: 7 days
);

// Create tokens
$accessToken = $handler->createAccessToken('user-123');
$refreshToken = $handler->createRefreshToken('user-123');

// Validate token
$payload = $handler->validateToken($token);
if ($payload) {
    $userId = $payload['sub'];
}

// Check token type
$handler->isAccessToken($token);   // true for access tokens
$handler->isRefreshToken($token);  // true for refresh tokens
```

### UserProfileManager (`UserProfileManager`)

Manages multiple profiles per account with comprehensive parental controls:

**Profile Types:**
- Standard Profile: Regular user profile with customizable content restrictions
- Admin Profile: Profile with elevated permissions for account management

**Parental Control Ratings:**
The system supports MPAA content ratings in order of restrictiveness:
| Rating | Level | Description |
|--------|-------|-------------|
| G | 1 | General Audiences - all ages |
| PG | 2 | Parental Guidance Suggested |
| PG-13 | 3 | Parents Strongly Cautioned - ages 13+ |
| R | 4 | Restricted - ages 17+ unless accompanied |
| NC-17 | 5 | No One 17 & Under Admitted |
| X | 6 | Adult content restriction |
| UNRATED | 7 | Content without rating (controlled by allow_unrated flag) |

**Profile Settings:**
- content_rating: Maximum allowed content rating
- pin: 4 or 6 digit PIN for parental control verification
- pin_required_for_admin: Require PIN for admin actions
- max_daily_watch_time: Daily watch time limit (0 = unlimited)
- allowed_genres: Whitelist of permitted genre tags
- blocked_genres: Blacklist of prohibited genre tags
- allow_unrated: Whether to allow unrated content

```php
// Create a profile with parental controls
$profileId = $profileManager->create('user-123', [
    'name' => 'Kids Profile',
    'content_rating' => 'G',
    'pin' => '1234',
    'pin_required_for_admin' => true,
    'allowed_genres' => ['Animation', 'Family', 'Children'],
    'blocked_genres' => ['Horror', 'Violence'],
    'allow_unrated' => false,
    'max_daily_watch_time' => 7200, // 2 hours
]);

// Find profile with all settings
$profile = $profileManager->findByIdWithSettings($profileId);

// Get all profiles for a user
$profiles = $profileManager->findByUserId('user-123');
$active = $profileManager->getActiveProfile('user-123');

// Check content rating access
$allowed = $profileManager->isContentRatingAllowed($profileId, 'PG-13');
// Returns: false for PG-13 when profile only allows G, PG

// Get all allowed ratings for a profile
$ratings = $profileManager->getAllowedRatings($profileId);
// Returns: ['G', 'PG'] for a PG-13 profile

// Verify profile PIN
if ($profileManager->verifyPin($profileId, '1234')) {
    // PIN verified - allow access to restricted content
}

// Switch active profile
$profileManager->switchProfile('user-123', 'profile-456');

// Delete a profile
$profileManager->delete('profile-456');
```

### WatchHistory (`WatchHistory`)

Tracks watch history and playback progress per profile with automatic completion detection:

**Tick Format:**
All position/duration values use 100-nanosecond tick intervals (standard media format):
- 1 tick = 100 nanoseconds
- 1 millisecond = 10,000 ticks
- 1 second = 10,000,000 ticks
- 1 hour = 36,000,000,000 ticks

**Playback Statuses:**
| Status | Description |
|--------|-------------|
| playing | Media is actively playing |
| paused | Playback is paused |
| stopped | Playback was stopped |
| completed | Reached 90% threshold or manually marked |

```php
// Update watch progress (position in ticks)
$history = $watchHistory->updateProgress(
    'profile-123',
    'media-item-456',
    12000000000,  // 20 minutes in ticks (20 * 60 * 10000000)
    36000000000,  // 60 minutes in ticks
    WatchHistory::STATUS_PLAYING
);
// Returns entry with: id, position_ticks, duration_ticks, progress_percent, etc.

// Get continue watching (items in progress < 90%)
$continueWatching = $watchHistory->getContinueWatching('profile-123', 10);

// Get recently completed items
$completed = $watchHistory->getRecentlyCompleted('profile-123', 20);

// Check if media has been completed
if ($watchHistory->hasWatched('profile-123', 'media-item-456')) {
    // Already watched
}

// Get resume position (null if completed)
$resumePosition = $watchHistory->getResumePosition('profile-123', 'media-item-456');
// Returns: 12000000000 (ticks) or null

// Get watch time statistics
$totalSeconds = $watchHistory->getTotalWatchTime('profile-123'); // All time
$todaySeconds = $watchHistory->getTodayWatchTime('profile-123'); // Today only
$byDay = $watchHistory->getWatchTimeByDay('profile-123', 30); // Last 30 days
// Returns: ['2024-01-15' => 3600, '2024-01-14' => 7200, ...] (seconds per day)

// Clear history
$watchHistory->clearHistory('profile-123');
$watchHistory->removeFromHistory('profile-123', 'media-item-456');
```

---

## User Management Architecture

The User Management system provides per-profile settings, parental controls, and watch history tracking.

### Database Schema

**user_profiles table:**
```sql
CREATE TABLE user_profiles (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    avatar_url VARCHAR(500),
    is_active BOOLEAN DEFAULT FALSE,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
);
```

**profile_settings table:**
```sql
CREATE TABLE profile_settings (
    id VARCHAR(36) PRIMARY KEY,
    profile_id VARCHAR(36) NOT NULL UNIQUE,
    content_rating ENUM('G','PG','PG-13','R','NC-17','X','UNRATED') DEFAULT 'R',
    pin_hash VARCHAR(255),
    pin_required_for_admin BOOLEAN DEFAULT FALSE,
    max_daily_watch_time INT DEFAULT 0,
    allowed_genres JSON,
    blocked_genres JSON,
    allow_unrated BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (profile_id) REFERENCES user_profiles(id) ON DELETE CASCADE
);
```

**watch_history table:**
```sql
CREATE TABLE watch_history (
    id VARCHAR(36) PRIMARY KEY,
    profile_id VARCHAR(36) NOT NULL,
    media_item_id VARCHAR(36) NOT NULL,
    position_ticks BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    playback_status ENUM('playing','paused','stopped','completed') DEFAULT 'stopped',
    progress_percent DECIMAL(5,2) DEFAULT 0.00,
    last_watched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    UNIQUE KEY unique_profile_media (profile_id, media_item_id),
    INDEX idx_profile_status (profile_id, playback_status),
    FOREIGN KEY (profile_id) REFERENCES user_profiles(id) ON DELETE CASCADE
);
```

### Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| `UserProfileManager` | Profile CRUD, parental controls, PIN verification |
| `WatchHistory` | Progress tracking, completion detection, watch time analytics |

### Profile Flow

```
Profile Creation:
    AuthManager::register() → Creates default profile
        OR
    UserProfileManager::create() → Creates additional profile
        → Creates profile_settings row
        → Returns profile ID

Profile Switch:
    UserProfileManager::switchProfile()
        → Deactivates all user profiles
        → Activates target profile
        → Updates session context

Content Access Check:
    Client requests media
        → UserProfileManager::isContentRatingAllowed()
        → WatchHistory::hasWatched() (optional)
        → Return allowed/disallowed

Progress Update:
    PlaybackController::reportProgress()
        → WatchHistory::updateProgress()
        → Auto-completion at 90% threshold
        → Updates last_watched_at, completed_at
```

### Tick Conversion Reference

```php
// Common tick conversions for playback
$one_second_ticks = 10000000;
$one_minute_ticks = 600000000;
$one_hour_ticks = 36000000000;

// Convert ticks to seconds
$seconds = (int)($ticks / 10000000);

// Convert seconds to ticks
$ticks = $seconds * 10000000;

// Convert position to progress percentage
$progress = round(($position_ticks / $duration_ticks) * 100, 2);
```

---

## Session Management Architecture

Tracks watch history per profile:

```php
// Update progress
$history = $watchHistory->updateProgress(
    'profile-123',
    'media-item-456',
    12000000000,  // position in ticks
    36000000000,  // duration in ticks
    WatchHistory::STATUS_PLAYING
);

// Get continue watching
$continueWatching = $watchHistory->getContinueWatching('profile-123', 10);

// Get recently completed
$completed = $watchHistory->getRecentlyCompleted('profile-123', 20);

// Check if watched
if ($watchHistory->hasWatched('profile-123', 'media-item-456')) {
    // Already watched
}

// Get resume position
$resumePosition = $watchHistory->getResumePosition('profile-123', 'media-item-456');
```

---

## Session Management Architecture

The Session system tracks device sessions and playback state across the application.

### Component Overview

| Component | Responsibility |
|-----------|----------------|
| `SessionManager` | Device session lifecycle (create, track, cleanup) |
| `PlaybackController` | Playback state persistence and progress tracking |
| `SyncPlayManager` | Group watching coordination and state sync |
| `GroupState` | Individual SyncPlay group state management |
| `TimeSync` | NTP-style time synchronization for playback sync |
| `Messages` | WebSocket message type definitions for SyncPlay |

### Session Flow

```
Device Login:
    → SessionManager::createSession(userId, deviceId, deviceName, deviceType)
    ← Returns sessionId

Progress Reporting:
    → PlaybackController::reportProgress(sessionId, mediaItemId, position, duration, isPaused)
    → Also updates session activity via SessionManager::updateActivity()

Get Continue Watching:
    → PlaybackController::getContinueWatching(userId, limit)
    ← Returns items in progress (playing/paused, <95% complete)
```

### SessionManager (`SessionManager`)

Manages device sessions and user authentication sessions:

```php
// Create a session
$sessionId = $sessionManager->createSession(
    'user-123',
    'device-uuid',
    'iPhone 15 Pro',
    'mobile'
);

// Get all user sessions
$sessions = $sessionManager->getUserSessions('user-123');

// Update activity
$sessionManager->updateActivity($sessionId);

// End a session
$sessionManager->endSession($sessionId);

// End all sessions except current
$sessionManager->endAllUserSessions('user-123', $currentSessionId);

// Cleanup stale sessions (default 24 hours)
$cleaned = $sessionManager->cleanupStaleSessions(86400);

// Get online users (active within 5 minutes)
$onlineUsers = $sessionManager->getOnlineUsers();
```

### PlaybackController (`PlaybackController`)

Manages playback state and progress tracking:

```php
// Report playback progress
$controller->reportProgress(
    'session-123',
    'media-456',
    12000000000,  // position in ticks
    36000000000,  // duration in ticks
    false         // not paused
);

// Get playback state
$state = $controller->getPlaybackState('session-123');

// Get user's progress on a specific item
$progress = $controller->getUserProgress('user-123', 'media-456');

// Get continue watching list
$continueWatching = $controller->getContinueWatching('user-123', 10);

// Get recently watched
$recentlyWatched = $controller->getRecentlyWatched('user-123', 20);

// Mark as watched
$controller->markAsWatched('session-123', 'media-456');

// Clear progress
$controller->clearProgress('session-123', 'media-456');
```

### SyncPlay Architecture

SyncPlay enables synchronized group watching with host-controlled playback. Multiple users can watch content together remotely, with the host controlling playback while all members receive synchronized commands.

#### Component Overview

```
src/Session/SyncPlay/
├── SyncPlayManager.php  # Main orchestrator for group sessions
├── GroupState.php     # Individual group state management
├── TimeSync.php       # NTP-style time synchronization
└── Messages.php        # WebSocket message type definitions
```

#### Protocol Flow

```
Client A (Host)                    Server                        Client B (Guest)
      |                               |                               |
      | --- CREATE_GROUP -----------> |                               |
      | <-- GROUP_STATE ------------- |                               |
      |                               |                               |
      |                               | --- JOIN_GROUP --------------> |
      |                               | <-- GROUP_STATE ------------- |
      |                               |                               |
      | --- PLAY -------------------- | ----------------------------> |
      | (broadcast position+time)     |                               |
      |                               |                               |
      | --- SEEK -------------------- | ----------------------------> |
      | (broadcast new position)      |                               |
      |                               |                               |
      | --- PING --------------------> |                               |
      | <-- PONG --------------------- |                               |
      | (calculate latency/offset)     |                               |
```

#### WebSocket Message Types

| Category | Type | Direction | Description |
|---------|------|----------|-------------|
| Group | `syncplay_group_create` | C→S | Create new group |
| Group | `syncplay_group_join` | C→S | Join existing group |
| Group | `syncplay_group_leave` | C→S | Leave current group |
| Group | `syncplay_group_state` | S→C | Full group state broadcast |
| Playback | `syncplay_playback_play` | C→S→C | Play/resume command |
| Playback | `syncplay_playback_pause` | C→S→C | Pause command |
| Playback | `syncplay_playback_seek` | C→S→C | Seek command |
| Playback | `syncplay_playback_queue` | C→S→C | Queue update |
| Chat | `syncplay_chat` | C→S→C | Chat message |
| Host | `syncplay_host_elect` | S→C | Automatic host election |
| Time | `syncplay_time_ping` | C→S | Time sync ping |
| Time | `syncplay_time_pong` | S→C | Time sync pong |
| Info | `syncplay_error` | S→C | Error notification |
| Info | `syncplay_info` | S→C | Info message |

#### Group Lifecycle

```
                    ┌─────────────────┐
                    │   NO GROUP      │
                    └────────┬────────┘
                             │ createGroup()
                             ▼
                    ┌─────────────────┐
         ┌─────────>│  GROUP_CREATED │<─────────┐
         │          └────────┬────────┘          │
         │                 │ joinGroup()        │
         │                 ▼                    │
         │          ┌─────────────────┐          │
         │          │   MEMBER_ADDED  │          │
         │          └────────┬────────┘          │
         │                 │                   │
         │    leaveGroup() │                   │
         │                 ▼                   │
         │          ┌─────────────────┐       │
         └──────────│  MEMBER_REMOVED │───────┘
                    └────────┬────────┘
                             │
                  (if last member)
                             ▼
                    ┌─────────────────┐
                    │   GROUP_REMOVED │
                    └─────────────────┘
```

#### Time Synchronization

SyncPlay uses NTP-style time synchronization to ensure accurate playback positioning across network latency:

1. Client sends `timePing` with local timestamp
2. Server responds with `timePong` containing both timestamps
3. Client calculates:
   - Round-trip time (RTT)
   - One-way latency (RTT / 2)
   - Clock offset (server_time - client_time + latency)
4. Multiple samples are averaged with weighting by RTT
5. Drift rate is calculated and applied to predicted positions

### SyncPlayManager (`SyncPlayManager`)

Coordinates group watching sessions:

```php
// Create a group
$result = $syncPlayManager->createGroup('Movie Night', 'password123', 'member-1', 'Host');

// Join a group
$result = $syncPlayManager->joinGroup('sp_abc123', 'member-2', 'Guest', 'password123');

// Leave a group
$result = $syncPlayManager->leaveGroup('member-2');

// Get group state
$state = $syncPlayManager->getGroupState('sp_abc123');

// List all groups
$groups = $syncPlayManager->listGroups();

// Cleanup stale groups
$removed = $syncPlayManager->cleanupStaleGroups(3600);

// Get statistics
$stats = $syncPlayManager->getStats();
// ['total_groups' => 5, 'total_members' => 12, 'time_sync_status' => [...]]
```

### GroupState (`GroupState`)

Manages individual group state:

```php
// Playback states
GroupState::STATE_PLAYING;   // 'playing'
GroupState::STATE_PAUSED;    // 'paused'
GroupState::STATE_BUFFERING;  // 'buffering'
GroupState::STATE_STOPPED;    // 'stopped'

// Member management
$group->addMember('member-id', ['name' => 'John']);
$group->removeMember('member-id');
$group->isHost('member-id');     // Check if host
$group->electNewHost();           // Auto-elect new host

// Playback queue
$group->addToQueue('media-id', ['name' => 'Movie']);
$group->removeFromQueue('media-id');
$queue = $group->getPlaybackQueue();

// Chat
$group->addChatMessage('member-id', 'Hello everyone!');
$messages = $group->getChatMessages(50);

// State serialization
$state = $group->getState();
$data = $group->serialize();  // For persistence
$group = GroupState::deserialize($data);  // Restore
```

### TimeSync (`TimeSync`)

NTP-style time synchronization for playback sync:

```php
// Process ping from client
$pong = $timeSync->processPing(['client_time' => $clientTime]);
// Returns: ['client_time' => ..., 'server_time' => ..., 'protocol_version' => 1]

// Process pong and calculate offset
$result = $timeSync->processPong($payload);
// Returns: ['offset' => 15, 'latency' => 50, 'rtt' => 100, 'is_stable' => true]

// Get synchronized time
$synchronizedTime = $timeSync->getSynchronizedTime();

// Convert between local and synchronized time
$localTime = $timeSync->synchronizedToLocal($syncedTime);
$syncedTime = $timeSync->localToSynchronized($localTime);

// Check sync stability
if ($timeSync->isSyncStable()) {
    // Time is synchronized
}

// Get status info
$status = $timeSync->getStatus();
// ['offset' => 15, 'latency' => 50, 'drift_rate' => 1.002, 'is_stable' => true, ...]
```

---

## DLNA/UPnP Architecture

The DLNA (Digital Living Network Alliance) system provides standard protocol support for media streaming to compatible devices (Roku, Samsung Tizen, Windows Media Player, etc.). Built on UPnP (Universal Plug and Play) standards.

### Component Overview

```
src/Dlna/
├── DlnaServer.php        # Main server, SOAP handling, SSDP/description
├── DlnaDevice.php        # Device representation (server/renderer)
├── ContentDirectory.php  # Browse/Search operations, DIDL-Lite generation
├── AvTransport.php      # Playback control (Play/Pause/Stop/Seek)
├── DeviceRegistry.php    # SSDP device discovery and caching
└── TransportState.php    # Per-instance playback state (nested in AvTransport)
```

### DLNA Protocol Stack

| Layer | Protocol | Purpose |
|-------|----------|---------|
| Discovery | SSDP (Simple Service Discovery Protocol) | Multicast M-SEARCH for device discovery |
| Description | HTTP + XML | Device description via URL from LOCATION header |
| Control | SOAP (Simple Object Access Protocol) | Action/response via HTTP POST |
| Event | GENA (Generic Event Notification Architecture) | State variable subscriptions |
| Transport | HTTP | Media streaming via GET requests |

### SSDP Discovery Flow

```
DeviceRegistry::discover()
    ↓
Send M-SEARCH multicast to 239.255.255.250:1900
    ↓
Parse HTTP/200 responses from devices
    ↓
For each device:
    → Fetch device description XML from LOCATION URL
    → Parse device type, services, icons
    → Create DlnaDevice and cache it
```

### UPnP Device Types

| Type | URN | Description |
|------|-----|-------------|
| MediaServer | `urn:schemas-upnp-org:device:MediaServer:1` | Content provider (Phlex server) |
| MediaRenderer | `urn:schemas-upnp-org:device:MediaRenderer:1` | Playback device (TV, receiver) |

### UPnP Services

| Service | Type | Actions |
|--------|------|---------|
| ContentDirectory | Server | Browse, Search, GetSearchCapabilities, GetSortCapabilities |
| AVTransport | Both | Play, Pause, Stop, Seek, GetTransportInfo, GetPositionInfo |
| ConnectionManager | Both | GetProtocolInfo, GetCurrentConnectionInfo |

### DIDL-Lite Format

DIDL-Lite (Digital Item Declaration Language Lite) is the XML format for representing media items:

```xml
<DIDL-Lite xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"
           xmlns:dc="http://purl.org/dc/elements/1.1/"
           xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/">
    <item id="obj-001" parentID="0" restricted="true">
        <dc:title>Movie Title</dc:title>
        <upnp:class>object.item.videoItem.movie</upnp:class>
        <upnp:artist>Director Name</upnp:artist>
        <upnp:genre>Action</upnp:genre>
        <upnp:duration>01:45:00</upnp:duration>
        <upnp:res protocolInfo="http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_MP_HD">
            http://server:8200/media/123.mp4
        </upnp:res>
    </item>
</DIDL-Lite>
```

### DlnaServer (`DlnaServer`)

The main DLNA server class implementing UPnP MediaServer:

```php
// Create DLNA server
$server = new DlnaServer(
    'server-001',
    'Phlex Media Server',
    '192.168.1.100',
    8200,
    $itemRepository,
    $logger
);

// Start server (registers with device registry)
$server->start();

// Get device description XML (for /device.xml endpoint)
$xml = $server->getDeviceDescriptionXml();

// Get SCPD XML for service (for /scpd/ContentDirectory.xml)
$scpd = $server->getScpdXml('ContentDirectory');

// Process SOAP requests from control points
$result = $server->processSoapRequest('ContentDirectory', 'Browse', $soapBody);

// Build SOAP response XML
$response = $server->buildSoapResponse('Browse', $result);
```

### DlnaDevice (`DlnaDevice`)

Represents a DLNA/UPnP device (server or renderer):

```php
// Create a MediaServer device
$device = new DlnaDevice(
    'uuid:phlex-server-001',
    DlnaDevice::TYPE_SERVER,
    'Phlex Media Server',
    '192.168.1.100',
    8200
);

// Add device icons
$device->addIcon([
    'mimetype' => 'image/png',
    'width' => 48,
    'height' => 48,
    'depth' => 32,
    'url' => '/icons/small.png',
]);

// Generate device description XML
$xml = $device->toDeviceDescriptionXml();

// Check capabilities
if ($device->hasCapability('Browse')) {
    // Device supports browsing
}
```

### ContentDirectory (`ContentDirectory`)

Handles content browsing and searching:

```php
// Browse root container
$result = $contentDir->browse('0', 'BrowseDirectChildren', '*', 0, 100, '');

// Browse specific object metadata
$result = $contentDir->browse('library-video', 'BrowseMetadata', '*', 0, 0, '');

// Search for content
$result = $contentDir->search(
    '0',
    'dc:title contains "Movie"',
    '*',
    0,
    50,
    ''
);

// Generate DIDL-Lite XML for items
$didl = $contentDir->generateDidl($items);

// Get search capabilities
// Returns: dc:title,dc:creator,upnp:artist,upnp:album
```

### AvTransport (`AvTransport`)

Handles playback control for renderers:

```php
$avTransport = new AvTransport($logger);

// Set the media URI to play
$avTransport->setAvTransportUri(0, 'http://server/media.mp4', $didlMetaData);

// Start playback
$result = $avTransport->play(0, '1');

// Pause playback
$result = $avTransport->pause(0);

// Stop playback
$result = $avTransport->stop(0);

// Seek to position (HH:MM:SS format)
$result = $avTransport->seek(0, 'REL_TIME', '00:05:30');

// Get current transport state
$info = $avTransport->getTransportInfo(0);
// ['CurrentTransportState' => 'PLAYING', 'CurrentTransportStatus' => 'OK', 'CurrentSpeed' => '1']

// Get position info
$pos = $avTransport->getPositionInfo(0);
// ['Track' => 1, 'TrackDuration' => '01:45:00', 'RelTime' => '00:05:30', ...]
```

### DeviceRegistry (`DeviceRegistry`)

Manages device discovery and caching via SSDP:

```php
$registry = new DeviceRegistry();

// Discover devices on network (with 5 second timeout)
$devices = $registry->discover(5);

// Get all discovered devices
$all = $registry->getDevices();

// Get only MediaServers
$servers = $registry->getServers();

// Get only MediaRenderers
$renderers = $registry->getRenderers();

// Find specific device by UDN
$device = $registry->getDevice('uuid:some-device-123');

// Register a device manually
$registry->registerDevice($newDevice);

// Unregister a device
$registry->unregisterDevice('uuid:device-to-remove');
```

### TransportState (`TransportState`)

Maintains playback state for a single AVTransport instance:

```php
// Get instance state
$state = $avTransport->getInstance(0);

// Check current state
$state->isPlaying();  // true if playing
$state->isPaused();   // true if paused
$state->isStopped(); // true if stopped

// Get/Set position (in ticks = 100-nanosecond units)
$position = $state->getPosition();
$state->setPosition(3000000000); // 30 seconds

// Get media info
$uri = $state->getMediaUri();
$duration = $state->getMediaDuration();
$metadata = $state->getMediaMetadata();

// Get/Set play mode
$mode = $state->getPlayMode(); // 'NORMAL', 'REPEAT_ONE', etc.
```

---

## Coding Standards

### PSR-12 Compliance

All code must follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style:

- 4 spaces for indentation
- Opening braces on same line for classes/functions
- Opening braces on next line for control structures
- Maximum line length: 120 characters
- PHP keywords lowercase: `true`, `false`, `null`
- Use `declare(strict_types=1)` on all PHP files

### PHPDoc Requirements

Every class, method, and property must have comprehensive PHPDoc:

```php
/**
 * Short description of the class.
 *
 * Longer description if needed, explaining the purpose
 * and usage of the class.
 *
 * @author Author Name
 * @version 1.0.0
 * @description Brief description of what this class does.
 * @see RelatedClass For related functionality
 */
class MyClass
{
    /** @var string Description of property */
    private string $myProperty;

    /**
     * Brief description of the method.
     *
     * @param string $param Description of parameter
     * @param array<string, mixed> $options Optional configuration
     * @return Response The response object
     * @throws InvalidArgumentException If parameters are invalid
     *
     * @example
     * ```php
     * $myClass->myMethod('value', ['option' => true]);
     * ```
     */
    public function myMethod(string $param, array $options = []): Response
    {
        // implementation
    }
}
```

### Type Safety

- Use strict type declarations: `declare(strict_types=1)`
- Declare return types on all methods
- Declare parameter types where known
- Use union types where appropriate: `callable|array`

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `UserProfileManager` |
| Methods | camelCase | `getUserById()` |
| Properties | camelCase | `userId` |
| Constants | UPPER_SNAKE | `AUTH_SUCCESS` |
| Variables | camelCase | `$userData` |
| Files | PascalCase | `UserProfileManager.php` |
| Interfaces | PascalCase + Interface suffix | `ConnectionInterface` |

---

## Testing Guide

### Test Structure

```
tests/
├── unit/
│   └── {Module}/
│       └── {ClassName}Test.php
└── integration/
    └── {Feature}/
        └── {Scenario}Test.php
```

### Writing Unit Tests

```php
<?php

namespace Phlex\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Http\Response;

/**
 * Unit tests for Response class.
 *
 * @covers \Phlex\Server\Http\Response
 */
class ResponseTest extends TestCase
{
    /**
     * @covers \Phlex\Server\Http\Response::json
     * @covers \Phlex\Server\Http\Response::status
     */
    public function testCanCreateJsonResponse(): void
    {
        $response = (new Response())->json(['key' => 'value']);

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('application/json', $response->headers['Content-Type']);
    }
}
```

### Test Guidelines

1. **One assertion per test** when practical
2. Use descriptive test names: `testCanCreateJsonResponse()`
3. Use `@covers` annotations to track coverage
4. Test both success and failure cases
5. Mock external dependencies

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with coverage report
./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration

# Run specific test class
./vendor/bin/phpunit tests/unit/Server/Http/ResponseTest.php

# Run tests matching a pattern
./vendor/bin/phpunit --filter testCanCreate
```

### Continuous Integration

GitHub Actions workflows run on every push:

1. **PHPUnit Tests**: Runs full test suite with MySQL service
2. **PHP CodeSniffer**: Checks PSR-12 compliance
3. **PHPStan**: Level 9 static analysis
4. **Psalm**: Additional static analysis
5. **Security Audit**: Checks for known vulnerabilities

---

## Git Workflow

### Branch Naming

| Type | Pattern | Example |
|------|---------|---------|
| Feature | `feature/{description}` | `feature/user-authentication` |
| Bug Fix | `fix/{description}` | `fix/session-timeout` |
| Hotfix | `hotfix/{description}` | `hotfix/security-patch` |
| Release | `release/{version}` | `release/1.2.0` |
| Chore | `chore/{description}` | `chore/update-deps` |

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>

[optional body]

[optional footer]
```

Types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

Examples:
```
feat(auth): add JWT refresh token support
fix(webhook): handle malformed JSON gracefully
docs(api): add endpoint documentation
test(session): add tests for concurrent sessions
chore(deps): upgrade workerman to v5.0
```

### Pull Request Process

1. **Create branch** from `develop` for new features, `master` for hotfixes
2. **Make changes** following coding standards
3. **Add tests** for new functionality
4. **Run locally**: `./vendor/bin/phpunit && ./vendor/bin/phpcs`
5. **Open PR** with clear description and reference issues
6. **Await review** and address feedback
7. **Squash merge** on approval

### Code Review Checklist

- [ ] Code follows PSR-12 style
- [ ] All new code has PHPDoc
- [ ] Tests pass and have adequate coverage
- [ ] No debug code left in
- [ ] No hardcoded credentials
- [ ] Proper error handling
- [ ] Security considerations addressed

---

## Useful Commands

```bash
# Development
php start.php server          # Start HTTP server
php start.php websocket       # Start WebSocket server
php start.php both            # Start both servers

# Testing
./vendor/bin/phpunit                    # Run all tests
./vendor/bin/phpunit --coverage-html coverage/  # With coverage
./vendor/bin/phpunit --testsuite Unit  # Unit tests only

# Code Quality
./vendor/bin/phpcs --standard=PSR12 src/           # Check style
./vendor/bin/phpstan analyze src/ --level=9        # Static analysis
./vendor/bin/psalm                                     # Psalm analysis

# Database
php scripts/migrate.php                  # Run migrations
php scripts/seed.php                     # Seed database
```

---

## Additional Resources

- [Technical Specification](./PHLEX_MEDIA_SERVER_TECHNICAL_SPEC.md)
- [Implementation Plan](./IMPLEMENTATION_PLAN.md)
- [API Documentation](./public_html/spec/openapi.yaml)
- [Platform Documentation](./PLATFORM_*.md)
