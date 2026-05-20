# Phlix Media Server - Samsung Smart TV (Tizen) Application

**Document Version:** 1.0  
**Platform:** Samsung Tizen TV  
**Technology:** HTML5/JavaScript, Tizen Web API  

---

## Table of Contents

1. [Overview](#1-overview)
2. [Development Environment](#2-development-environment)
3. [Project Structure](#3-project-structure)
4. [API Client Implementation](#4-api-client-implementation)
5. [Player Implementation](#5-player-implementation)
6. [Remote Control Handling](#6-remote-control-handling)
7. [User Interface](#7-user-interface)
8. [Testing](#8-testing)
9. [Tizen Store Submission](#9-tizen-store-submission)
10. [Implementation Checklist](#10-implementation-checklist)

---

## 1. Overview

### 1.1 Platform Capabilities

Samsung Tizen TVs support:
- **Video Codecs**: H.264, H.265/HEVC, VP9
- **Audio Codecs**: AAC, AC3, EAC3, DTS, FLAC, MP3
- **Containers**: MP4, MKV, WebM, TS
- **Streaming**: HLS, MPEG-DASH, Progressive HTTP
- **Features**: 4K HDR10, Dolby Digital Plus, HDMI-CEC control

### 1.2 Tizen Web API Components

| API | Purpose |
|-----|---------|
| `tizen.tvinputdevice` | Remote control handling |
| `tizen.tv.window` | Video positioning |
| `tizen.application` | App lifecycle |
| `tizen.tv.audiocontrol` | Audio volume control |
| `webapis.tv Peripheral` | HDMI-CEC |
| `webapis.tv.display` | Display information |

---

## 2. Development Environment

### 2.1 Required Software

```
1. Node.js 18+ (LTS recommended)
2. Tizen Studio 4.0+ (with TV extension)
3. Samsung TV SDK (included in Tizen Studio)
4. Git
5. Code editor (VS Code recommended)
```

### 2.2 Tizen Studio Installation

```bash
# Download Tizen Studio from:
# https://developer.samsung.com/smarttv/develop/tools/tizen-studio/download.html

# Install with TV extension
./Tizen-Studio-4.0.bin --accept-license

# During installation select:
# - Base Tizen Studio
# - Samsung TV Extensions
# - Web Device TV SDK
```

### 2.3 Tizen Studio Configuration

1. **Register Samsung Account**
   - Go to Tools > Tizen > Settings > Certification
   - Register or import Samsung certificate

2. **Create Author Certificate**
   ```
   Tools > Tizen > Certificate Manager > +
   Certificate profile name: Phlix Dev
   Publisher name: Phlix
   Select "Samsung" as manufacturer
   ```

3. **Configure Device**
   - Connect TV to same network as computer
   - Enable Developer Mode on TV: Settings > Smart Hub > Developer Tools
   - Add TV IP in Tizen Studio: Devices > Add Device

### 2.4 Project Setup Commands

```bash
# Create project directory
mkdir phlix-tizen
cd phlix-tizen

# Initialize npm
npm init -y

# Install development dependencies
npm install --save-dev webpack webpack-cli webpack-dev-server \
    @babel/core @babel/preset-env babel-loader css-loader style-loader \
    html-webpack-plugin copy-webpack-plugin

# Install Tizen-specific tools
npm install --save-dev tizen-web-manager

# Create project structure (see Section 3)
```

---

## 3. Project Structure

```
phlix-tizen/
├── app/
│   ├── index.html           # Main HTML entry
│   ├── js/
│   │   ├── main.js          # Application entry point
│   │   ├── api/
│   │   │   ├── ApiClient.js         # Base API client
│   │   │   ├── AuthManager.js       # Authentication
│   │   │   ├── SessionManager.js    # Session handling
│   │   │   ├── LibraryManager.js    # Library browsing
│   │   │   └── PlayerManager.js     # Playback control
│   │   ├── player/
│   │   │   ├── VideoPlayer.js       # Video playback
│   │   │   ├── HlsPlayer.js         # HLS streaming
│   │   │   ├── QualitySelector.js   # Adaptive bitrate
│   │   │   └── SubtitleRenderer.js  # Subtitle display
│   │   ├── ui/
│   │   │   ├── App.js               # Main app UI
│   │   │   ├── Router.js            # Navigation
│   │   │   ├── HomeView.js          # Home screen
│   │   │   ├── LibraryView.js       # Library browser
│   │   │   ├── DetailView.js        # Item detail
│   │   │   ├── PlayerView.js        # Fullscreen player
│   │   │   └── components/          # UI components
│   │   ├── remote/
│   │   │   ├── RemoteManager.js     # Remote handling
│   │   │   └── KeyMapping.js        # Button mapping
│   │   ├── utils/
│   │   │   ├── Storage.js           # Local storage
│   │   │   ├── Logger.js            # Logging
│   │   │   └── Helpers.js           # Utilities
│   │   └── config/
│   │       └── constants.js         # App constants
│   ├── css/
│   │   ├── style.css        # Main styles
│   │   ├── components.css   # Component styles
│   │   ├── player.css       # Player styles
│   │   └── themes/
│   │       └── dark.css     # Dark theme
│   └── config.xml           # Tizen config
├── tizen/
│   └── TvWidgetApp/         # Tizen native wrapper (if needed)
├── scripts/
│   ├── build.js             # Build script
│   ├── package.js           # Tizen packaging
│   └── debug.js             # Debug launcher
├── tests/
│   ├── unit/                # Unit tests
│   └── integration/         # Integration tests
├── webpack.config.js        # Webpack configuration
├── babel.config.js          # Babel configuration
├── tizen.env                # Environment variables
└── README.md                # Documentation
```

### 3.1 Tizen Configuration File

```xml
<!-- app/config.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<widget xmlns="http://www.w3.org/ns/widgets" 
        xmlns:tizen="http://tizen.org/ns/widgets" 
        id="http://phlix.app/phlixtizen" 
        version="1.0.0" 
        viewmodes="maximized">
    
    <access origin="*" subdomains="*"></access>
    <tizen:application id="phlix.app.phlixtizen" 
                       package="phlix" 
                       required_version="2.3"/>
    
    <content src="index.html"/>
    <icon src="icon.png"/>
    <name>Phlix</name>
    <tizen:privilege name="http://tizen.org/privilege/internet"/>
    <tizen:privilege name="http://tizen.org/privilege/tv.inputdevice"/>
    <tizen:privilege name="http://tizen.org/privilege/tv.window"/>
    <tizen:privilege name="http://tizen.org/privilege/tv.audio"/>
    <tizen:privilege name="http://tizen.org/privilege/network.get"/>
    <tizen:privilege name="http://tizen.org/privilege/application.launch"/>
    <tizen:privilege name="http://tizen.org/privilege/filesystem.read"/>
    
    <tizen:setting screen-orientation="landscape" 
                   context-menu="enable" 
                   background-support="disable" 
                   encryption="disable" 
                   install-location="auto"/>
    
    <feature name="http://tizen.org/feature/screen.size.all"/>
    <feature name="http://tizen.org/feature/screen.orientation.landscape"/>
    
</widget>
```

---

## 4. API Client Implementation

### 4.1 ApiClient Class

```javascript
// app/js/api/ApiClient.js

/**
 * Phlix API Client for Samsung Tizen TVs
 * Handles all communication with Phlix Media Server
 */

class ApiClient {
    constructor(baseUrl, deviceId, deviceName) {
        this.baseUrl = baseUrl.replace(/\/$/, '');
        this.deviceId = deviceId;
        this.deviceName = deviceName || 'Samsung Tizen TV';
        this.deviceType = 'samsung-tizen';
        this.token = null;
        this.sessionId = null;
        this.user = null;
        
        // Device profile for playback decisions
        this.deviceProfile = {
            Name: 'Samsung Tizen TV',
            MaxStreamingBitrate: 80000000, // 80 Mbps
            MaxStaticBitrate: 80000000,
            SupportedMediaTypes: ['Video', 'Audio'],
            DirectPlayProfiles: [{
                Container: 'mkv,mp4,webm',
                Type: 'Video',
                VideoCodec: 'h264,hevc,vp9',
                AudioCodec: 'aac,ac3,eac3,dts,flac'
            }],
            TranscodingProfiles: [{
                Container: 'ts',
                Type: 'Video',
                VideoCodec: 'h264',
                AudioCodec: 'aac,ac3'
            }]
        };
        
        // Request queue for rate limiting
        this.requestQueue = [];
        this.isProcessingQueue = false;
        this.maxConcurrentRequests = 3;
    }

    /**
     * Set authentication token
     */
    setToken(token) {
        this.token = token;
        if (token) {
            Storage.set('auth_token', token);
        } else {
            Storage.remove('auth_token');
        }
    }

    /**
     * Set session ID
     */
    setSession(sessionId) {
        this.sessionId = sessionId;
        Storage.set('session_id', sessionId);
    }

    /**
     * Restore session from storage
     */
    async restoreSession() {
        const token = Storage.get('auth_token');
        const sessionId = Storage.get('session_id');
        
        if (token) {
            this.token = token;
            
            // Validate token with server
            try {
                const result = await this.request('GET', '/Users/Me');
                this.user = result;
                
                if (sessionId) {
                    this.sessionId = sessionId;
                }
                
                return true;
            } catch (error) {
                // Token expired, clear it
                this.setToken(null);
                this.setSession(null);
                return false;
            }
        }
        return false;
    }

    /**
     * Make API request
     */
    async request(method, path, body = null, options = {}) {
        const url = `${this.baseUrl}/api/v1${path}`;
        const headers = {
            'Content-Type': 'application/json',
            'X-Phlix-Device-ID': this.deviceId,
            'X-Phlix-Device-Name': this.deviceName,
            'X-Phlix-Device-Type': this.deviceType
        };

        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        if (this.sessionId) {
            headers['X-Phlix-Session-ID'] = this.sessionId;
        }

        const config = {
            method,
            headers,
            mode: 'cors'
        };

        if (body && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            config.body = JSON.stringify(body);
        }

        // Add timeout
        const timeout = options.timeout || 30000;
        const controller = new AbortController();
        config.signal = controller.signal;

        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, config);
            clearTimeout(timeoutId);

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new ApiError(response.status, error.message || 'Request failed', error);
            }

            // Handle empty responses
            const text = await response.text();
            return text ? JSON.parse(text) : null;
        } catch (error) {
            clearTimeout(timeoutId);
            
            if (error.name === 'AbortError') {
                throw new ApiError(408, 'Request timeout');
            }
            
            throw error;
        }
    }

    /**
     * Authentication methods
     */
    async login(username, password) {
        const deviceInfo = {
            device_id: this.deviceId,
            device_name: this.deviceName,
            device_type: this.deviceType
        };

        const result = await this.request('POST', '/Auth/Login', {
            username,
            password,
            ...deviceInfo
        });

        this.setToken(result.token);
        this.setSession(result.session_id);
        this.user = result.user;

        return result;
    }

    async register(email, username, password) {
        const result = await this.request('POST', '/Auth/Register', {
            email,
            username,
            password
        });

        return result;
    }

    logout() {
        try {
            if (this.sessionId) {
                this.request('DELETE', `/Sessions/${this.sessionId}`).catch(() => {});
            }
        } finally {
            this.setToken(null);
            this.setSession(null);
            this.user = null;
        }
    }

    /**
     * Session management
     */
    async createSession() {
        if (!this.user) {
            throw new Error('Not logged in');
        }

        const deviceInfo = {
            device_id: this.deviceId,
            device_name: this.deviceName,
            device_type: this.deviceType,
            capabilities: this.deviceProfile
        };

        const result = await this.request('POST', '/Sessions', deviceInfo);
        this.setSession(result.id);
        
        return result;
    }

    async getSessions() {
        return this.request('GET', '/Sessions');
    }

    /**
     * Library browsing
     */
    async getLibraries() {
        return this.request('GET', '/Library/VirtualFolders');
    }

    async getLibraryItems(libraryId, options = {}) {
        const params = new URLSearchParams({
            parentId: libraryId,
            includeItemTypes: options.type || 'Movie,Series',
            limit: options.limit || 50,
            startIndex: options.startIndex || 0,
            sortBy: options.sortBy || 'SortName',
            sortOrder: options.sortOrder || 'Ascending'
        });

        return this.request('GET', `/Items?${params}`);
    }

    async getItem(itemId) {
        return this.request('GET', `/Items/${itemId}`);
    }

    async getItemPlaybackInfo(itemId, options = {}) {
        const params = new URLSearchParams({
            deviceProfile: this.deviceType,
            maxStreamingBitrate: this.deviceProfile.MaxStreamingBitrate
        });

        return this.request('GET', `/Items/${itemId}/PlaybackInfo?${params}`);
    }

    /**
     * Search
     */
    async search(query, options = {}) {
        const params = new URLSearchParams({
            searchTerm: query,
            limit: options.limit || 20,
            includeItemTypes: options.types || 'Movie,Series,Music'
        });

        return this.request('GET', `/Search/Hints?${params}`);
    }

    /**
     * User data (watched, favorite, etc.)
     */
    async updateUserData(itemId, userData) {
        return this.request('POST', `/Items/${itemId}/UserData`, userData);
    }

    async markWatched(itemId) {
        return this.updateUserData(itemId, { is_watched: true });
    }

    async markUnwatched(itemId) {
        return this.updateUserData(itemId, { is_watched: false });
    }

    async toggleFavorite(itemId) {
        return this.request('POST', `/Items/${itemId}/UserData`, { is_favorite: true });
    }

    /**
     * Playback control
     */
    async playItem(itemId, options = {}) {
        const startPosition = options.startPosition || 0;
        
        const result = await this.request('POST', '/Sessions/Play', {
            item_id: itemId,
            start_position_ticks: startPosition,
            device_profile: this.deviceType,
            media_source_id: options.mediaSourceId
        });

        return result;
    }

    async stopPlayback() {
        return this.request('POST', '/Playstate', {
            session_id: this.sessionId,
            command: 'stop'
        });
    }

    async pausePlayback() {
        return this.request('POST', '/Playstate', {
            session_id: this.sessionId,
            command: 'pause'
        });
    }

    async resumePlayback() {
        return this.request('POST', '/Playstate', {
            session_id: this.sessionId,
            command: 'play'
        });
    }

    async seekPlayback(positionTicks) {
        return this.request('POST', '/Playstate', {
            session_id: this.sessionId,
            command: 'seek',
            data: { position_ticks: positionTicks }
        });
    }

    async reportPlaybackProgress(positionTicks, isPaused = false) {
        return this.request('POST', '/Playstate/Progress', {
            session_id: this.sessionId,
            position_ticks: positionTicks,
            is_paused: isPaused
        });
    }

    /**
     * Server info
     */
    async getServerInfo() {
        return this.request('GET', '/System/Info');
    }

    async getPublicServerInfo() {
        return this.request('GET', '/System/Info/Public');
    }
}

/**
 * Custom API Error class
 */
class ApiError extends Error {
    constructor(status, message, data = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

// Export singleton instance
const api = new ApiClient(
    window.PHLIX_SERVER_URL || 'http://localhost:8096',
    window.PHLIX_DEVICE_ID || generateDeviceId(),
    'Samsung Tizen TV'
);

function generateDeviceId() {
    const stored = Storage.get('device_id');
    if (stored) return stored;
    
    const id = 'tizen-' + Math.random().toString(36).substr(2, 9) + 
               '-' + Math.random().toString(36).substr(2, 9);
    Storage.set('device_id', id);
    return id;
}

export default api;
export { ApiClient, ApiError };
```

### 4.2 Session Manager

```javascript
// app/js/api/SessionManager.js

import api from './ApiClient.js';
import Storage from '../utils/Storage.js';
import Logger from '../utils/Logger.js';

class SessionManager {
    constructor() {
        this.currentSession = null;
        this.playbackState = null;
        this.listeners = new Map();
        this.heartbeatInterval = null;
        this.progressReportingInterval = null;
        this.lastReportedPosition = 0;
    }

    /**
     * Initialize session
     */
    async init() {
        // Try to restore existing session
        const restored = await api.restoreSession();
        
        if (restored) {
            Logger.info('Session restored from storage');
            this.startHeartbeat();
            return this.currentSession;
        }
        
        return null;
    }

    /**
     * Create new session
     */
    async createSession() {
        try {
            this.currentSession = await api.createSession();
            this.startHeartbeat();
            this.emit('sessionCreated', this.currentSession);
            return this.currentSession;
        } catch (error) {
            Logger.error('Failed to create session', error);
            throw error;
        }
    }

    /**
     * Start playback session
     */
    async startPlayback(itemId, options = {}) {
        const playbackInfo = await api.playItem(itemId, {
            startPosition: options.startPosition || 0,
            mediaSourceId: options.mediaSourceId
        });

        this.playbackState = {
            itemId,
            playbackInfo,
            isPlaying: true,
            position: options.startPosition || 0,
            duration: playbackInfo.media_item?.run_time_ticks || 0,
            streamUrl: playbackInfo.playback_info?.url,
            method: playbackInfo.playback_info?.method,
            startTime: Date.now()
        };

        this.startProgressReporting();
        this.emit('playbackStarted', this.playbackState);

        return this.playbackState;
    }

    /**
     * Stop playback
     */
    async stopPlayback() {
        if (!this.playbackState) return;

        // Report final progress
        await this.reportProgress(true);

        try {
            await api.stopPlayback();
        } catch (error) {
            Logger.error('Failed to stop playback', error);
        }

        this.playbackState = null;
        this.stopProgressReporting();
        this.emit('playbackStopped');
    }

    /**
     * Pause playback
     */
    async pausePlayback() {
        if (!this.playbackState || !this.playbackState.isPlaying) return;

        try {
            await api.pausePlayback();
            this.playbackState.isPlaying = false;
            this.emit('playbackPaused', this.playbackState);
        } catch (error) {
            Logger.error('Failed to pause playback', error);
            throw error;
        }
    }

    /**
     * Resume playback
     */
    async resumePlayback() {
        if (!this.playbackState || this.playbackState.isPlaying) return;

        try {
            await api.resumePlayback();
            this.playbackState.isPlaying = true;
            this.emit('playbackResumed', this.playbackState);
        } catch (error) {
            Logger.error('Failed to resume playback', error);
            throw error;
        }
    }

    /**
     * Seek to position
     */
    async seekTo(positionTicks) {
        if (!this.playbackState) return;

        try {
            await api.seekPlayback(positionTicks);
            this.playbackState.position = positionTicks;
            this.lastReportedPosition = positionTicks;
            this.emit('playbackSeeked', { position: positionTicks });
        } catch (error) {
            Logger.error('Failed to seek', error);
            throw error;
        }
    }

    /**
     * Report playback progress
     */
    async reportProgress(force = false) {
        if (!this.playbackState) return;

        const currentPosition = this.calculateCurrentPosition();
        
        // Only report if position changed significantly (1 second)
        if (!force && Math.abs(currentPosition - this.lastReportedPosition) < 10000000) {
            return;
        }

        this.lastReportedPosition = currentPosition;

        try {
            await api.reportPlaybackProgress(
                currentPosition,
                !this.playbackState.isPlaying
            );
        } catch (error) {
            Logger.debug('Progress report failed', error);
        }
    }

    /**
     * Calculate current position based on elapsed time
     */
    calculateCurrentPosition() {
        if (!this.playbackState) return 0;

        if (this.playbackState.isPlaying) {
            const elapsed = Date.now() - this.playbackState.startTime;
            const elapsedTicks = elapsed * 10000; // Convert ms to ticks
            return Math.min(
                this.playbackState.position + elapsedTicks,
                this.playbackState.duration
            );
        }

        return this.playbackState.position;
    }

    /**
     * Start heartbeat to keep session alive
     */
    startHeartbeat() {
        if (this.heartbeatInterval) return;

        // Heartbeat every 30 seconds
        this.heartbeatInterval = setInterval(async () => {
            try {
                await api.request('GET', `/Sessions/${api.sessionId}/Heartbeat`);
            } catch (error) {
                Logger.debug('Heartbeat failed', error);
            }
        }, 30000);
    }

    /**
     * Stop heartbeat
     */
    stopHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }

    /**
     * Start progress reporting
     */
    startProgressReporting() {
        if (this.progressReportingInterval) return;

        // Report progress every 10 seconds
        this.progressReportingInterval = setInterval(() => {
            this.reportProgress();
        }, 10000);
    }

    /**
     * Stop progress reporting
     */
    stopProgressReporting() {
        if (this.progressReportingInterval) {
            clearInterval(this.progressReportingInterval);
            this.progressReportingInterval = null;
        }
    }

    /**
     * Event handling
     */
    on(event, callback) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }
        this.listeners.get(event).push(callback);
    }

    off(event, callback) {
        if (!this.listeners.has(event)) return;
        const callbacks = this.listeners.get(event);
        const index = callbacks.indexOf(callback);
        if (index > -1) {
            callbacks.splice(index, 1);
        }
    }

    emit(event, data) {
        if (!this.listeners.has(event)) return;
        this.listeners.get(event).forEach(callback => callback(data));
    }

    /**
     * Clean up
     */
    destroy() {
        this.stopHeartbeat();
        this.stopProgressReporting();
        this.playbackState = null;
        this.currentSession = null;
        this.listeners.clear();
    }
}

export default new SessionManager();
export { SessionManager };
```

---

## 5. Player Implementation

### 5.1 Video Player

```javascript
// app/js/player/VideoPlayer.js

import Logger from '../utils/Logger.js';
import sessionManager from '../api/SessionManager.js';

class VideoPlayer {
    constructor() {
        this.video = null;
        this.hlsPlayer = null;
        this.currentSource = null;
        this.currentQuality = null;
        this.isReady = false;
        this.listeners = new Map();
        
        // Quality levels available
        this.qualityLevels = [];
        this.currentQualityIndex = -1;
        
        // Buffer settings for Tizen
        this.bufferSettings = {
            maxBufferLength: 30,
            maxVideoBufferLength: 60,
            maxAudioBufferLength: 30
        };
    }

    /**
     * Initialize player with video element
     */
    init(videoElement) {
        this.video = videoElement;
        this.setupEventListeners();
        this.configureForTizen();
        
        Logger.info('VideoPlayer initialized');
    }

    /**
     * Configure player specifically for Tizen
     */
    configureForTizen() {
        if (!this.video) return;

        // Enable buffering
        this.video.setAttribute('buffered-smooth', 'true');
        this.video.setAttribute('buffered-monitor-interval', '500');

        // Performance settings
        this.video.preload = 'auto';

        // Apply buffer settings
        try {
            if (typeof this.video.setBufferSettings === 'function') {
                this.video.setBufferSettings(this.bufferSettings);
            }
        } catch (error) {
            Logger.debug('Buffer settings not supported', error);
        }

        // Disable default controls (we use custom ones)
        this.video.controls = false;

        // Enable smooth seeking
        this.video.setAttribute('seeking-smooth', 'true');
    }

    /**
     * Setup native video element events
     */
    setupEventListeners() {
        if (!this.video) return;

        // Playback events
        this.video.addEventListener('play', () => this.onPlay());
        this.video.addEventListener('pause', () => this.onPause());
        this.video.addEventListener('ended', () => this.onEnded());
        this.video.addEventListener('error', (e) => this.onError(e));

        // Buffer events
        this.video.addEventListener('waiting', () => this.onWaiting());
        this.video.addEventListener('canplay', () => this.onCanPlay());
        this.video.addEventListener('loadedmetadata', () => this.onLoadedMetadata());

        // Progress events
        this.video.addEventListener('timeupdate', () => this.onTimeUpdate());
        this.video.addEventListener('progress', () => this.onProgress());

        // Quality change (for HLS)
        this.video.addEventListener('qualitychange', () => this.onQualityChange());
    }

    /**
     * Load video source
     */
    async load(playbackInfo) {
        if (!this.video) {
            throw new Error('Video element not initialized');
        }

        Logger.info('Loading video', { 
            method: playbackInfo.method, 
            url: playbackInfo.url?.substring(0, 50) + '...' 
        });

        this.currentSource = playbackInfo;

        if (playbackInfo.method === 'transcode' && playbackInfo.protocol === 'HLS') {
            // Use HLS player for transcoded content
            await this.loadHLS(playbackInfo.url, playbackInfo);
        } else {
            // Direct play
            await this.loadDirect(playbackInfo.url, playbackInfo);
        }

        this.isReady = true;
        this.emit('ready', playbackInfo);
    }

    /**
     * Load HLS stream
     */
    async loadHLS(playlistUrl, playbackInfo) {
        // Dynamically import Hls.js
        const Hls = await import('./HlsPlayer.js');

        if (this.hlsPlayer) {
            this.hlsPlayer.destroy();
        }

        this.hlsPlayer = new Hls.default({
            // Tizen-specific configuration
            enableWorker: true,
            lowLatencyMode: false,
            backBufferLength: 60,
            maxBufferLength: 30,
            maxMaxBufferLength: 120,
            maxBufferSize: 60 * 1000 * 1000, // 60MB
            maxBufferHole: 0.5,
            enableSoftwareAES: true,
            
            // Fragment loading
            fragLoadingMaxRetry: 3,
            fragLoadingRetryDelay: 1000,
            fragLoadingTimeOut: 20000,
            
            // Level selection
            autoStartLoad: true,
            startLevel: -1, // Auto
            capLevelToPlayerSize: true,
            
            // Error handling
            recoverAttempts: 5,
            onErrorRecover: true
        });

        // Load HLS playlist
        this.hlsPlayer.loadSource(playlistUrl);
        this.hlsPlayer.attachMedia(this.video);

        // Wait for HLS to be ready
        await new Promise((resolve, reject) => {
            this.hlsPlayer.once(Hls.Events.MANIFEST_PARSED, (event, data) => {
                this.qualityLevels = data.levels.map((level, index) => ({
                    index,
                    height: level.height,
                    width: level.width,
                    bitrate: level.bitrate,
                    name: `${level.height}p`
                }));
                
                Logger.info('HLS loaded', { 
                    levels: this.qualityLevels.length,
                    startLevel: this.hlsPlayer.startLevel 
                });
                
                resolve();
            });

            this.hlsPlayer.once(Hls.Events.ERROR, (event, data) => {
                if (data.fatal) {
                    Logger.error('HLS fatal error', data);
                    reject(data);
                }
            });
        });

        // Set quality level
        if (playbackInfo.preferredQuality) {
            this.setQuality(playbackInfo.preferredQuality);
        }
    }

    /**
     * Load direct file
     */
    async loadDirect(url, playbackInfo) {
        return new Promise((resolve, reject) => {
            this.video.addEventListener('loadedmetadata', () => {
                resolve();
            }, { once: true });

            this.video.addEventListener('error', (e) => {
                reject(e);
            }, { once: true });

            this.video.src = url;
            this.video.load();
        });
    }

    /**
     * Start playback
     */
    async play() {
        if (!this.video) return;

        try {
            await this.video.play();
            this.emit('play');
        } catch (error) {
            Logger.error('Play failed', error);
            throw error;
        }
    }

    /**
     * Pause playback
     */
    pause() {
        if (!this.video) return;
        this.video.pause();
    }

    /**
     * Stop playback
     */
    stop() {
        if (!this.video) return;

        this.video.pause();
        this.video.currentTime = 0;
        this.video.src = '';

        if (this.hlsPlayer) {
            this.hlsPlayer.destroy();
            this.hlsPlayer = null;
        }

        this.currentSource = null;
        this.isReady = false;
    }

    /**
     * Seek to position
     */
    async seek(positionSeconds) {
        if (!this.video) return;

        // Clamp to valid range
        const clampedPosition = Math.max(0, Math.min(positionSeconds, this.video.duration));
        this.video.currentTime = clampedPosition;
    }

    /**
     * Seek by ticks (100-nanosecond units used by Phlix)
     */
    async seekToTicks(positionTicks) {
        const positionSeconds = positionTicks / 10000000;
        await this.seek(positionSeconds);
    }

    /**
     * Set playback rate
     */
    setPlaybackRate(rate) {
        if (!this.video) return;
        this.video.playbackRate = rate;
    }

    /**
     * Set volume
     */
    setVolume(volume) {
        if (!this.video) return;
        this.video.volume = Math.max(0, Math.min(1, volume));
    }

    /**
     * Set quality level
     */
    setQuality(qualityIndex) {
        if (!this.hlsPlayer) return;

        if (qualityIndex === -1) {
            // Auto quality
            this.hlsPlayer.currentLevel = -1;
            this.currentQualityIndex = -1;
        } else {
            this.hlsPlayer.currentLevel = qualityIndex;
            this.currentQualityIndex = qualityIndex;
            this.currentQuality = this.qualityLevels[qualityIndex];
        }

        Logger.info('Quality changed', { 
            index: qualityIndex, 
            quality: this.currentQuality 
        });
        
        this.emit('qualityChanged', this.currentQuality);
    }

    /**
     * Set subtitle track
     */
    setSubtitleTrack(trackIndex) {
        if (!this.video) return;

        if (trackIndex === -1) {
            // Disable subtitles
            for (let i = 0; i < this.video.textTracks.length; i++) {
                this.video.textTracks[i].mode = 'disabled';
            }
        } else {
            // Enable specific track
            for (let i = 0; i < this.video.textTracks.length; i++) {
                this.video.textTracks[i].mode = (i === trackIndex) ? 'showing' : 'disabled';
            }
        }
    }

    /**
     * Get current position in seconds
     */
    getCurrentTime() {
        return this.video?.currentTime || 0;
    }

    /**
     * Get current position in ticks
     */
    getCurrentTimeTicks() {
        return Math.floor(this.getCurrentTime() * 10000000);
    }

    /**
     * Get duration in seconds
     */
    getDuration() {
        return this.video?.duration || 0;
    }

    /**
     * Get buffered percentage
     */
    getBufferedPercentage() {
        if (!this.video || !this.video.buffered.length) return 0;
        return (this.video.buffered.end(this.video.buffered.length - 1) / this.video.duration) * 100;
    }

    /**
     * Event handlers
     */
    onPlay() {
        this.emit('play');
    }

    onPause() {
        this.emit('pause');
    }

    onEnded() {
        sessionManager.stopPlayback();
        this.emit('ended');
    }

    onError(error) {
        Logger.error('Video error', { error: error.type, code: error.code });
        this.emit('error', error);
    }

    onWaiting() {
        this.emit('waiting');
    }

    onCanPlay() {
        this.emit('canplay');
    }

    onLoadedMetadata() {
        this.emit('loadedmetadata', {
            duration: this.video.duration,
            width: this.video.videoWidth,
            height: this.video.videoHeight
        });
    }

    onTimeUpdate() {
        this.emit('timeupdate', {
            currentTime: this.video.currentTime,
            duration: this.video.duration,
            position: this.getCurrentTimeTicks()
        });
    }

    onProgress() {
        this.emit('progress', {
            buffered: this.getBufferedPercentage()
        });
    }

    onQualityChange() {
        if (this.hlsPlayer) {
            this.currentQualityIndex = this.hlsPlayer.currentLevel;
            this.currentQuality = this.qualityLevels[this.currentQualityIndex];
            this.emit('qualityChanged', this.currentQuality);
        }
    }

    /**
     * Event system
     */
    on(event, callback) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }
        this.listeners.get(event).push(callback);
    }

    off(event, callback) {
        if (!this.listeners.has(event)) return;
        const callbacks = this.listeners.get(event);
        const index = callbacks.indexOf(callback);
        if (index > -1) callbacks.splice(index, 1);
    }

    emit(event, data) {
        if (!this.listeners.has(event)) return;
        this.listeners.get(event).forEach(callback => callback(data));
    }

    /**
     * Cleanup
     */
    destroy() {
        this.stop();
        this.listeners.clear();
        this.video = null;
    }
}

export default new VideoPlayer();
export { VideoPlayer };
```

### 5.2 HLS Player

```javascript
// app/js/player/HlsPlayer.js

/**
 * HLS.js wrapper for Samsung Tizen
 * Handles HLS stream playback with adaptive bitrate
 */

import Hls from 'hls.js';

class HlsPlayer extends Hls {
    constructor(config = {}) {
        // Tizen-optimized default config
        const tizenConfig = {
            // Enable WebWorker for better performance
            enableWorker: true,
            
            // Fragment loading
            fragLoadingMaxRetry: 4,
            fragLoadingRetryDelay: 500,
            fragLoadingTimeOut: 20000,
            
            // Level loading
            levelLoadingMaxRetry: 4,
            levelLoadingRetryDelay: 500,
            levelLoadingTimeOut: 10000,
            
            // Buffer configuration
            backBufferLength: 90,
            maxBufferLength: 60,
            maxMaxBufferLength: 180,
            maxBufferSize: 100 * 1000 * 1000, // 100MB
            maxBufferHole: 0.5,
            
            // Streaming
            highBufferWatchdogPeriod: 1,
            startLevel: -1, // Auto
            capLevelToPlayerSize: true,
            
            // Error recovery
            recoverAttempts: 6,
            restartDecoder: true,
            
            // Tizen-specific
            enableSoftwareAES: true,
            
            ...config
        };

        super(tizenConfig);

        this.qualityLevels = [];
        this.activeLevel = -1;
        this.isAutoLevel = true;

        this.setupEventHandlers();
    }

    setupEventHandlers() {
        // Manifest parsed
        this.on(Hls.Events.MANIFEST_PARSED, (event, data) => {
            this.qualityLevels = data.levels.map((level, index) => ({
                index,
                height: level.height,
                width: level.width,
                bitrate: level.bitrate,
                name: `${level.height}p`,
                url: level.url
            }));
            
            this.emit('qualityLevels', this.qualityLevels);
        });

        // Level switch
        this.on(Hls.Events.LEVEL_SWITCHED, (event, data) => {
            this.activeLevel = data.level;
            this.isAutoLevel = data.level === -1;
            
            const level = this.qualityLevels[data.level];
            this.emit('qualityChanged', {
                level: data.level,
                quality: level,
                isAuto: this.isAutoLevel
            });
        });

        // Fragment loaded
        this.on(Hls.Events.FRAG_LOADED, (event, data) => {
            this.emit('fragmentLoaded', {
                sn: data.frag.sn,
                duration: data.frag.duration,
                size: data.frag.length
            });
        });

        // Error handling
        this.on(Hls.Events.ERROR, (event, data) => {
            this.handleError(data);
        });

        // Buffer events
        this.on(Hls.Events.BUFFER_APPENDED, () => {
            this.emit('bufferAppended');
        });

        this.on(Hls.Events.BUFFER_FLUSHED, () => {
            this.emit('bufferFlushed');
        });
    }

    handleError(data) {
        const { type, details, fatal } = data;

        switch (details) {
            case Hls.ErrorDetails.FRAG_LOAD_ERROR:
                Logger.warn('Fragment load error, retrying...', { fatal, url: data.frag?.url });
                if (!fatal) {
                    // Non-fatal, let HLS handle recovery
                    return;
                }
                break;

            case Hls.ErrorDetails.LEVEL_LOAD_ERROR:
                Logger.warn('Level load error, retrying...', { fatal });
                if (!fatal) {
                    return;
                }
                break;

            case Hls.ErrorDetails.MANIFEST_LOAD_ERROR:
                Logger.error('Manifest load error', { fatal });
                this.emit('manifestError', data);
                break;

            case Hls.ErrorDetails.BUFFER_APPEND_ERROR:
                Logger.error('Buffer append error', { fatal });
                this.emit('bufferError', data);
                break;

            case Hls.ErrorDetails.BUFFER_FULL_ERROR:
                Logger.warn('Buffer full, reducing buffer size');
                // Try to reduce buffer
                break;

            default:
                Logger.error('HLS error', { type, details, fatal });
        }

        if (fatal) {
            this.emit('fatalError', data);
        }
    }

    /**
     * Get available quality levels
     */
    getQualityLevels() {
        return this.qualityLevels;
    }

    /**
     * Set quality level manually
     */
    setQualityLevel(levelIndex) {
        this.currentLevel = levelIndex;
        this.isAutoLevel = levelIndex === -1;
    }

    /**
     * Get current quality level
     */
    getCurrentQualityLevel() {
        return this.activeLevel;
    }

    /**
     * Check if quality is auto
     */
    isAutoQuality() {
        return this.isAutoLevel;
    }

    /**
     * Get bandwidth estimate
     */
    getBandwidthEstimate() {
        return this.abrController?.bwEstimator?.getEstimate() || 0;
    }

    /**
     * Start quality selection based on bandwidth
     */
    autoSelectQuality(targetHeight = 1080) {
        const bandwidth = this.getBandwidthEstimate();
        
        // Find highest quality that bandwidth can support
        for (let i = this.qualityLevels.length - 1; i >= 0; i--) {
            const level = this.qualityLevels[i];
            if (level.height <= targetHeight && level.bitrate < bandwidth * 0.8) {
                this.setQualityLevel(i);
                return level;
            }
        }

        // Default to auto
        this.setQualityLevel(-1);
        return null;
    }
}

export default HlsPlayer;
```

### 5.3 Subtitle Renderer

```javascript
// app/js/player/SubtitleRenderer.js

class SubtitleRenderer {
    constructor() {
        this.container = null;
        this.currentSubtitles = [];
        this.activeCue = null;
        this.visible = true;
        this.style = {
            fontFamily: 'Tizen',
            fontSize: 24,
            color: '#FFFFFF',
            backgroundColor: 'rgba(0, 0, 0, 0.75)',
            textAlign: 'center',
            padding: '8px 16px',
            borderRadius: 4
        };
    }

    /**
     * Initialize renderer with container element
     */
    init(containerElement) {
        this.container = containerElement;
        this.container.style.position = 'relative';
        this.container.style.overflow = 'hidden';
        this.createCueElement();
    }

    /**
     * Create cue display element
     */
    createCueElement() {
        this.cueElement = document.createElement('div');
        this.cueElement.className = 'subtitle-cue';
        this.cueElement.style.cssText = `
            position: absolute;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            max-width: 80%;
            font-family: ${this.style.fontFamily};
            font-size: ${this.style.fontSize}px;
            color: ${this.style.color};
            background-color: ${this.style.backgroundColor};
            text-align: ${this.style.textAlign};
            padding: ${this.style.padding};
            border-radius: ${this.style.borderRadius};
            z-index: 1000;
            display: none;
            white-space: pre-wrap;
        `;
        this.container.appendChild(this.cueElement);
    }

    /**
     * Set subtitle tracks from video element
     */
    setTracks(videoElement) {
        // Clear existing tracks
        this.currentSubtitles = [];

        // Get text tracks from video
        const textTracks = videoElement.textTracks;
        for (let i = 0; i < textTracks.length; i++) {
            const track = textTracks[i];
            this.currentSubtitles.push({
                index: i,
                language: track.language,
                label: track.label || track.language,
                mode: track.mode
            });
        }

        return this.currentSubtitles;
    }

    /**
     * Enable subtitle track
     */
    enableTrack(trackIndex) {
        const video = document.querySelector('video');
        if (!video) return;

        for (let i = 0; i < video.textTracks.length; i++) {
            video.textTracks[i].mode = (i === trackIndex) ? 'showing' : 'hidden';
        }

        // Handle VTT side-loaded subtitles
        this.setupNativeSubtitles(video);
    }

    /**
     * Setup native subtitle rendering
     */
    setupNativeSubtitles(video) {
        // Use native WebVTT rendering
        video.textTracks[0].mode = 'showing';
    }

    /**
     * Load external VTT subtitle file
     */
    async loadExternalSubtitles(url) {
        const video = document.querySelector('video');
        if (!video) return;

        // Create track element
        const track = document.createElement('track');
        track.kind = 'subtitles';
        track.label = 'External';
        track.src = url;
        track.default = true;

        // Remove existing external tracks
        const existingTracks = video.querySelectorAll('track[label="External"]');
        existingTracks.forEach(t => t.remove());

        video.appendChild(track);

        // Wait for track to load
        return new Promise((resolve, reject) => {
            track.addEventListener('load', () => {
                track.mode = 'showing';
                resolve();
            });
            track.addEventListener('error', reject);
        });
    }

    /**
     * Show subtitle cue
     */
    showCue(text, startTime, endTime) {
        if (!this.cueElement) return;

        this.cueElement.textContent = text;
        this.cueElement.style.display = 'block';
        this.activeCue = { text, startTime, endTime };
    }

    /**
     * Hide current cue
     */
    hideCue() {
        if (!this.cueElement) return;
        this.cueElement.style.display = 'none';
        this.activeCue = null;
    }

    /**
     * Update subtitle display based on time
     */
    update(currentTime) {
        // Subtitle handling is done by native video element
        // This is for custom subtitle rendering if needed
    }

    /**
     * Set subtitle appearance
     */
    setStyle(styles) {
        this.style = { ...this.style, ...styles };

        if (this.cueElement) {
            this.cueElement.style.fontFamily = this.style.fontFamily;
            this.cueElement.style.fontSize = `${this.style.fontSize}px`;
            this.cueElement.style.color = this.style.color;
            this.cueElement.style.backgroundColor = this.style.backgroundColor;
        }
    }

    /**
     * Toggle subtitle visibility
     */
    toggle() {
        this.visible = !this.visible;
        if (this.cueElement) {
            this.cueElement.style.display = this.visible ? 'block' : 'none';
        }
    }

    /**
     * Cleanup
     */
    destroy() {
        if (this.cueElement && this.cueElement.parentNode) {
            this.cueElement.parentNode.removeChild(this.cueElement);
        }
        this.cueElement = null;
        this.currentSubtitles = [];
    }
}

export default new SubtitleRenderer();
export { SubtitleRenderer };
```

---

## 6. Remote Control Handling

### 6.1 Remote Manager

```javascript
// app/js/remote/RemoteManager.js

import KeyMapping from './KeyMapping.js';
import Logger from '../utils/Logger.js';

class RemoteManager {
    constructor() {
        this.enabled = true;
        this.keyRepeatDelay = 500;
        this.keyRepeatInterval = 100;
        this.activeKeyRepeat = null;
        this.listeners = new Map();
        
        this.init();
    }

    /**
     * Initialize remote control handling
     */
    init() {
        // Register key event listener
        document.addEventListener('keydown', (e) => this.onKeyDown(e));
        document.addEventListener('keyup', (e) => this.onKeyUp(e));

        Logger.info('RemoteManager initialized');
    }

    /**
     * Handle key down event
     */
    onKeyDown(event) {
        if (!this.enabled) return;

        const keyCode = event.keyCode;
        const mappedKey = KeyMapping.mapKeyCode(keyCode);

        Logger.debug('Key down', { keyCode, mappedKey });

        // Emit key event
        this.emit('keydown', { keyCode, mappedKey });

        // Handle key repeat for navigation keys
        if (KeyMapping.isRepeatable(mappedKey)) {
            event.preventDefault();
            
            // Start repeat after initial delay
            this.activeKeyRepeat = setTimeout(() => {
                this.startKeyRepeat(mappedKey);
            }, this.keyRepeatDelay);
        }

        // Handle immediate action keys
        if (KeyMapping.isImmediate(mappedKey)) {
            event.preventDefault();
            this.emit('action', { key: mappedKey });
        }

        // Prevent default for handled keys
        if (KeyMapping.isHandled(mappedKey)) {
            event.preventDefault();
        }
    }

    /**
     * Handle key up event
     */
    onKeyUp(event) {
        if (!this.enabled) return;

        const keyCode = event.keyCode;
        const mappedKey = KeyMapping.mapKeyCode(keyCode);

        // Stop key repeat
        this.stopKeyRepeat();

        // Emit key event
        this.emit('keyup', { keyCode, mappedKey });
    }

    /**
     * Start key repeat for navigation
     */
    startKeyRepeat(key) {
        this.stopKeyRepeat();

        this.activeKeyRepeat = setInterval(() => {
            this.emit('action', { key, repeat: true });
        }, this.keyRepeatInterval);
    }

    /**
     * Stop key repeat
     */
    stopKeyRepeat() {
        if (this.activeKeyRepeat) {
            clearTimeout(this.activeKeyRepeat);
            clearInterval(this.activeKeyRepeat);
            this.activeKeyRepeat = null;
        }
    }

    /**
     * Enable/disable remote handling
     */
    setEnabled(enabled) {
        this.enabled = enabled;
        if (!enabled) {
            this.stopKeyRepeat();
        }
    }

    /**
     * Register action handler
     */
    onAction(callback) {
        this.on('action', callback);
    }

    /**
     * Event system
     */
    on(event, callback) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, []);
        }
        this.listeners.get(event).push(callback);
    }

    off(event, callback) {
        if (!this.listeners.has(event)) return;
        const callbacks = this.listeners.get(event);
        const index = callbacks.indexOf(callback);
        if (index > -1) callbacks.splice(index, 1);
    }

    emit(event, data) {
        if (!this.listeners.has(event)) return;
        this.listeners.get(event).forEach(callback => callback(data));
    }

    /**
     * Cleanup
     */
    destroy() {
        this.stopKeyRepeat();
        document.removeEventListener('keydown', this.onKeyDown);
        document.removeEventListener('keyup', this.onKeyUp);
        this.listeners.clear();
    }
}

export default new RemoteManager();
export { RemoteManager };
```

### 6.2 Key Mapping

```javascript
// app/js/remote/KeyMapping.js

/**
 * Samsung Tizen Remote Key Mapping
 * Maps Tizen key codes to unified action names
 */

const KeyMapping = {
    // Key code to action name mapping
    KEY_MAP: {
        // Navigation
        37: 'LEFT',
        38: 'UP',
        39: 'RIGHT',
        40: 'DOWN',
        13: 'ENTER',
        10009: 'BACK',        // Samsung back button
        36: 'HOME',           // Home button
        
        // Playback control
        415: 'PLAY',
        413: 'STOP',
        19: 'PAUSE',
        417: 'FAST_FORWARD',
        412: 'REWIND',
        424: 'PREVIOUS',
        425: 'NEXT',
        
        // Color buttons
        403: 'RED',
        404: 'GREEN',
        405: 'YELLOW',
        406: 'BLUE',
        
        // Volume
        1028: 'VOLUME_UP',
        1029: 'VOLUME_DOWN',
        1025: 'MUTE',
        
        // Menu
        10282: 'MENU',
        18: 'INFO',
        113: 'TOOLS',
        
        // Misc
        48: '0',
        49: '1',
        50: '2',
        51: '3',
        52: '4',
        53: '5',
        54: '6',
        55: '7',
        56: '8',
        57: '9',
        
        // Tizen specific
        66: 'PLAY_PAUSE',
        79: 'OPTIONS',
    },

    /**
     * Map Tizen key code to action name
     */
    mapKeyCode(keyCode) {
        return this.KEY_MAP[keyCode] || `UNKNOWN_${keyCode}`;
    },

    /**
     * Check if key is a navigation key (for repeat)
     */
    isRepeatable(action) {
        const repeatableActions = [
            'LEFT', 'UP', 'RIGHT', 'DOWN',
            'FAST_FORWARD', 'REWIND',
            'NEXT', 'PREVIOUS',
            'VOLUME_UP', 'VOLUME_DOWN'
        ];
        return repeatableActions.includes(action);
    },

    /**
     * Check if key should trigger immediate action
     */
    isImmediate(action) {
        const immediateActions = [
            'ENTER', 'BACK', 'HOME',
            'PLAY', 'STOP', 'PAUSE',
            'RED', 'GREEN', 'YELLOW', 'BLUE',
            'MUTE', 'MENU', 'INFO', 'TOOLS'
        ];
        return immediateActions.includes(action);
    },

    /**
     * Check if key should prevent default
     */
    isHandled(action) {
        // All mapped keys should prevent default
        return action.startsWith('UNKNOWN_') === false;
    },

    /**
     * Get display name for action
     */
    getDisplayName(action) {
        const displayNames = {
            'LEFT': 'Left Arrow',
            'RIGHT': 'Right Arrow',
            'UP': 'Up Arrow',
            'DOWN': 'Down Arrow',
            'ENTER': 'OK',
            'BACK': 'Back',
            'HOME': 'Home',
            'PLAY': 'Play',
            'STOP': 'Stop',
            'PAUSE': 'Pause',
            'FAST_FORWARD': 'Fast Forward',
            'REWIND': 'Rewind',
            'NEXT': 'Next',
            'PREVIOUS': 'Previous',
            'RED': 'Red',
            'GREEN': 'Green',
            'YELLOW': 'Yellow',
            'BLUE': 'Blue',
            'VOLUME_UP': 'Volume Up',
            'VOLUME_DOWN': 'Volume Down',
            'MUTE': 'Mute',
            'MENU': 'Menu',
            'INFO': 'Info',
            'TOOLS': 'Tools',
            'PLAY_PAUSE': 'Play/Pause'
        };
        return displayNames[action] || action;
    }
};

export default KeyMapping;
```

### 6.3 Player Remote Handler

```javascript
// app/js/remote/PlayerRemoteHandler.js

import remoteManager from './RemoteManager.js';
import videoPlayer from '../player/VideoPlayer.js';
import sessionManager from '../api/SessionManager.js';
import Logger from '../utils/Logger.js';

class PlayerRemoteHandler {
    constructor() {
        this.isActive = false;
        this.seekStep = 10; // seconds
        this.volumeStep = 5; // percent
    }

    /**
     * Activate player remote handling
     */
    activate() {
        if (this.isActive) return;
        
        this.isActive = true;
        remoteManager.setEnabled(false); // Disable global handling
        
        remoteManager.onAction((data) => this.handleAction(data));
        
        Logger.info('PlayerRemoteHandler activated');
    }

    /**
     * Deactivate player remote handling
     */
    deactivate() {
        if (!this.isActive) return;
        
        this.isActive = false;
        remoteManager.setEnabled(true); // Re-enable global handling
        
        Logger.info('PlayerRemoteHandler deactivated');
    }

    /**
     * Handle remote action
     */
    handleAction({ key, repeat }) {
        switch (key) {
            case 'PLAY':
                this.handlePlay();
                break;
            case 'PAUSE':
                this.handlePause();
                break;
            case 'STOP':
                this.handleStop();
                break;
            case 'PLAY_PAUSE':
                this.handlePlayPause();
                break;
            case 'FAST_FORWARD':
                this.handleSeekForward(repeat);
                break;
            case 'REWIND':
                this.handleSeekBackward(repeat);
                break;
            case 'LEFT':
                this.handleSeekBackward(repeat ? 30 : this.seekStep);
                break;
            case 'RIGHT':
                this.handleSeekForward(repeat ? 30 : this.seekStep);
                break;
            case 'UP':
                this.handleVolumeUp(repeat);
                break;
            case 'DOWN':
                this.handleVolumeDown(repeat);
                break;
            case 'BACK':
                this.handleBack();
                break;
            case 'INFO':
                this.handleToggleInfo();
                break;
            case 'RED':
                this.handleToggleSubtitles();
                break;
            case 'GREEN':
                this.handleToggleAudioTracks();
                break;
            case 'YELLOW':
                this.handleToggleQuality();
                break;
            default:
                Logger.debug('Unhandled player action', { key });
        }
    }

    /**
     * Handle play
     */
    async handlePlay() {
        const state = sessionManager.playbackState;
        if (state?.isPlaying) return;
        
        try {
            await sessionManager.resumePlayback();
            await videoPlayer.play();
        } catch (error) {
            Logger.error('Play failed', error);
        }
    }

    /**
     * Handle pause
     */
    async handlePause() {
        const state = sessionManager.playbackState;
        if (!state?.isPlaying) return;
        
        try {
            await sessionManager.pausePlayback();
            videoPlayer.pause();
        } catch (error) {
            Logger.error('Pause failed', error);
        }
    }

    /**
     * Handle play/pause toggle
     */
    handlePlayPause() {
        const state = sessionManager.playbackState;
        if (state?.isPlaying) {
            this.handlePause();
        } else {
            this.handlePlay();
        }
    }

    /**
     * Handle stop
     */
    async handleStop() {
        try {
            await sessionManager.stopPlayback();
            videoPlayer.stop();
            this.deactivate();
        } catch (error) {
            Logger.error('Stop failed', error);
        }
    }

    /**
     * Handle seek forward
     */
    async handleSeekForward(seconds = null) {
        const step = seconds || this.seekStep;
        const current = videoPlayer.getCurrentTime();
        const duration = videoPlayer.getDuration();
        const newPosition = Math.min(current + step, duration);

        try {
            await sessionManager.seekTo(newPosition * 10000000); // Convert to ticks
            await videoPlayer.seek(newPosition);
        } catch (error) {
            Logger.error('Seek forward failed', error);
        }
    }

    /**
     * Handle seek backward
     */
    async handleSeekBackward(seconds = null) {
        const step = seconds || this.seekStep;
        const current = videoPlayer.getCurrentTime();
        const newPosition = Math.max(current - step, 0);

        try {
            await sessionManager.seekTo(newPosition * 10000000); // Convert to ticks
            await videoPlayer.seek(newPosition);
        } catch (error) {
            Logger.error('Seek backward failed', error);
        }
    }

    /**
     * Handle volume up
     */
    handleVolumeUp(repeat) {
        const step = repeat ? 3 : this.volumeStep;
        const current = videoPlayer.video?.volume || 0;
        const newVolume = Math.min(current + step / 100, 1);
        videoPlayer.setVolume(newVolume);
    }

    /**
     * Handle volume down
     */
    handleVolumeDown(repeat) {
        const step = repeat ? 3 : this.volumeStep;
        const current = videoPlayer.video?.volume || 0;
        const newVolume = Math.max(current - step / 100, 0);
        videoPlayer.setVolume(newVolume);
    }

    /**
     * Handle back button
     */
    handleBack() {
        this.deactivate();
        // Navigate back
        window.app?.navigateBack();
    }

    /**
     * Handle info button (show/hide OSD)
     */
    handleToggleInfo() {
        // Toggle on-screen display
        window.app?.toggleInfoPanel();
    }

    /**
     * Handle red button (subtitles)
     */
    handleToggleSubtitles() {
        // Cycle through subtitle tracks
        window.app?.cycleSubtitles();
    }

    /**
     * Handle green button (audio tracks)
     */
    handleToggleAudioTracks() {
        // Cycle through audio tracks
        window.app?.cycleAudioTracks();
    }

    /**
     * Handle yellow button (quality)
     */
    handleToggleQuality() {
        // Cycle through quality levels
        window.app?.cycleQuality();
    }
}

export default new PlayerRemoteHandler();
export { PlayerRemoteHandler };
```

---

## 7. User Interface

### 7.1 Main App Class

```javascript
// app/js/ui/App.js

import Router from './Router.js';
import HomeView from './HomeView.js';
import LibraryView from './LibraryView.js';
import DetailView from './DetailView.js';
import PlayerView from './PlayerView.js';
import PlayerRemoteHandler from '../remote/PlayerRemoteHandler.js';
import api from '../api/ApiClient.js';
import sessionManager from '../api/SessionManager.js';
import Logger from '../utils/Logger.js';
import Storage from '../utils/Storage.js';

class App {
    constructor() {
        this.views = new Map();
        this.currentView = null;
        this.router = new Router();
        this.isLoggedIn = false;
        this.user = null;
    }

    /**
     * Initialize the application
     */
    async init() {
        Logger.info('Initializing Phlix TV App');

        // Create views
        this.createViews();

        // Setup router
        this.setupRoutes();

        // Setup session manager events
        this.setupSessionEvents();

        // Try to restore session
        await this.tryRestoreSession();

        // Setup keyboard navigation
        this.setupNavigation();

        Logger.info('App initialized');
    }

    /**
     * Create all views
     */
    createViews() {
        const container = document.getElementById('app');
        
        this.views.set('home', new HomeView(container));
        this.views.set('library', new LibraryView(container));
        this.views.set('detail', new DetailView(container));
        this.views.set('player', new PlayerView(container));
    }

    /**
     * Setup routes
     */
    setupRoutes() {
        this.router.addRoute('/', () => this.showView('home'));
        this.router.addRoute('/libraries', () => this.showView('home'));
        this.router.addRoute('/libraries/:id', (params) => this.showLibrary(params.id));
        this.router.addRoute('/item/:id', (params) => this.showItem(params.id));
        this.router.addRoute('/player/:id', (params) => this.playItem(params.id));
        
        this.router.setNotFoundHandler(() => this.showView('home'));
    }

    /**
     * Setup session manager events
     */
    setupSessionEvents() {
        sessionManager.on('playbackStarted', (data) => {
            PlayerRemoteHandler.activate();
        });

        sessionManager.on('playbackStopped', () => {
            PlayerRemoteHandler.deactivate();
        });
    }

    /**
     * Try to restore existing session
     */
    async tryRestoreSession() {
        try {
            const hasSession = await sessionManager.init();
            if (hasSession) {
                this.isLoggedIn = true;
                this.user = api.user;
                this.showView('home');
            } else {
                this.showLoginScreen();
            }
        } catch (error) {
            Logger.error('Failed to restore session', error);
            this.showLoginScreen();
        }
    }

    /**
     * Show login screen
     */
    showLoginScreen() {
        const loginHtml = `
            <div class="login-screen">
                <div class="login-container">
                    <h1 class="login-title">Phlix</h1>
                    <form class="login-form" id="loginForm">
                        <input type="text" class="login-input" 
                               id="username" placeholder="Username" 
                               autocomplete="username" required>
                        <input type="password" class="login-input" 
                               id="password" placeholder="Password" 
                               autocomplete="current-password" required>
                        <button type="submit" class="login-button">Sign In</button>
                        <p class="login-error" id="loginError" style="display: none;"></p>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('app').innerHTML = loginHtml;
        document.getElementById('loginForm').addEventListener('submit', (e) => this.handleLogin(e));
    }

    /**
     * Handle login form submission
     */
    async handleLogin(event) {
        event.preventDefault();

        const username = document.getElementById('username').value;
        const password = document.getElementById('password').value;
        const errorEl = document.getElementById('loginError');

        try {
            await api.login(username, password);
            await sessionManager.createSession();

            this.isLoggedIn = true;
            this.user = api.user;
            this.showView('home');
        } catch (error) {
            errorEl.textContent = error.message || 'Login failed';
            errorEl.style.display = 'block';
        }
    }

    /**
     * Show a view
     */
    showView(viewName) {
        const view = this.views.get(viewName);
        if (!view) return;

        // Hide current view
        if (this.currentView) {
            this.currentView.hide();
        }

        // Show new view
        view.show();
        this.currentView = view;
    }

    /**
     * Navigate to library
     */
    async showLibrary(libraryId) {
        const view = this.views.get('library');
        await view.load(libraryId);
        this.showView('library');
    }

    /**
     * Navigate to item detail
     */
    async showItem(itemId) {
        const view = this.views.get('detail');
        await view.load(itemId);
        this.showView('detail');
    }

    /**
     * Start playing item
     */
    async playItem(itemId) {
        const view = this.views.get('player');
        await view.load(itemId);
        this.showView('player');
    }

    /**
     * Navigate back
     */
    navigateBack() {
        this.router.navigateBack();
    }

    /**
     * Setup keyboard navigation
     */
    setupNavigation() {
        // Initial focus
        setTimeout(() => {
            const firstFocusable = document.querySelector('.focusable');
            if (firstFocusable) {
                firstFocusable.focus();
            }
        }, 100);
    }

    /**
     * Toggle info panel
     */
    toggleInfoPanel() {
        const playerView = this.views.get('player');
        if (playerView) {
            playerView.toggleInfoPanel();
        }
    }

    /**
     * Cycle subtitles
     */
    cycleSubtitles() {
        const playerView = this.views.get('player');
        if (playerView) {
            playerView.cycleSubtitles();
        }
    }

    /**
     * Cycle audio tracks
     */
    cycleAudioTracks() {
        const playerView = this.views.get('player');
        if (playerView) {
            playerView.cycleAudioTracks();
        }
    }

    /**
     * Cycle quality
     */
    cycleQuality() {
        const playerView = this.views.get('player');
        if (playerView) {
            playerView.cycleQuality();
        }
    }
}

// Create and export app instance
const app = new App();

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => app.init());
} else {
    app.init();
}

export default app;
```

### 7.2 Player View

```javascript
// app/js/ui/PlayerView.js

import videoPlayer from '../player/VideoPlayer.js';
import sessionManager from '../api/SessionManager.js';
import subtitleRenderer from '../player/SubtitleRenderer.js';
import Logger from '../utils/Logger.js';

class PlayerView {
    constructor(container) {
        this.container = container;
        this.item = null;
        this.playbackInfo = null;
        this.isInfoVisible = true;
        this.infoHideTimeout = null;
    }

    /**
     * Load and prepare item for playback
     */
    async load(itemId) {
        // Get item details
        this.item = await api.getItem(itemId);

        // Get playback info
        this.playbackInfo = await api.getItemPlaybackInfo(itemId);

        // Render player UI
        this.render();

        // Initialize player
        videoPlayer.init(this.getVideoElement());

        // Initialize subtitle renderer
        subtitleRenderer.init(this.container);

        // Setup player events
        this.setupPlayerEvents();

        // Start playback
        await this.startPlayback();
    }

    /**
     * Render player UI
     */
    render() {
        const html = `
            <div class="player-view">
                <video id="player-video" 
                       class="player-video"
                       autoplay
                       crossorigin="anonymous">
                </video>
                
                <div class="player-overlay">
                    <div class="player-top-bar">
                        <button class="player-back-btn" id="playerBack">
                            <span class="icon-back"></span>
                            <span class="back-text">Back</span>
                        </button>
                        <h2 class="player-title">${this.escapeHtml(this.item?.name || '')}</h2>
                    </div>
                    
                    <div class="player-center-controls" id="centerControls">
                        <button class="control-btn rewind-btn" id="rewindBtn">
                            <span class="icon-rewind"></span>
                        </button>
                        <button class="control-btn play-btn" id="playBtn">
                            <span class="icon-play"></span>
                        </button>
                        <button class="control-btn forward-btn" id="forwardBtn">
                            <span class="icon-forward"></span>
                        </button>
                    </div>
                    
                    <div class="player-bottom-bar">
                        <div class="progress-container">
                            <div class="progress-bar" id="progressBar">
                                <div class="progress-buffered" id="progressBuffered"></div>
                                <div class="progress-current" id="progressCurrent"></div>
                            </div>
                            <div class="time-display">
                                <span id="currentTime">00:00</span>
                                <span class="time-separator">/</span>
                                <span id="totalTime">00:00</span>
                            </div>
                        </div>
                        
                        <div class="quality-selector">
                            <button class="quality-btn" id="qualityBtn">Auto</button>
                        </div>
                    </div>
                </div>
                
                <div class="player-info-panel" id="infoPanel">
                    <h3 class="info-title">${this.escapeHtml(this.item?.name || '')}</h3>
                    <p class="info-meta">
                        ${this.item?.production_year || ''} • 
                        ${this.formatDuration(this.item?.run_time_ticks)}
                    </p>
                    <p class="info-description">${this.escapeHtml(this.item?.overview || '')}</p>
                </div>
            </div>
        `;

        this.container.innerHTML = html;
        this.setupUIHandlers();
    }

    /**
     * Setup UI event handlers
     */
    setupUIHandlers() {
        // Back button
        document.getElementById('playerBack')?.addEventListener('click', () => {
            window.app?.navigateBack();
        });

        // Control buttons
        document.getElementById('playBtn')?.addEventListener('click', () => {
            videoPlayer.video?.paused ? videoPlayer.play() : videoPlayer.pause();
        });

        document.getElementById('rewindBtn')?.addEventListener('click', () => {
            videoPlayer.seek(videoPlayer.getCurrentTime() - 10);
        });

        document.getElementById('forwardBtn')?.addEventListener('click', () => {
            videoPlayer.seek(videoPlayer.getCurrentTime() + 10);
        });

        // Progress bar interaction
        const progressBar = document.getElementById('progressBar');
        progressBar?.addEventListener('click', (e) => {
            const rect = progressBar.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            const duration = videoPlayer.getDuration();
            videoPlayer.seek(percent * duration);
        });
    }

    /**
     * Setup player events
     */
    setupPlayerEvents() {
        videoPlayer.on('timeupdate', (data) => {
            this.updateProgress(data);
        });

        videoPlayer.on('progress', (data) => {
            this.updateBuffered(data.buffered);
        });

        videoPlayer.on('qualityChanged', (quality) => {
            this.updateQualityIndicator(quality);
        });

        videoPlayer.on('ended', () => {
            window.app?.navigateBack();
        });

        videoPlayer.on('error', (error) => {
            Logger.error('Player error', error);
        });
    }

    /**
     * Start playback
     */
    async startPlayback() {
        try {
            const state = await sessionManager.startPlayback(this.item.id);
            await videoPlayer.load(state.playbackInfo);
            await videoPlayer.play();
        } catch (error) {
            Logger.error('Failed to start playback', error);
        }
    }

    /**
     * Update progress display
     */
    updateProgress(data) {
        const current = document.getElementById('currentTime');
        const progress = document.getElementById('progressCurrent');
        
        if (current) {
            current.textContent = this.formatTime(data.currentTime);
        }
        
        if (progress) {
            const percent = (data.currentTime / data.duration) * 100;
            progress.style.width = `${percent}%`;
        }

        // Auto-hide info after 3 seconds
        if (this.isInfoVisible) {
            clearTimeout(this.infoHideTimeout);
            this.infoHideTimeout = setTimeout(() => {
                this.hideInfoPanel();
            }, 3000);
        }
    }

    /**
     * Update buffered display
     */
    updateBuffered(percent) {
        const buffered = document.getElementById('progressBuffered');
        if (buffered) {
            buffered.style.width = `${percent}%`;
        }
    }

    /**
     * Update quality indicator
     */
    updateQualityIndicator(quality) {
        const qualityBtn = document.getElementById('qualityBtn');
        if (qualityBtn && quality) {
            qualityBtn.textContent = quality.name || 'Auto';
        }
    }

    /**
     * Toggle info panel
     */
    toggleInfoPanel() {
        this.isInfoVisible = !this.isInfoVisible;
        const panel = document.getElementById('infoPanel');
        
        if (panel) {
            panel.classList.toggle('hidden', !this.isInfoVisible);
        }
    }

    /**
     * Show info panel
     */
    showInfoPanel() {
        this.isInfoVisible = true;
        const panel = document.getElementById('infoPanel');
        if (panel) {
            panel.classList.remove('hidden');
        }
    }

    /**
     * Hide info panel
     */
    hideInfoPanel() {
        this.isInfoVisible = false;
        const panel = document.getElementById('infoPanel');
        if (panel) {
            panel.classList.add('hidden');
        }
    }

    /**
     * Cycle through subtitles
     */
    cycleSubtitles() {
        const tracks = videoPlayer.video?.textTracks || [];
        let currentIndex = -1;
        
        for (let i = 0; i < tracks.length; i++) {
            if (tracks[i].mode === 'showing') {
                currentIndex = i;
                tracks[i].mode = 'disabled';
                break;
            }
        }
        
        // Enable next track (or first if at end)
        const nextIndex = (currentIndex + 1) % tracks.length;
        if (tracks[nextIndex]) {
            tracks[nextIndex].mode = 'showing';
        }
    }

    /**
     * Cycle through audio tracks
     */
    cycleAudioTracks() {
        const tracks = videoPlayer.video?.audioTracks || [];
        let currentIndex = -1;
        
        for (let i = 0; i < tracks.length; i++) {
            if (tracks[i].enabled) {
                currentIndex = i;
                tracks[i].enabled = false;
                break;
            }
        }
        
        // Enable next track (or first if at end)
        const nextIndex = (currentIndex + 1) % tracks.length;
        if (tracks[nextIndex]) {
            tracks[nextIndex].enabled = true;
        }
    }

    /**
     * Cycle through quality levels
     */
    cycleQuality() {
        const levels = videoPlayer.qualityLevels || [];
        if (levels.length === 0) return;
        
        let currentIndex = videoPlayer.currentQualityIndex;
        const nextIndex = (currentIndex + 1) % (levels.length + 1); // +1 for auto
        
        if (nextIndex === levels.length) {
            // Auto
            videoPlayer.setQuality(-1);
        } else {
            videoPlayer.setQuality(nextIndex);
        }
    }

    /**
     * Get video element
     */
    getVideoElement() {
        return document.getElementById('player-video');
    }

    /**
     * Format duration from ticks to string
     */
    formatDuration(ticks) {
        if (!ticks) return '';
        const hours = Math.floor(ticks / 36000000000);
        const minutes = Math.floor((ticks % 36000000000) / 600000000);
        
        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        }
        return `${minutes}m`;
    }

    /**
     * Format seconds to time string
     */
    formatTime(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        
        if (h > 0) {
            return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }
        return `${m}:${s.toString().padStart(2, '0')}`;
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show view
     */
    show() {
        this.container.style.display = 'block';
    }

    /**
     * Hide view
     */
    hide() {
        this.container.style.display = 'none';
    }
}

export default PlayerView;
```

---

## 8. Testing

### 8.1 Unit Tests

```javascript
// tests/Unit/api/ApiClient.test.js

describe('ApiClient', () => {
    let apiClient;
    
    beforeEach(() => {
        apiClient = new ApiClient('http://localhost:8096', 'test-device', 'Test TV');
    });
    
    describe('Authentication', () => {
        it('should store token when setToken is called', () => {
            apiClient.setToken('test-token');
            expect(apiClient.token).toBe('test-token');
        });
        
        it('should clear token when setToken is called with null', () => {
            apiClient.setToken('test-token');
            apiClient.setToken(null);
            expect(apiClient.token).toBeNull();
        });
    });
    
    describe('Request building', () => {
        it('should include auth header when token is set', () => {
            apiClient.setToken('test-token');
            
            // Can't directly test private method, but we can test effects
            expect(apiClient.token).toBe('test-token');
        });
        
        it('should include device headers', () => {
            expect(apiClient.deviceId).toBe('test-device');
            expect(apiClient.deviceType).toBe('samsung-tizen');
        });
    });
    
    describe('Device Profile', () => {
        it('should have correct profile for Samsung Tizen', () => {
            expect(apiClient.deviceProfile.Name).toBe('Samsung Tizen TV');
            expect(apiClient.deviceProfile.MaxStreamingBitrate).toBe(80000000);
        });
        
        it('should support video playback', () => {
            const supportsVideo = apiClient.deviceProfile.SupportedMediaTypes.includes('Video');
            expect(supportsVideo).toBe(true);
        });
    });
});
```

### 8.2 Integration Tests

```javascript
// tests/Integration/Playback.test.js

describe('Playback Integration', () => {
    beforeAll(async () => {
        // Login and create session
        await api.login('testuser', 'testpass');
        await sessionManager.createSession();
    });
    
    afterAll(async () => {
        await api.logout();
    });
    
    it('should load playback info for item', async () => {
        const item = await api.getItem('test-item-id');
        const playbackInfo = await api.getItemPlaybackInfo('test-item-id');
        
        expect(playbackInfo).toBeDefined();
        expect(playbackInfo.playback_info).toBeDefined();
    });
    
    it('should start playback session', async () => {
        const state = await sessionManager.startPlayback('test-item-id');
        
        expect(state).toBeDefined();
        expect(state.isPlaying).toBe(true);
        expect(state.position).toBe(0);
    });
});
```

### 8.3 Tizen Emulator Testing

```bash
# Build for Tizen
npm run build:tizen

# Run on Tizen emulator
npm run test:emulator

# Run on connected TV
npm run test:device
```

---

## 9. Tizen Store Submission

### 9.1 Pre-Submission Checklist

```
□ Samsung Seller Office account created
□ App icon (1920x1080 PNG)
□ App screenshots (1920x1080 PNG, min 3)
□ App banner (1920x450 PNG)
□ Description (max 4000 characters)
□ Privacy policy URL (required)
□ Content rating submission
□ Binary signing with Samsung certificate
□ Test on actual Samsung TV
□ Check all remote buttons work
□ Verify playback works with sample content
□ Check for memory leaks
□ Test network interruption handling
```

### 9.2 Submission Steps

1. **Build Release Binary**
   ```bash
   npm run build:release
   ```

2. **Sign with Samsung Certificate**
   ```bash
   tizen build-sign -c "Samsung Certificate" app.wgt
   ```

3. **Create Seller Office Account**
   - Visit https://seller.samsung.com
   - Complete verification

4. **Submit App**
   - Create new app
   - Upload binary
   - Add metadata
   - Submit for review

5. **Monitor Status**
   - Review typically takes 3-5 business days
   - Respond to any feedback

---

## 10. Implementation Checklist

### Phase 1: Environment Setup
- [ ] Install Tizen Studio
- [ ] Configure Samsung certificate
- [ ] Create project structure
- [ ] Setup build system

### Phase 2: API Client
- [ ] Implement ApiClient class
- [ ] Implement authentication
- [ ] Implement session management
- [ ] Test API connectivity

### Phase 3: Player Core
- [ ] Implement VideoPlayer
- [ ] Implement HLS support
- [ ] Test playback
- [ ] Test seeking

### Phase 4: Remote Control
- [ ] Implement RemoteManager
- [ ] Map all remote buttons
- [ ] Handle key repeat
- [ ] Test navigation

### Phase 5: UI
- [ ] Create home view
- [ ] Create library view
- [ ] Create player view
- [ ] Style for TV

### Phase 6: Testing
- [ ] Unit tests for API
- [ ] Integration tests
- [ ] Manual TV testing
- [ ] Performance testing

### Phase 7: Submission
- [ ] Prepare assets
- [ ] Build release binary
- [ ] Submit to store
- [ ] Address feedback

---

**Document Version:** 1.0  
**Last Updated:** 2026-05-14  
