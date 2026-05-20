# Phlix Media Server - Windows Desktop Application

**Document Version:** 1.0  
**Platform:** Windows 10/11  
**Technology:** Electron + React + TypeScript  

---

## Table of Contents

1. [Overview](#1-overview)
2. [Development Environment](#2-development-environment)
3. [Project Structure](#3-project-structure)
4. [API Client Implementation](#4-api-client-implementation)
5. [Player Implementation](#5-player-implementation)
6. [System Integration](#6-system-integration)
7. [User Interface](#7-user-interface)
8. [Testing](#8-testing)
9. [Microsoft Store Submission](#9-microsoft-store-submission)
10. [Implementation Checklist](#10-implementation-checklist)

---

## 1. Overview

### 1.1 Platform Capabilities

Windows Desktop supports:
- **Video Codecs**: All major codecs via Windows Media Foundation and DirectX Video Acceleration
- **Audio Codecs**: AAC, AC3, EAC3, DTS, FLAC, MP3, WMA, and more
- **Containers**: MP4, MKV, AVI, MOV, WMV, WebM, TS
- **Streaming**: HLS, DASH, HTTP Progressive
- **Features**: 4K HDR, Dolby Atmos, multi-channel audio, hardware acceleration

### 1.2 Technology Stack

| Component | Technology |
|-----------|------------|
| Framework | Electron 30+ |
| UI | React 18+ with TypeScript |
| Video Player | Native HTML5 Video + video.js |
| State Management | Zustand |
| HTTP Client | Axios |
| Build Tool | Vite |
| Packaging | electron-builder |
| Native Modules | node native modules (if needed) |

---

## 2. Development Environment

### 2.1 Required Software

```
1. Node.js 20+ (LTS recommended)
2. Visual Studio 2022 (for Windows SDK)
3. Git
4. Code editor (VS Code recommended)
5. Windows 10/11 for testing
```

### 2.2 Environment Setup

```bash
# Create project directory
mkdir phlix-windows
cd phlix-windows

# Initialize npm
npm init -y

# Install Electron and build tools
npm install --save-dev electron@30 electron-builder@24

# Install React and UI dependencies
npm install react@18 react-dom@18 zustand axios
npm install --save-dev typescript@5 @types/react @types/react-dom vite@5 @vitejs/plugin-react

# Install Electron-specific tools
npm install --save-dev electron-log electron-store wait-on

# Create project structure
mkdir -p src/main src/renderer/components src/renderer/pages src/renderer/hooks src/renderer/utils src/preload tests
```

### 2.3 Project Configuration Files

```json
// package.json
{
  "name": "phlix-windows",
  "version": "1.0.0",
  "description": "Phlix Media Server Client for Windows",
  "main": "dist/main/index.js",
  "scripts": {
    "dev": "concurrently \"npm run dev:vite\" \"npm run dev:electron\"",
    "dev:vite": "vite",
    "dev:electron": "wait-on http://localhost:5173 && electron .",
    "build": "npm run build:vite && npm run build:electron",
    "build:vite": "vite build",
    "build:electron": "tsc -p tsconfig.main.json",
    "package": "npm run build && electron-builder --win --publish never",
    "package:store": "npm run build && electron-builder --win appx"
  },
  "build": {
    "appId": "app.phlix.windows",
    "productName": "Phlix",
    "directories": {
      "output": "release"
    },
    "win": {
      "target": [
        {
          "target": "nsis",
          "arch": ["x64"]
        },
        {
          "target": "appx",
          "arch": ["x64"]
        }
      ],
      "icon": "build/icon.ico"
    },
    "nsis": {
      "oneClick": false,
      "perMachine": true,
      "allowToChangeInstallationDirectory": true
    },
    "appx": {
      "identityName": "Phlix",
      "publisher": "CN=Phlix",
      "publisherDisplayName": "Phlix",
      "applicationId": "Phlix"
    }
  }
}
```

```typescript
// tsconfig.json
{
  "compilerOptions": {
    "target": "ES2020",
    "useDefineForClassFields": true,
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noFallthroughCasesInSwitch": true
  },
  "include": ["src/renderer"],
  "references": [{ "path": "./tsconfig.main.json" }]
}
```

```typescript
// tsconfig.main.json (for Electron main process)
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "CommonJS",
    "lib": ["ES2020"],
    "outDir": "dist/main",
    "rootDir": "src/main",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "resolveJsonModule": true
  },
  "include": ["src/main/**/*", "src/preload/**/*"]
}
```

---

## 3. Project Structure

```
phlix-windows/
├── src/
│   ├── main/
│   │   ├── index.ts           # Electron main process entry
│   │   ├── WindowManager.ts   # Window management
│   │   ├── MenuBuilder.ts     # Application menu
│   │   ├── TrayManager.ts     # System tray
│   │   ├── AutoUpdater.ts     # Auto-update handling
│   │   └── ipc/
│   │       └── handlers.ts    # IPC message handlers
│   ├── preload/
│   │   └── index.ts          # Preload script (context bridge)
│   └── renderer/
│       ├── index.html         # HTML entry
│       ├── main.tsx           # React entry
│       ├── App.tsx            # Root component
│       ├── pages/
│       │   ├── Home.tsx
│       │   ├── Library.tsx
│       │   ├── ItemDetail.tsx
│       │   ├── Player.tsx
│       │   └── Settings.tsx
│       ├── components/
│       │   ├── VideoPlayer.tsx
│       │   ├── MediaGrid.tsx
│       │   ├── Sidebar.tsx
│       │   ├── Header.tsx
│       │   └── ...
│       ├── hooks/
│       │   ├── useApi.ts
│       │   ├── usePlayback.ts
│       │   ├── useAuth.ts
│       │   └── ...
│       ├── stores/
│       │   ├── authStore.ts
│       │   ├── playbackStore.ts
│       │   └── uiStore.ts
│       ├── utils/
│       │   ├── api.ts
│       │   ├── format.ts
│       │   └── ...
│       └── styles/
│           ├── global.css
│           └── theme.ts
├── build/
│   └── icon.ico
├── tests/
│   ├── unit/
│   └── e2e/
├── electron-builder.json
├── vite.config.ts
└── tsconfig.json
```

---

## 4. API Client Implementation

### 4.1 API Client

```typescript
// src/renderer/utils/api.ts

import axios, { AxiosInstance, AxiosError } from 'axios';

export interface DeviceProfile {
  Name: string;
  MaxStreamingBitrate: number;
  MaxStaticBitrate: number;
  SupportedMediaTypes: string[];
  DirectPlayProfiles: Array<{
    Container: string;
    Type: string;
    VideoCodec?: string;
    AudioCodec?: string;
  }>;
  TranscodingProfiles: Array<{
    Container: string;
    Type: string;
    VideoCodec: string;
    AudioCodec: string;
  }>;
}

export interface ApiClientConfig {
  baseUrl: string;
  deviceId: string;
  deviceName: string;
}

class ApiClient {
  private client: AxiosInstance;
  private token: string | null = null;
  private sessionId: string | null = null;
  private user: User | null = null;

  private deviceProfile: DeviceProfile = {
    Name: 'Windows Desktop',
    MaxStreamingBitrate: 100000000,
    MaxStaticBitrate: 100000000,
    SupportedMediaTypes: ['Video', 'Audio', 'Photo'],
    DirectPlayProfiles: [{
      Container: '*',
      Type: 'Video',
      VideoCodec: '*',
      AudioCodec: '*'
    }],
    TranscodingProfiles: [{
      Container: 'mp4',
      Type: 'Video',
      VideoCodec: 'h264',
      AudioCodec: 'aac'
    }]
  };

  constructor(config: ApiClientConfig) {
    this.client = axios.create({
      baseURL: `${config.baseUrl}/api/v1`,
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json',
        'X-Phlix-Device-ID': config.deviceId,
        'X-Phlix-Device-Name': config.deviceName,
        'X-Phlix-Device-Type': 'windows'
      }
    });

    // Request interceptor
    this.client.interceptors.request.use((config) => {
      if (this.token) {
        config.headers.Authorization = `Bearer ${this.token}`;
      }
      if (this.sessionId) {
        config.headers['X-Phlix-Session-ID'] = this.sessionId;
      }
      return config;
    });

    // Response interceptor
    this.client.interceptors.response.use(
      (response) => response,
      (error: AxiosError) => {
        if (error.response?.status === 401) {
          this.handleUnauthorized();
        }
        return Promise.reject(error);
      }
    );
  }

  // Auth methods
  setToken(token: string | null): void {
    this.token = token;
    if (token) {
      localStorage.setItem('auth_token', token);
    } else {
      localStorage.removeItem('auth_token');
    }
  }

  setSession(sessionId: string | null): void {
    this.sessionId = sessionId;
    if (sessionId) {
      localStorage.setItem('session_id', sessionId);
    } else {
      localStorage.removeItem('session_id');
    }
  }

  async restoreSession(): Promise<boolean> {
    const token = localStorage.getItem('auth_token');
    const sessionId = localStorage.getItem('session_id');

    if (token) {
      this.token = token;
      if (sessionId) this.sessionId = sessionId;

      try {
        const result = await this.request<User>('GET', '/Users/Me');
        this.user = result;
        return true;
      } catch {
        this.setToken(null);
        this.setSession(null);
      }
    }
    return false;
  }

  async login(username: string, password: string): Promise<AuthResult> {
    const result = await this.request<AuthResult>('POST', '/Auth/Login', {
      username,
      password,
      device_id: this.getDeviceId(),
      device_name: this.deviceProfile.Name,
      device_type: 'windows'
    });

    this.setToken(result.token);
    this.setSession(result.session_id);
    this.user = result.user;
    return result;
  }

  logout(): void {
    try {
      if (this.sessionId) {
        this.request('DELETE', `/Sessions/${this.sessionId}`, {});
      }
    } finally {
      this.setToken(null);
      this.setSession(null);
      this.user = null;
    }
  }

  // Session methods
  async createSession(): Promise<Session> {
    const result = await this.request<Session>('POST', '/Sessions', {
      device_id: this.getDeviceId(),
      device_name: this.deviceProfile.Name,
      device_type: 'windows',
      capabilities: this.deviceProfile
    });
    this.setSession(result.id);
    return result;
  }

  // Library methods
  async getLibraries(): Promise<Library[]> {
    return this.request<Library[]>('GET', '/Library/VirtualFolders', {});
  }

  async getLibraryItems(
    libraryId: string,
    options: {
      type?: string;
      limit?: number;
      startIndex?: number;
    } = {}
  ): Promise<MediaItemsResponse> {
    const params = new URLSearchParams({
      parentId: libraryId,
      includeItemTypes: options.type || 'Movie,Series',
      limit: String(options.limit || 50),
      startIndex: String(options.startIndex || 0),
      sortBy: 'SortName',
      sortOrder: 'Ascending'
    });

    return this.request<MediaItemsResponse>('GET', `/Items?${params}`, {});
  }

  async getItem(itemId: string): Promise<MediaItem> {
    return this.request<MediaItem>('GET', `/Items/${itemId}`, {});
  }

  async getItemPlaybackInfo(itemId: string): Promise<PlaybackInfoResponse> {
    const params = new URLSearchParams({
      deviceProfile: 'windows',
      maxStreamingBitrate: String(this.deviceProfile.MaxStreamingBitrate)
    });

    return this.request<PlaybackInfoResponse>(
      'GET',
      `/Items/${itemId}/PlaybackInfo?${params}`,
      {}
    );
  }

  // Playback control
  async playItem(
    itemId: string,
    options: { startPosition?: number } = {}
  ): Promise<PlaybackStartResponse> {
    return this.request<PlaybackStartResponse>('POST', '/Sessions/Play', {
      item_id: itemId,
      start_position_ticks: options.startPosition || 0,
      device_profile: 'windows'
    });
  }

  async stopPlayback(): Promise<void> {
    await this.request('POST', '/Playstate', {
      session_id: this.sessionId,
      command: 'stop'
    });
  }

  async pausePlayback(): Promise<void> {
    await this.request('POST', '/Playstate', {
      session_id: this.sessionId,
      command: 'pause'
    });
  }

  async resumePlayback(): Promise<void> {
    await this.request('POST', '/Playstate', {
      session_id: this.sessionId,
      command: 'play'
    });
  }

  async seekPlayback(positionTicks: number): Promise<void> {
    await this.request('POST', '/Playstate', {
      session_id: this.sessionId,
      command: 'seek',
      data: { position_ticks: positionTicks }
    });
  }

  async reportProgress(positionTicks: number, isPaused: boolean): Promise<void> {
    await this.request('POST', '/Playstate/Progress', {
      session_id: this.sessionId,
      position_ticks: positionTicks,
      is_paused: isPaused
    });
  }

  // Helper methods
  private async request<T>(
    method: string,
    path: string,
    data?: unknown
  ): Promise<T> {
    const response = await this.client.request<T>({ method, url: path, data });
    return response.data;
  }

  private getDeviceId(): string {
    let deviceId = localStorage.getItem('device_id');
    if (!deviceId) {
      deviceId = `windows-${crypto.randomUUID()}`;
      localStorage.setItem('device_id', deviceId);
    }
    return deviceId;
  }

  private handleUnauthorized(): void {
    this.setToken(null);
    this.setSession(null);
    this.user = null;
  }

  // Getters
  get isAuthenticated(): boolean {
    return this.token !== null;
  }

  get currentUser(): User | null {
    return this.user;
  }
}

export const api = new ApiClient({
  baseUrl: import.meta.env.VITE_PHLIX_SERVER_URL || 'http://localhost:8096',
  deviceId: '',
  deviceName: 'Windows Desktop'
});

export default api;
```

---

## 5. Player Implementation

### 5.1 Video Player Component

```tsx
// src/renderer/components/VideoPlayer.tsx

import React, { useRef, useEffect, useState, useCallback } from 'react';
import { usePlaybackStore } from '../stores/playbackStore';
import api from '../utils/api';
import './VideoPlayer.css';

interface VideoPlayerProps {
  itemId: string;
  playbackInfo: PlaybackInfoResponse;
}

export const VideoPlayer: React.FC<VideoPlayerProps> = ({ itemId, playbackInfo }) => {
  const videoRef = useRef<HTMLVideoElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [showControls, setShowControls] = useState(true);
  const [volume, setVolume] = useState(1);
  const [currentTime, setCurrentTime] = useState(0);
  const [duration, setDuration] = useState(0);
  const [buffered, setBuffered] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [playbackRate, setPlaybackRate] = useState(1);

  const hideControlsTimeout = useRef<NodeJS.Timeout>();

  // Initialize playback
  useEffect(() => {
    const video = videoRef.current;
    if (!video || !playbackInfo?.playback_info?.url) return;

    video.src = playbackInfo.playback_info.url;
    video.volume = volume;
    
    const startPlayback = async () => {
      try {
        await video.play();
        setIsPlaying(true);
        startProgressReporting();
      } catch (error) {
        console.error('Playback failed:', error);
      }
    };

    startPlayback();

    return () => {
      stopProgressReporting();
      video.pause();
      video.src = '';
    };
  }, [itemId, playbackInfo]);

  // Auto-hide controls
  useEffect(() => {
    if (isPlaying && showControls) {
      hideControlsTimeout.current = setTimeout(() => {
        setShowControls(false);
      }, 3000);
    }

    return () => {
      if (hideControlsTimeout.current) {
        clearTimeout(hideControlsTimeout.current);
      }
    };
  }, [isPlaying, showControls]);

  // Progress reporting interval
  const progressInterval = useRef<NodeJS.Timeout>();

  const startProgressReporting = () => {
    progressInterval.current = setInterval(async () => {
      const video = videoRef.current;
      if (video && isPlaying) {
        const positionTicks = Math.floor(video.currentTime * 10000000);
        await api.reportProgress(positionTicks, false);
      }
    }, 10000);
  };

  const stopProgressReporting = () => {
    if (progressInterval.current) {
      clearInterval(progressInterval.current);
    }
  };

  // Event handlers
  const handleTimeUpdate = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;
    
    setCurrentTime(video.currentTime);
  }, []);

  const handleLoadedMetadata = useCallback(() => {
    const video = videoRef.current;
    if (!video) return;
    
    setDuration(video.duration);
  }, []);

  const handleProgress = useCallback(() => {
    const video = videoRef.current;
    if (!video || !video.buffered.length) return;
    
    const bufferedEnd = video.buffered.end(video.buffered.length - 1);
    setBuffered((bufferedEnd / video.duration) * 100);
  }, []);

  const handlePlay = useCallback(() => {
    setIsPlaying(true);
    api.resumePlayback();
  }, []);

  const handlePause = useCallback(() => {
    setIsPlaying(false);
    api.pausePlayback();
  }, []);

  const handleEnded = useCallback(async () => {
    setIsPlaying(false);
    await api.stopPlayback();
  }, []);

  const handleVolumeChange = useCallback((newVolume: number) => {
    const video = videoRef.current;
    if (!video) return;
    
    video.volume = newVolume;
    setVolume(newVolume);
  }, []);

  const handleSeek = useCallback((time: number) => {
    const video = videoRef.current;
    if (!video) return;
    
    video.currentTime = time;
    const positionTicks = Math.floor(time * 10000000);
    api.seekPlayback(positionTicks);
  }, []);

  const handlePlaybackRateChange = useCallback((rate: number) => {
    const video = videoRef.current;
    if (!video) return;
    
    video.playbackRate = rate;
    setPlaybackRate(rate);
  }, []);

  const toggleFullscreen = useCallback(async () => {
    if (!containerRef.current) return;

    try {
      if (!document.fullscreenElement) {
        await containerRef.current.requestFullscreen();
        setIsFullscreen(true);
      } else {
        await document.exitFullscreen();
        setIsFullscreen(false);
      }
    } catch (error) {
      console.error('Fullscreen error:', error);
    }
  }, []);

  const formatTime = (seconds: number): string => {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    
    if (h > 0) {
      return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    return `${m}:${s.toString().padStart(2, '0')}`;
  };

  // Keyboard controls
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const video = videoRef.current;
      if (!video) return;

      switch (e.key) {
        case ' ':
        case 'k':
          isPlaying ? video.pause() : video.play();
          break;
        case 'ArrowLeft':
          handleSeek(Math.max(0, video.currentTime - 10));
          break;
        case 'ArrowRight':
          handleSeek(Math.min(duration, video.currentTime + 10));
          break;
        case 'ArrowUp':
          handleVolumeChange(Math.min(1, volume + 0.1));
          break;
        case 'ArrowDown':
          handleVolumeChange(Math.max(0, volume - 0.1));
          break;
        case 'f':
          toggleFullscreen();
          break;
        case 'm':
          handleVolumeChange(volume > 0 ? 0 : 1);
          break;
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isPlaying, volume, duration]);

  return (
    <div 
      ref={containerRef}
      className="video-player"
      onMouseMove={() => setShowControls(true)}
      onMouseLeave={() => isPlaying && setShowControls(false)}
    >
      <video
        ref={videoRef}
        className="video-element"
        onTimeUpdate={handleTimeUpdate}
        onLoadedMetadata={handleLoadedMetadata}
        onProgress={handleProgress}
        onPlay={handlePlay}
        onPause={handlePause}
        onEnded={handleEnded}
        onClick={() => isPlaying ? videoRef.current?.pause() : videoRef.current?.play()}
      />

      {/* Controls Overlay */}
      <div className={`controls-overlay ${showControls ? 'visible' : ''}`}>
        {/* Top Bar */}
        <div className="controls-top">
          <button className="control-btn back-btn" onClick={() => window.history.back()}>
            ← Back
          </button>
          <h2 className="video-title">{playbackInfo.item?.name}</h2>
        </div>

        {/* Center Controls */}
        <div className="controls-center">
          <button 
            className="control-btn skip-btn"
            onClick={() => handleSeek(Math.max(0, currentTime - 10))}
          >
            ⏪ 10s
          </button>
          
          <button 
            className="control-btn play-btn"
            onClick={() => isPlaying ? videoRef.current?.pause() : videoRef.current?.play()}
          >
            {isPlaying ? '⏸' : '▶'}
          </button>
          
          <button 
            className="control-btn skip-btn"
            onClick={() => handleSeek(Math.min(duration, currentTime + 10))}
          >
            10s ⏩
          </button>
        </div>

        {/* Bottom Bar */}
        <div className="controls-bottom">
          {/* Progress Bar */}
          <div className="progress-container">
            <div 
              className="progress-bar"
              onClick={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                const percent = (e.clientX - rect.left) / rect.width;
                handleSeek(percent * duration);
              }}
            >
              <div 
                className="progress-buffered" 
                style={{ width: `${buffered}%` }}
              />
              <div 
                className="progress-current"
                style={{ width: `${(currentTime / duration) * 100}%` }}
              />
            </div>
          </div>

          {/* Time and Controls */}
          <div className="controls-row">
            <span className="time-display">
              {formatTime(currentTime)} / {formatTime(duration)}
            </span>

            <div className="controls-right">
              <select 
                className="playback-rate-select"
                value={playbackRate}
                onChange={(e) => handlePlaybackRateChange(Number(e.target.value))}
              >
                <option value="0.5">0.5x</option>
                <option value="0.75">0.75x</option>
                <option value="1">1x</option>
                <option value="1.25">1.25x</option>
                <option value="1.5">1.5x</option>
                <option value="2">2x</option>
              </select>

              <button 
                className="control-btn volume-btn"
                onClick={() => handleVolumeChange(volume > 0 ? 0 : 1)}
              >
                {volume === 0 ? '🔇' : volume < 0.5 ? '🔉' : '🔊'}
              </button>

              <input
                type="range"
                className="volume-slider"
                min="0"
                max="1"
                step="0.05"
                value={volume}
                onChange={(e) => handleVolumeChange(Number(e.target.value))}
              />

              <button 
                className="control-btn fullscreen-btn"
                onClick={toggleFullscreen}
              >
                {isFullscreen ? '⛶' : '⛶'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default VideoPlayer;
```

### 5.2 Video Player CSS

```css
/* src/renderer/components/VideoPlayer.css */

.video-player {
  position: relative;
  width: 100%;
  height: 100%;
  background-color: #000;
  overflow: hidden;
}

.video-element {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.controls-overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  background: linear-gradient(
    to bottom,
    rgba(0, 0, 0, 0.7) 0%,
    transparent 30%,
    transparent 70%,
    rgba(0, 0, 0, 0.7) 100%
  );
  opacity: 0;
  transition: opacity 0.3s ease;
  pointer-events: none;
}

.controls-overlay.visible {
  opacity: 1;
  pointer-events: all;
}

.controls-top {
  display: flex;
  align-items: center;
  padding: 20px;
  gap: 20px;
}

.back-btn {
  background: rgba(255, 255, 255, 0.2);
  border: none;
  color: white;
  padding: 10px 20px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.back-btn:hover {
  background: rgba(255, 255, 255, 0.3);
}

.video-title {
  color: white;
  font-size: 18px;
  font-weight: 500;
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.controls-center {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 40px;
}

.control-btn {
  background: rgba(255, 255, 255, 0.2);
  border: none;
  color: white;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  cursor: pointer;
  font-size: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s;
}

.control-btn:hover {
  background: rgba(255, 255, 255, 0.3);
}

.play-btn {
  width: 80px;
  height: 80px;
  font-size: 32px;
}

.controls-bottom {
  padding: 20px;
}

.progress-container {
  margin-bottom: 15px;
}

.progress-bar {
  height: 6px;
  background: rgba(255, 255, 255, 0.3);
  border-radius: 3px;
  cursor: pointer;
  position: relative;
}

.progress-buffered {
  position: absolute;
  height: 100%;
  background: rgba(255, 255, 255, 0.5);
  border-radius: 3px;
}

.progress-current {
  position: absolute;
  height: 100%;
  background: #0095d5;
  border-radius: 3px;
}

.controls-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.time-display {
  color: white;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}

.controls-right {
  display: flex;
  align-items: center;
  gap: 15px;
}

.playback-rate-select {
  background: rgba(255, 255, 255, 0.2);
  border: none;
  color: white;
  padding: 5px 10px;
  border-radius: 4px;
  cursor: pointer;
}

.volume-slider {
  width: 100px;
  cursor: pointer;
}

.fullscreen-btn {
  background: transparent;
  font-size: 20px;
}
```

---

## 6. System Integration

### 6.1 Main Process (Electron)

```typescript
// src/main/index.ts

import { app, BrowserWindow, Menu, Tray, ipcMain, shell, nativeImage } from 'electron';
import * as path from 'path';
import log from 'electron-log';
import Store from 'electron-store';

const store = new Store();

let mainWindow: BrowserWindow | null = null;
let tray: Tray | null = null;

const isDev = process.env.NODE_ENV === 'development' || !app.isPackaged;

log.initialize();
log.info('Phlix Windows starting...');

function createWindow(): void {
  log.info('Creating main window');

  mainWindow = new BrowserWindow({
    width: 1280,
    height: 720,
    minWidth: 960,
    minHeight: 540,
    backgroundColor: '#1a1a2e',
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false
    }
  });

  // Load content
  if (isDev) {
    mainWindow.loadURL('http://localhost:5173');
    mainWindow.webContents.openDevTools();
  } else {
    mainWindow.loadFile(path.join(__dirname, '../renderer/index.html'));
  }

  // Show when ready
  mainWindow.once('ready-to-show', () => {
    mainWindow?.show();
    log.info('Main window ready');
  });

  // Handle close to tray
  mainWindow.on('close', (event) => {
    if (store.get('minimizeToTray', true)) {
      event.preventDefault();
      mainWindow?.hide();
    }
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });

  // Handle external links
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });
}

function createTray(): void {
  const icon = nativeImage.createFromPath(
    path.join(__dirname, '../../build/icon.png')
  );

  tray = new Tray(icon.resize({ width: 16, height: 16 }));

  const contextMenu = Menu.buildFromTemplate([
    { label: 'Show Phlix', click: () => mainWindow?.show() },
    { type: 'separator' },
    { label: 'Play/Pause', click: () => mainWindow?.webContents.send('media-play-pause') },
    { label: 'Stop', click: () => mainWindow?.webContents.send('media-stop') },
    { type: 'separator' },
    { label: 'Quit', click: () => {
      store.set('minimizeToTray', false);
      app.quit();
    }}
  ]);

  tray.setToolTip('Phlix Media Server');
  tray.setContextMenu(contextMenu);

  tray.on('click', () => {
    mainWindow?.show();
  });
}

function createMenu(): void {
  const template: Electron.MenuItemConstructorOptions[] = [
    {
      label: 'File',
      submenu: [
        { label: 'Open File...', accelerator: 'CmdOrCtrl+O', click: () => openFile() },
        { type: 'separator' },
        { label: 'Settings', accelerator: 'CmdOrCtrl+,', click: () => openSettings() },
        { type: 'separator' },
        { role: 'quit' }
      ]
    },
    {
      label: 'Playback',
      submenu: [
        { label: 'Play/Pause', accelerator: 'Space', click: () => mainWindow?.webContents.send('media-play-pause') },
        { label: 'Stop', click: () => mainWindow?.webContents.send('media-stop') },
        { type: 'separator' },
        { label: 'Rewind', accelerator: 'Left', click: () => mainWindow?.webContents.send('media-rewind') },
        { label: 'Fast Forward', accelerator: 'Right', click: () => mainWindow?.webContents.send('media-forward') },
        { type: 'separator' },
        { label: 'Fullscreen', accelerator: 'F11', click: () => toggleFullscreen() }
      ]
    },
    {
      label: 'View',
      submenu: [
        { role: 'reload' },
        { role: 'forceReload' },
        { role: 'toggleDevTools' },
        { type: 'separator' },
        { role: 'resetZoom' },
        { role: 'zoomIn' },
        { role: 'zoomOut' },
        { type: 'separator' },
        { role: 'togglefullscreen' }
      ]
    },
    {
      label: 'Help',
      submenu: [
        { label: 'About Phlix', click: () => showAbout() }
      ]
    }
  ];

  const menu = Menu.buildFromTemplate(template);
  Menu.setApplicationMenu(menu);
}

async function openFile(): Promise<void> {
  const { dialog } = await import('electron');
  const result = await dialog.showOpenDialog(mainWindow!, {
    properties: ['openFile'],
    filters: [
      { name: 'Video Files', extensions: ['mp4', 'mkv', 'avi', 'mov', 'wmv', 'webm'] },
      { name: 'Audio Files', extensions: ['mp3', 'flac', 'aac', 'ogg', 'wav', 'm4a'] },
      { name: 'All Files', extensions: ['*'] }
    ]
  });

  if (!result.canceled && result.filePaths.length > 0) {
    mainWindow?.webContents.send('file-opened', result.filePaths[0]);
  }
}

function openSettings(): void {
  mainWindow?.webContents.send('open-settings');
}

function toggleFullscreen(): void {
  if (!mainWindow) return;
  
  if (mainWindow.isFullScreen()) {
    mainWindow.setFullScreen(false);
  } else {
    mainWindow.setFullScreen(true);
  }
}

function showAbout(): void {
  const { dialog } = require('electron');
  dialog.showMessageBox(mainWindow!, {
    type: 'info',
    title: 'About Phlix',
    message: 'Phlix Media Server',
    detail: `Version ${app.getVersion()}\n\nA free media server for your home.`
  });
}

// IPC Handlers
ipcMain.handle('get-app-path', () => app.getPath('userData'));

ipcMain.handle('get-version', () => app.getVersion());

ipcMain.on('set-always-on-top', (_, value: boolean) => {
  mainWindow?.setAlwaysOnTop(value);
});

ipcMain.on('minimize-to-tray', () => {
  mainWindow?.hide();
});

// App lifecycle
app.whenReady().then(() => {
  log.info('App ready');
  createWindow();
  createMenu();
  createTray();
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit();
  }
});

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) {
    createWindow();
  }
});

app.on('before-quit', () => {
  store.set('minimizeToTray', false);
});

// Global exception handler
process.on('uncaughtException', (error) => {
  log.error('Uncaught exception:', error);
});

process.on('unhandledRejection', (reason) => {
  log.error('Unhandled rejection:', reason);
});
```

---

## 7. User Interface

### 7.1 App Component

```tsx
// src/renderer/App.tsx

import React, { useEffect, useState } from 'react';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { useAuthStore } from './stores/authStore';
import { Sidebar } from './components/Sidebar';
import { Header } from './components/Header';
import { Home } from './pages/Home';
import { Library } from './pages/Library';
import { ItemDetail } from './pages/ItemDetail';
import { Player } from './pages/Player';
import { Settings } from './pages/Settings';
import { Login } from './pages/Login';
import './styles/global.css';

export const App: React.FC = () => {
  const { isAuthenticated, checkAuth } = useAuthStore();
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const init = async () => {
      await checkAuth();
      setIsLoading(false);
    };
    init();
  }, []);

  if (isLoading) {
    return (
      <div className="loading-screen">
        <div className="loading-spinner" />
        <p>Loading...</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Login />;
  }

  return (
    <HashRouter>
      <div className="app-layout">
        <Sidebar />
        <div className="main-content">
          <Header />
          <div className="page-content">
            <Routes>
              <Route path="/" element={<Home />} />
              <Route path="/library/:id" element={<Library />} />
              <Route path="/item/:id" element={<ItemDetail />} />
              <Route path="/player/:id" element={<Player />} />
              <Route path="/settings" element={<Settings />} />
              <Route path="*" element={<Navigate to="/" />} />
            </Routes>
          </div>
        </div>
      </div>
    </HashRouter>
  );
};

export default App;
```

---

## 8. Testing

### 8.1 Unit Tests

```typescript
// tests/unit/api.test.ts

import { describe, it, expect, vi } from 'vitest';
import api from '../../src/renderer/utils/api';

describe('ApiClient', () => {
  it('should store token when setToken is called', () => {
    api.setToken('test-token');
    expect(localStorage.getItem('auth_token')).toBe('test-token');
  });

  it('should clear token when setToken is called with null', () => {
    api.setToken('test-token');
    api.setToken(null);
    expect(localStorage.getItem('auth_token')).toBeNull();
  });
});
```

### 8.2 E2E Tests

```typescript
// tests/e2e/app.test.ts

import { test, expect } from '@playwright/test';

test.describe('Phlix Windows App', () => {
  test('should launch and show login screen', async ({ app }) => {
    await app.launch();
    await expect(app.locator('.login-form')).toBeVisible();
  });

  test('should login and show home screen', async ({ app }) => {
    await app.login('testuser', 'testpass');
    await expect(app.locator('.home-view')).toBeVisible();
  });
});
```

---

## 9. Microsoft Store Submission

### 9.1 Pre-Submission Checklist

```
□ Microsoft Partner Center account created
□ App icon (256x256 PNG + ICO)
□ Screenshots (various sizes required)
□ Store listing (description, keywords, category)
□ Privacy policy URL (required)
□ Age rating (3+ for general media)
□ Content rating submission (IARC)
□ App manifest configured
□ Code signed with valid certificate
□ Tested on Windows 10 and 11
□ Tested on ARM and x64
```

### 9.2 Packaging Commands

```bash
# Package for Windows (NSIS installer)
npm run package

# Package for Microsoft Store (APPX)
npm run package:store
```

---

## 10. Implementation Checklist

### Phase 1: Environment Setup
- [ ] Install Node.js and VS Code
- [ ] Create Electron project
- [ ] Configure TypeScript
- [ ] Setup build tools

### Phase 2: Main Process
- [ ] Implement main entry
- [ ] Create window management
- [ ] Setup system tray
- [ ] Create application menu

### Phase 3: API Client
- [ ] Implement API client
- [ ] Add authentication flow
- [ ] Implement session handling
- [ ] Add playback control

### Phase 4: Player
- [ ] Create video player component
- [ ] Implement controls
- [ ] Handle keyboard shortcuts
- [ ] Add progress reporting

### Phase 5: UI
- [ ] Create layout components
- [ ] Build home page
- [ ] Build library page
- [ ] Build detail page

### Phase 6: System Integration
- [ ] System tray
- [ ] Jump list
- [ ] Native menus
- [ ] Fullscreen support

### Phase 7: Testing
- [ ] Unit tests
- [ ] Integration tests
- [ ] E2E tests
- [ ] Performance testing

### Phase 8: Submission
- [ ] Create assets
- [ ] Configure signing
- [ ] Build packages
- [ ] Submit to store

---

**Document Version:** 1.0  
**Last Updated:** 2026-05-14  
