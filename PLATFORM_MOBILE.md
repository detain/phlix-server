# Phlix Media Server - Mobile Application (iOS & Android)

**Document Version:** 1.0  
**Platform:** iOS 15+ and Android 10+ (API 29+)  
**Technology:** React Native 0.76+ with Native Modules, Kotlin/Swift for platform-specific features

---

## Table of Contents

1. [Overview](#1-overview)
2. [Development Environment](#2-development-environment)
3. [Project Structure](#3-project-structure)
4. [API Client Implementation](#4-api-client-implementation)
5. [Video Player Implementation](#5-video-player-implementation)
6. [Navigation & UI](#6-navigation--ui)
7. [Authentication & Security](#7-authentication--security)
8. [Background Playback & Media Session](#8-background-playback--media-session)
9. [Offline Support & Downloads](#9-offline-support--downloads)
10. [Push Notifications](#10-push-notifications)
11. [Platform-Specific Features](#11-platform-specific-features)
12. [Testing](#12-testing)
13. [App Store Submission](#13-app-store-submission)
14. [Implementation Checklist](#14-implementation-checklist)

---

## 1. Overview

### 1.1 Platform Capabilities

#### iOS Capabilities

| Capability | Support |
|------------|---------|
| Video Codecs | H.264, H.265/HEVC, VP9 |
| Audio Codecs | AAC, AC3, EAC3, FLAC, MP3, ALAC |
| Containers | MP4, MKV, MOV, TS |
| Streaming | HLS, HTTP Progressive |
| Features | AirPlay, Picture-in-Picture, CarPlay, Background Audio |
| Playback | AVKit/AVFoundation, AVPlayer |
| Max Resolution | 4K HDR with Dolby Vision |

#### Android Capabilities

| Capability | Support |
|------------|---------|
| Video Codecs | H.264, H.265/HEVC, VP9, AV1 |
| Audio Codecs | AAC, AC3, EAC3, FLAC, MP3, Opus, DTS |
| Containers | MP4, MKV, MOV, TS, WebM |
| Streaming | HLS, MPEG-DASH, HTTP Progressive |
| Features | Chromecast, Picture-in-Picture, Cast notifications |
| Playback | ExoPlayer (Media3), AVPlayer on Fire TV |
| Max Resolution | 4K HDR with Dolby Vision |

### 1.2 React Native vs Native Choice

**Decision: Hybrid Approach**

- **React Native** for UI layer, navigation, state management, API client
- **Native Modules** via TurboModules for video playback performance
- **Reasoning**: Video playback is performance-critical; native codecs must be used directly

### 1.3 Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Phlix Mobile App                          │
├─────────────────────────────────────────────────────────────┤
│  UI Layer (React Native)                                     │
│  ├── Navigation (React Navigation)                          │
│  ├── Screens (Home, Library, Player, Settings)              │
│  ├── Components (Poster, Card, List, Search)                │
│  └── State Management (Zustand)                             │
├─────────────────────────────────────────────────────────────┤
│  Native Bridge / TurboModules                               │
│  ├── PhlixPlayer (iOS: AVKit, Android: ExoPlayer)           │
│  ├── PhlixDownloader (Background downloads)                 │
│  └── PhlixMediaSession (Media controls, notifications)      │
├─────────────────────────────────────────────────────────────┤
│  Services                                                    │
│  ├── ApiClient (REST + WebSocket)                           │
│  ├── AuthService (Token management)                         │
│  └── SyncService (Offline sync)                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Development Environment

### 2.1 Required Software

```
1. Node.js 20+ (LTS recommended)
2. React Native CLI 0.76+
3. Xcode 15+ (for iOS)
4. Android Studio 2024+ (for Android)
5. CocoaPods (iOS dependency manager)
6. Fastlane (CI/CD and deployment)
7. Git
```

### 2.2 Environment Setup

#### macOS (iOS Development)

```bash
# Install Homebrew
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install Node.js
brew install node@20

# Install CocoaPods
brew install cocoapods

# Install Watchman (file watcher for React Native)
brew install watchman

# Install Xcode Command Line Tools
xcode-select --install

# Install Fastlane
gem install fastlane
```

#### Windows/Linux (Android Development)

```bash
# Install Node.js 20
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Install Java JDK 17
sudo apt-get install openjdk-17-jdk

# Install Android Studio
# Download from: https://developer.android.com/studio

# Set ANDROID_HOME
export ANDROID_HOME=$HOME/Android/Sdk
export PATH=$PATH:$ANDROID_HOME/emulator:$ANDROID_HOME/platform-tools

# Install Fastlane
npm install -g fastlane
```

### 2.3 React Native Project Setup

```bash
# Initialize React Native project
npx @react-native-community/cli init PhlixMobile --version 0.76.0

# Navigate to project
cd PhlixMobile

# Install core dependencies
npm install \
  @react-navigation/native \
  @react-navigation/native-stack \
  @react-navigation/bottom-tabs \
  react-native-screens \
  react-native-safe-area-context \
  zustand \
  axios \
  react-native-websocket \
  react-native-video@^6.0.0 \
  react-native-track-player@^4.1.0 \
  react-native-background-downloader \
  react-native-push-notification \
  @react-native-async-storage/async-storage \
  react-native-fast-image \
  react-native-linear-gradient

# Install iOS native modules
cd ios && pod install && cd ..

# Install Android native modules
# (handled automatically by Gradle)
```

### 2.4 Project Configuration

#### iOS Info.plist Additions

```xml
<!-- Background Modes -->
<key>UIBackgroundModes</key>
<array>
    <string>audio</string>
    <string>fetch</string>
    <string>remote-notification</string>
</array>

<!-- Permissions -->
<key>NSAppleMusicUsageDescription</key>
<string>Phlix needs access to your media library</string>
<key>NSLocalNetworkUsageDescription</key>
<string>Phlix needs to discover media servers on your network</string>
<key>UIBackgroundModes</key>
```

#### Android Manifest Additions

```xml
<!-- Permissions -->
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_MEDIA_PLAYBACK" />
<uses-permission android:name="android.permission.WAKE_LOCK" />
<uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<!-- Features -->
<uses-feature android:name="android.hardware.screen.portrait" android:required="false" />
<uses-feature android:name="android.software.picture_in_picture" android:required="false" />
```

---

## 3. Project Structure

```
PhlixMobile/
├── src/
│   ├── App.tsx                    # App entry point
│   ├── api/
│   │   ├── client.ts              # Base API client (Axios)
│   │   ├── ApiClient.ts           # Phlix API wrapper
│   │   ├── AuthManager.ts         # Authentication
│   │   ├── SessionManager.ts      # Session handling
│   │   ├── LibraryManager.ts      # Library browsing
│   │   ├── PlaybackManager.ts     # Playback control
│   │   └── types.ts               # API types
│   ├── components/
│   │   ├── media/
│   │   │   ├── PosterCard.tsx     # Movie/show poster
│   │   │   ├── MediaCard.tsx      # Generic media card
│   │   │   ├── EpisodeCard.tsx    # Episode card
│   │   │   ├── SeasonCard.tsx     # Season card
│   │   │   ├── MediaList.tsx      # Horizontal media list
│   │   │   ├── MediaGrid.tsx      # Grid of media items
│   │   │   └── ContinueWatching.tsx
│   │   ├── player/
│   │   │   ├── PlayerControls.tsx # Video player controls
│   │   │   ├── SeekBar.tsx        # Seekable progress bar
│   │   │   ├── QualitySelector.tsx# Video quality picker
│   │   │   ├── SubtitleSelector.tsx
│   │   │   └── AudioSelector.tsx
│   │   ├── ui/
│   │   │   ├── SearchBar.tsx
│   │   │   ├── LoadingSpinner.tsx
│   │   │   ├── ErrorView.tsx
│   │   │   ├── EmptyState.tsx
│   │   │   └── GradientOverlay.tsx
│   │   └── layout/
│   │       ├── SafeContainer.tsx
│   │       └── MediaHeader.tsx
│   ├── screens/
│   │   ├── HomeScreen.tsx         # Home screen with Continue Watching, Recent
│   │   ├── LibraryScreen.tsx      # Browse libraries
│   │   ├── MediaDetailScreen.tsx  # Movie/Show details
│   │   ├── SeasonDetailScreen.tsx # Season episodes
│   │   ├── PlayerScreen.tsx       # Fullscreen video player
│   │   ├── SearchScreen.tsx       # Search
│   │   ├── SettingsScreen.tsx     # App settings
│   │   ├── DownloadsScreen.tsx    # Downloaded content
│   │   └── ProfilesScreen.tsx     # User profiles
│   ├── navigation/
│   │   ├── RootNavigator.tsx      # Root navigation
│   │   ├── HomeStack.tsx          # Home stack
│   │   ├── LibraryStack.tsx       # Library stack
│   │   └── PlayerStack.tsx        # Player stack
│   ├── services/
│   │   ├── PlayerService.ts       # Native player bridge
│   │   ├── DownloadService.ts     # Background downloads
│   │   ├── SyncService.ts         # Offline sync
│   │   └── NotificationService.ts # Push notifications
│   ├── stores/
│   │   ├── useAuthStore.ts        # Auth state
│   │   ├── usePlayerStore.ts      # Player state
│   │   ├── useLibraryStore.ts     # Library cache
│   │   └── useSettingsStore.ts    # App settings
│   ├── hooks/
│   │   ├── useApi.ts              # API hooks
│   │   ├── usePlayer.ts           # Player hooks
│   │   ├── useDownload.ts         # Download hooks
│   │   └── useMediaSession.ts     # Media session hooks
│   ├── native/
│   │   ├── PhlixPlayer.ts         # Native player module
│   │   ├── PhlixDownloader.ts     # Native downloader
│   │   └── types.ts               # Native module types
│   ├── utils/
│   │   ├── formatters.ts          # Time, size formatters
│   │   ├── storage.ts             # AsyncStorage helpers
│   │   └── logger.ts              # Logging
│   └── types/
│       ├── media.ts               # Media item types
│       ├── playback.ts            # Playback types
│       └── navigation.ts          # Navigation types
├── ios/
│   ├── LocalPods/
│   │   └── PhlixPlayer/           # Native AVKit player
│   ├── PhlixMobile/
│   │   ├── AppDelegate.mm
│   │   ├── Info.plist
│   │   └── PhlixMobile.entitlements
├── android/
│   └── app/src/main/java/com/phlixmobile/
│       ├── player/                # ExoPlayer implementation
│       ├── downloader/            # Download service
│       └── MainApplication.kt
└── assets/
    ├── images/
    └── fonts/
```

---

## 4. API Client Implementation

### 4.1 Base API Client

```typescript
// src/api/client.ts
import axios, { AxiosInstance, AxiosError } from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';

const BASE_URL = 'https://api.phlix.app'; // Configure for self-hosted

class ApiClient {
  private client: AxiosInstance;
  private refreshPromise: Promise<string> | null = null;

  constructor() {
    this.client = axios.create({
      baseURL: BASE_URL,
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json',
      },
    });

    this.setupInterceptors();
  }

  private setupInterceptors() {
    // Request interceptor - add auth token
    this.client.interceptors.request.use(
      async (config) => {
        const token = await AsyncStorage.getItem('access_token');
        if (token) {
          config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
      },
      (error) => Promise.reject(error)
    );

    // Response interceptor - handle token refresh
    this.client.interceptors.response.use(
      (response) => response,
      async (error: AxiosError) => {
        const originalRequest = error.config;
        
        if (error.response?.status === 401 && originalRequest) {
          try {
            const newToken = await this.refreshToken();
            originalRequest.headers.Authorization = `Bearer ${newToken}`;
            return this.client(originalRequest);
          } catch (refreshError) {
            // Logout user
            await AsyncStorage.multiRemove(['access_token', 'refresh_token']);
            return Promise.reject(refreshError);
          }
        }
        
        return Promise.reject(error);
      }
    );
  }

  private async refreshToken(): Promise<string> {
    // Prevent multiple simultaneous refresh requests
    if (this.refreshPromise) {
      return this.refreshPromise;
    }

    this.refreshPromise = (async () => {
      const refreshToken = await AsyncStorage.getItem('refresh_token');
      if (!refreshToken) {
        throw new Error('No refresh token available');
      }

      const response = await axios.post(`${BASE_URL}/auth/refresh`, {
        refresh_token: refreshToken,
      });

      const { access_token, refresh_token: newRefreshToken } = response.data;
      
      await AsyncStorage.setItem('access_token', access_token);
      if (newRefreshToken) {
        await AsyncStorage.setItem('refresh_token', newRefreshToken);
      }

      return access_token;
    })();

    try {
      return await this.refreshPromise;
    } finally {
      this.refreshPromise = null;
    }
  }

  // Generic HTTP methods
  async get<T>(url: string, params?: object): Promise<T> {
    const response = await this.client.get(url, { params });
    return response.data;
  }

  async post<T>(url: string, data?: object): Promise<T> {
    const response = await this.client.post(url, data);
    return response.data;
  }

  async put<T>(url: string, data?: object): Promise<T> {
    const response = await this.client.put(url, data);
    return response.data;
  }

  async delete<T>(url: string): Promise<T> {
    const response = await this.client.delete(url);
    return response.data;
  }
}

export const apiClient = new ApiClient();
export default apiClient;
```

### 4.2 Authentication API

```typescript
// src/api/AuthManager.ts
import apiClient from './client';
import AsyncStorage from '@react-native-async-storage/async-storage';

interface LoginResponse {
  access_token: string;
  refresh_token: string;
  user: User;
  server: Server;
}

interface User {
  id: string;
  username: string;
  display_name: string;
  avatar_url?: string;
}

interface Server {
  id: string;
  name: string;
  url: string;
  version: string;
}

class AuthManager {
  // Server discovery using broadcast
  async discoverServers(): Promise<Server[]> {
    // Use UDP broadcast on local network
    // Implementation via native module for UDP socket
    const { PhlixDiscovery } = require('../native/PhlixDiscovery');
    return PhlixDiscovery.discoverServers();
  }

  // Login with username/password
  async login(serverUrl: string, username: string, password: string): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>(`${serverUrl}/auth/login`, {
      username,
      password,
    });

    await this.saveCredentials(response);
    return response;
  }

  // Login with device name (for auto-login)
  async loginWithDevice(serverUrl: string, deviceName: string): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>(`${serverUrl}/auth/device`, {
      device_name: deviceName,
    });

    await this.saveCredentials(response);
    return response;
  }

  // Manual token authentication
  async loginWithToken(serverUrl: string, token: string): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>(`${serverUrl}/auth/token`, {
      token,
    });

    await this.saveCredentials(response);
    return response;
  }

  private async saveCredentials(data: LoginResponse): Promise<void> {
    await AsyncStorage.setItem('access_token', data.access_token);
    await AsyncStorage.setItem('refresh_token', data.refresh_token);
    await AsyncStorage.setItem('user', JSON.stringify(data.user));
    await AsyncStorage.setItem('server', JSON.stringify(data.server));
  }

  // Logout
  async logout(): Promise<void> {
    try {
      await apiClient.post('/auth/logout');
    } catch {
      // Ignore logout errors
    } finally {
      await AsyncStorage.multiRemove([
        'access_token',
        'refresh_token',
        'user',
        'server',
      ]);
    }
  }

  // Check if user is authenticated
  async isAuthenticated(): Promise<boolean> {
    const token = await AsyncStorage.getItem('access_token');
    return !!token;
  }

  // Get current user
  async getCurrentUser(): Promise<User | null> {
    const userData = await AsyncStorage.getItem('user');
    return userData ? JSON.parse(userData) : null;
  }

  // Get current server
  async getCurrentServer(): Promise<Server | null> {
    const serverData = await AsyncStorage.getItem('server');
    return serverData ? JSON.parse(serverData) : null;
  }
}

export const authManager = new AuthManager();
export default authManager;
```

### 4.3 Library Manager

```typescript
// src/api/LibraryManager.ts
import apiClient from './client';
import { MediaItem, Movie, Series, Season, Episode } from '../types/media';

class LibraryManager {
  // Get all libraries
  async getLibraries(): Promise<Library[]> {
    return apiClient.get<Library[]>('/libraries');
  }

  // Get library items
  async getLibraryItems(
    libraryId: string,
    options: {
      type?: 'movie' | 'series' | 'all';
      sortBy?: string;
      sortOrder?: 'asc' | 'desc';
      limit?: number;
      offset?: number;
    } = {}
  ): Promise<PaginatedResponse<MediaItem>> {
    return apiClient.get<PaginatedResponse<MediaItem>>(
      `/libraries/${libraryId}/items`,
      options
    );
  }

  // Get recently added
  async getRecentlyAdded(limit: number = 20): Promise<MediaItem[]> {
    return apiClient.get<MediaItem[]>('/libraries/recently-added', { limit });
  }

  // Get continue watching
  async getContinueWatching(userId: string): Promise<MediaItem[]> {
    return apiClient.get<MediaItem[]>(`/users/${userId}/continue-watching`);
  }

  // Get media item details
  async getMediaItem(itemId: string): Promise<MediaItem> {
    return apiClient.get<MediaItem>(`/media/${itemId}`);
  }

  // Get series seasons
  async getSeasons(seriesId: string): Promise<Season[]> {
    return apiClient.get<Season[]>(`/series/${seriesId}/seasons`);
  }

  // Get season episodes
  async getEpisodes(seasonId: string): Promise<Episode[]> {
    return apiClient.get<Episode[]>(`/seasons/${seasonId}/episodes`);
  }

  // Search media
  async search(query: string, type?: 'movie' | 'series' | 'all'): Promise<MediaItem[]> {
    return apiClient.get<MediaItem[]>('/search', { query, type });
  }

  // Get metadata (posters, backdrop, etc.)
  async getMetadata(itemId: string): Promise<MediaMetadata> {
    return apiClient.get<MediaMetadata>(`/media/${itemId}/metadata`);
  }
}

export interface Library {
  id: string;
  name: string;
  type: 'movie' | 'series' | 'music' | 'photo';
  display_order: number;
  artwork: {
    poster: string;
    backdrop: string;
  };
}

export interface PaginatedResponse<T> {
  items: T[];
  total: number;
  limit: number;
  offset: number;
  has_more: boolean;
}

export interface MediaMetadata {
  poster_url: string;
  backdrop_url: string;
  banner_url?: string;
  logo_url?: string;
  genres: string[];
  tags: string[];
  rating?: number;
  critic_rating?: number;
  year?: number;
  runtime_ticks: number;
  community_rating?: number;
}

export const libraryManager = new LibraryManager();
export default libraryManager;
```

### 4.4 Playback Manager

```typescript
// src/api/PlaybackManager.ts
import apiClient from './client';
import { StreamInfo, QualityProfile, SubtitleTrack, AudioTrack } from '../types/playback';

class PlaybackManager {
  // Get playback info for item
  async getPlaybackInfo(
    itemId: string,
    deviceProfile: DeviceProfile
  ): Promise<PlaybackInfo> {
    return apiClient.post<PlaybackInfo>(`/media/${itemId}/playback`, {
      device_profile: deviceProfile,
    });
  }

  // Get stream URL
  async getStreamUrl(
    itemId: string,
    options: {
      media_source_id?: string;
      stream_id?: string;
      quality?: string;
      subtitle_method?: 'embed' | 'burn' | 'hls';
      audio_method?: 'embed' | 'transcode';
    } = {}
  ): Promise<StreamInfo> {
    return apiClient.post<StreamInfo>(`/media/${itemId}/stream`, options);
  }

  // Report playback progress
  async reportProgress(
    sessionId: string,
    progress: PlaybackProgress
  ): Promise<void> {
    await apiClient.post(`/sessions/${sessionId}/progress`, progress);
  }

  // Report playback stopped
  async reportStopped(sessionId: string): Promise<void> {
    await apiClient.post(`/sessions/${sessionId}/stopped`);
  }

  // Report playback started
  async reportStarted(sessionId: string, itemId: string): Promise<void> {
    await apiClient.post(`/sessions/${sessionId}/started`, { item_id: itemId });
  }

  // Mark as watched
  async markAsWatched(itemId: string): Promise<void> {
    await apiClient.post(`/media/${itemId}/watched`);
  }

  // Mark as unwatched
  async markAsUnwatched(itemId: string): Promise<void> {
    await apiClient.post(`/media/${itemId}/unwatched`);
  }

  // Get playback session
  async getSession(sessionId: string): Promise<PlaybackSession> {
    return apiClient.get<PlaybackSession>(`/sessions/${sessionId}`);
  }
}

export interface DeviceProfile {
  name: string;
  platform: 'ios' | 'android';
  version: string;
  capabilities: {
    video_codecs: string[];
    audio_codecs: string[];
    max_resolution: number;
    max_bitrate: number;
    supports_4k: boolean;
    supports_hdr: boolean;
    supports_dolby_vision: boolean;
    supports_dolby_atmos: boolean;
    supports DTS: boolean;
  };
}

export interface PlaybackInfo {
  media_source: MediaSource;
  play_session_id: string;
  stream_info: StreamInfo;
  subtitle_tracks: SubtitleTrack[];
  audio_tracks: AudioTrack[];
}

export interface MediaSource {
  id: string;
  protocol: 'hls' | 'http';
  container: string;
  size: number;
  bitrate: number;
}

export interface PlaybackProgress {
  position_ticks: number;
  duration_ticks: number;
  is_paused: boolean;
  volume_level: number;
}

export interface PlaybackSession {
  id: string;
  user_id: string;
  media_item_id: string;
  server_id: string;
  client_name: string;
  device_id: string;
}

export const playbackManager = new PlaybackManager();
export default playbackManager;
```

---

## 5. Video Player Implementation

### 5.1 Native Player Bridge (iOS - AVKit)

```swift
// ios/LocalPods/PhlixPlayer/PhlixPlayerView.swift
import AVKit
import AVFoundation
import React

@objc(PhlixPlayerView)
class PhlixPlayerView: RCTViewManager {
    override func view() -> UIView! {
        return PhlixPlayerViewWrapper()
    }
}

class PhlixPlayerViewWrapper: UIView {
    private var player: AVPlayer?
    private var playerLayer: AVPlayerLayer?
    private var timeObserver: Any?
    private var playerItem: AVPlayerItem?
    
    // Event emitter
    @objc var onPlaybackEvent: RCTDirectEventBlock?
    @objc var onProgress: RCTDirectEventBlock?
    @objc var onError: RCTDirectEventBlock?
    
    // Properties
    @objc var src: String = "" {
        didSet { loadVideo() }
    }
    
    @objc var autoPlay: Bool = true
    @objc var startPosition: Double = 0
    @objc var volume: Float = 1.0 {
        didSet { player?.volume = volume }
    }
    @objc var muted: Bool = false {
        didSet { player?.isMuted = muted }
    }
    
    override init(frame: CGRect) {
        super.init(frame: frame)
        setupPlayer()
    }
    
    required init?(coder: NSCoder) {
        super.init(coder: coder)
        setupPlayer()
    }
    
    private func setupPlayer() {
        playerLayer = AVPlayerLayer()
        playerLayer?.videoGravity = .resizeAspect
        playerLayer?.frame = bounds
        if let layer = playerLayer {
            layer.addSublayer(layer)
        }
    }
    
    override func layoutSubviews() {
        super.layoutSubviews()
        playerLayer?.frame = bounds
    }
    
    private func loadVideo() {
        guard !src.isEmpty else { return }
        
        // Clean up previous player
        cleanup()
        
        // Create asset and player item
        guard let url = URL(string: src) else {
            onError?(["error": "Invalid URL"])
            return
        }
        
        let asset = AVURLAsset(url: url)
        playerItem = AVPlayerItem(asset: asset)
        player = AVPlayer(playerItem: playerItem)
        
        playerLayer?.player = player
        
        // Observe player status
        playerItem?.addObserver(self, forKeyPath: "status", options: [.new], context: nil)
        
        // Add time observer
        let interval = CMTime(seconds: 1.0, preferredTimescale: CMTimeScale(NSEC_PER_SEC))
        timeObserver = player?.addPeriodicTimeObserver(forInterval: interval, queue: .main) { [weak self] time in
            self?.onProgress?([
                "currentTime": time.seconds,
                "duration": self?.playerItem?.duration.seconds ?? 0
            ])
        }
        
        // Seek to start position
        if startPosition > 0 {
            let seekTime = CMTime(seconds: startPosition, preferredTimescale: CMTimeScale(NSEC_PER_SEC))
            player?.seek(to: seekTime)
        }
        
        if autoPlay {
            player?.play()
        }
        
        // Observe playback end
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(playerDidFinishPlaying),
            name: .AVPlayerItemDidPlayToEndTime,
            object: playerItem
        )
    }
    
    override func observeValue(forKeyPath keyPath: String?, of object: Any?, change: [NSKeyValueChangeKey : Any]?, context: UnsafeMutableRawPointer?) {
        if keyPath == "status" {
            if playerItem?.status == .readyToPlay {
                onPlaybackEvent?(["event": "ready"])
            } else if playerItem?.status == .failed {
                onError?(["error": playerItem?.error?.localizedDescription ?? "Unknown error"])
            }
        }
    }
    
    @objc private func playerDidFinishPlaying() {
        onPlaybackEvent?(["event": "ended"])
    }
    
    // React Native methods
    @objc func play() {
        player?.play()
        onPlaybackEvent?(["event": "play"])
    }
    
    @objc func pause() {
        player?.pause()
        onPlaybackEvent?(["event": "pause"])
    }
    
    @objc func seekTo(_ position: Double) {
        let time = CMTime(seconds: position, preferredTimescale: CMTimeScale(NSEC_PER_SEC))
        player?.seek(to: time)
    }
    
    @objc func setVolume(_ volume: Float) {
        player?.volume = volume
    }
    
    @objc func setMuted(_ muted: Bool) {
        player?.isMuted = muted
    }
    
    private func cleanup() {
        if let observer = timeObserver {
            player?.removeTimeObserver(observer)
            timeObserver = nil
        }
        playerItem?.removeObserver(self, forKeyPath: "status")
        NotificationCenter.default.removeObserver(self)
        player?.pause()
        player = nil
        playerItem = nil
    }
    
    deinit {
        cleanup()
    }
}
```

### 5.2 Native Player Bridge (Android - ExoPlayer)

```kotlin
// android/app/src/main/java/com/phlixmobile/player/PhlixPlayerView.kt
package com.phlixmobile.player

import android.app.PictureInPictureParams
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.os.Build
import android.util.Rational
import com.facebook.react.bridge.*
import com.facebook.react.uimanager.annotations.ReactProp
import com.google.android.exoplayer2.*
import com.google.android.exoplayer2.audio.AudioAttributes
import com.google.android.exoplayer2.audio.AudioSink
import com.google.android.exoplayer2.trackselection.DefaultTrackSelector
import com.google.android.exoplayer2.ui.PlayerView

class PhlixPlayerView(reactContext: ReactApplicationContext) :
    ReactViewManager(reactContext) {
    
    private var player: ExoPlayer? = null
    private var playerView: PlayerView? = null
    private var trackSelector: DefaultTrackSelector? = null
    private var src: String = ""
    private var autoPlay: Boolean = true
    private var startPosition: Long = 0
    
    override fun getName(): String = "PhlixPlayerView"
    
    override fun createViewInstance(reactContext: ThemedReactContext): PlayerView {
        trackSelector = DefaultTrackSelector(reactContext)
        player = ExoPlayer.Builder(reactContext)
            .setTrackSelector(trackSelector!!)
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setContentType(C.AUDIO_CONTENT_TYPE_MOVIE)
                    .setUsage(C.USAGE_MEDIA)
                    .build(),
                true
            )
            .setHandleAudioBecomingNoisy(true)
            .build()
        
        playerView = PlayerView(reactContext).apply {
            this.player = player
            useController = false
        }
        
        player?.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(playbackState: Int) {
                when (playbackState) {
                    Player.STATE_READY -> sendEvent("onPlaybackEvent", Arguments.createMap().apply {
                        putString("event", "ready")
                    })
                    Player.STATE_ENDED -> sendEvent("onPlaybackEvent", Arguments.createMap().apply {
                        putString("event", "ended")
                    })
                }
            }
            
            override fun onPlayerError(error: PlaybackException) {
                sendEvent("onError", Arguments.createMap().apply {
                    putString("error", error.message)
                })
            }
        })
        
        return playerView!!
    }
    
    @ReactProp(name = "src")
    fun setSrc(view: PlayerView, src: String) {
        this.src = src
        if (src.isNotEmpty()) {
            loadVideo(src)
        }
    }
    
    @ReactProp(name = "autoPlay")
    fun setAutoPlay(view: PlayerView, autoPlay: Boolean) {
        this.autoPlay = autoPlay
    }
    
    @ReactProp(name = "startPosition")
    fun setStartPosition(view: PlayerView, position: Double) {
        this.startPosition = (position * 1000).toLong()
    }
    
    private fun loadVideo(url: String) {
        val mediaItem = MediaItem.fromUri(url)
        player?.setMediaItem(mediaItem)
        player?.prepare()
        
        if (startPosition > 0) {
            player?.seekTo(startPosition)
        }
        
        if (autoPlay) {
            player?.play()
        }
    }
    
    @ReactProp(name = "volume")
    fun setVolume(view: PlayerView, volume: Float) {
        player?.volume = volume
    }
    
    @ReactProp(name = "muted")
    fun setMuted(view: PlayerView, muted: Boolean) {
        player?.volume = if (muted) 0f else player?.volume ?: 1f
    }
    
    @ReactProp(name = "rate")
    fun setRate(view: PlayerView, rate: Float) {
        player?.setPlaybackSpeed(rate)
    }
    
    @ReactProp(name = "pictureInPicture")
    fun setPictureInPicture(view: PlayerView, enabled: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && enabled) {
            val params = PictureInPictureParams.Builder()
                .setAspectRatio(Rational(16, 9))
                .build()
            (reactApplicationContext.currentActivity as? MainActivity)?.enterPictureInPictureMode(params.build())
        }
    }
    
    // Exposed methods
    @ReactMethod
    fun play() {
        player?.play()
        sendEvent("onPlaybackEvent", Arguments.createMap().apply {
            putString("event", "play")
        })
    }
    
    @ReactMethod
    fun pause() {
        player?.pause()
        sendEvent("onPlaybackEvent", Arguments.createMap().apply {
            putString("event", "pause")
        })
    }
    
    @ReactMethod
    fun seekTo(position: Double) {
        player?.seekTo((position * 1000).toLong())
    }
    
    @ReactMethod
    fun getCurrentPosition(callback: Callback) {
        callback.invoke(player?.currentPosition?.toDouble()?.div(1000))
    }
    
    @ReactMethod
    fun getDuration(callback: Callback) {
        callback.invoke(player?.duration?.toDouble()?.div(1000))
    }
    
    private fun sendEvent(name: String, params: WritableMap) {
        reactApplicationContext
            .getJSModule(DeviceEventManagerModule.RCTDeviceEventEmitter::class.java)
            .emit(name, params)
    }
    
    override fun onDropViewInstance(view: PlayerView) {
        super.onDropViewInstance(view)
        player?.release()
        player = null
    }
}
```

### 5.3 Player Screen Implementation

```typescript
// src/screens/PlayerScreen.tsx
import React, { useEffect, useRef, useState, useCallback } from 'react';
import {
  View,
  StyleSheet,
  TouchableOpacity,
  Dimensions,
  StatusBar,
  Animated,
  Platform,
} from 'react-native';
import { useRoute, useNavigation, RouteProp } from '@react-navigation/native';
import { usePlayerStore } from '../stores/usePlayerStore';
import { playbackManager } from '../api/PlaybackManager';
import PlayerControls from '../components/player/PlayerControls';
import SeekBar from '../components/player/SeekBar';
import SubtitleSelector from '../components/player/SubtitleSelector';
import AudioSelector from '../components/player/AudioSelector';
import QualitySelector from '../components/player/QualitySelector';
import { StreamInfo } from '../types/playback';

const { width: SCREEN_WIDTH, height: SCREEN_HEIGHT } = Dimensions.get('window');

type PlayerRouteParams = {
  Player: {
    itemId: string;
    startPosition?: number;
  };
};

const PlayerScreen: React.FC = () => {
  const route = useRoute<RouteProp<PlayerRouteParams, 'Player'>>();
  const navigation = useNavigation();
  const { itemId, startPosition = 0 } = route.params;

  // Player state
  const [streamInfo, setStreamInfo] = useState<StreamInfo | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showControls, setShowControls] = useState(true);
  const [currentTime, setCurrentTime] = useState(startPosition);
  const [duration, setDuration] = useState(0);
  const [isPlaying, setIsPlaying] = useState(false);
  const [quality, setQuality] = useState<string>('auto');
  const [subtitles, setSubtitles] = useState<SubtitleTrack[]>([]);
  const [audioTracks, setAudioTracks] = useState<AudioTrack[]>([]);

  const controlsOpacity = useRef(new Animated.Value(1)).current;
  const hideControlsTimeout = useRef<NodeJS.Timeout | null>(null);

  // Load playback info
  useEffect(() => {
    loadPlaybackInfo();
  }, [itemId]);

  // Auto-hide controls
  useEffect(() => {
    if (showControls && isPlaying) {
      hideControlsTimeout.current = setTimeout(() => {
        hideControls();
      }, 3000);
    }
    return () => {
      if (hideControlsTimeout.current) {
        clearTimeout(hideControlsTimeout.current);
      }
    };
  }, [showControls, isPlaying]);

  const loadPlaybackInfo = async () => {
    try {
      setIsLoading(true);
      setError(null);

      const deviceProfile = getDeviceProfile();
      const info = await playbackManager.getPlaybackInfo(itemId, deviceProfile);

      setStreamInfo(info.stream_info);
      setSubtitles(info.subtitle_tracks);
      setAudioTracks(info.audio_tracks);
      setDuration(info.stream_info.duration_seconds);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load video');
    } finally {
      setIsLoading(false);
    }
  };

  const getDeviceProfile = (): DeviceProfile => {
    return {
      name: Platform.OS === 'ios' ? 'iPhone' : 'Android',
      platform: Platform.OS,
      version: Platform.Version.toString(),
      capabilities: {
        video_codecs: ['h264', 'h265', 'vp9'],
        audio_codecs: ['aac', 'ac3', 'eac3', 'flac', 'mp3'],
        max_resolution: 2160,
        max_bitrate: 50000000,
        supports_4k: true,
        supports_hdr: true,
        supports_dolby_vision: true,
        supports_dolby_atmos: true,
        supports_dts: true,
      },
    };
  };

  const showControlsTemporarily = () => {
    setShowControls(true);
    controlsOpacity.setValue(1);
  };

  const hideControls = () => {
    Animated.timing(controlsOpacity, {
      toValue: 0,
      duration: 300,
      useNativeDriver: true,
    }).start(() => setShowControls(false));
  };

  const toggleControls = () => {
    if (showControls) {
      hideControls();
    } else {
      showControlsTemporarily();
    }
  };

  const handlePlay = () => {
    setIsPlaying(true);
    // Call native player play
    PhlixPlayer.play();
  };

  const handlePause = () => {
    setIsPlaying(false);
    PhlixPlayer.pause();
  };

  const handleSeek = (position: number) => {
    setCurrentTime(position);
    PhlixPlayer.seekTo(position);
  };

  const handleProgress = (progress: { currentTime: number; duration: number }) => {
    setCurrentTime(progress.currentTime);
    setDuration(progress.duration);

    // Report progress to server periodically
    playbackManager.reportProgress(sessionId, {
      position_ticks: Math.floor(progress.currentTime * 10000000),
      duration_ticks: Math.floor(progress.duration * 10000000),
      is_paused: !isPlaying,
      volume_level: 1.0,
    });
  };

  const handlePlaybackEnded = () => {
    setIsPlaying(false);
    playbackManager.reportStopped(sessionId);
    navigation.goBack();
  };

  // Native player ref
  const playerRef = useRef<PhlixPlayer>(null);

  if (isLoading) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" color="#fff" />
      </View>
    );
  }

  if (error) {
    return (
      <View style={styles.errorContainer}>
        <Text style={styles.errorText}>{error}</Text>
        <TouchableOpacity style={styles.retryButton} onPress={loadPlaybackInfo}>
          <Text style={styles.retryButtonText}>Retry</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <StatusBar hidden />

      {/* Video Player Native View */}
      <TouchableOpacity
        activeOpacity={1}
        onPress={toggleControls}
        style={styles.playerWrapper}
      >
        <PhlixPlayerView
          ref={playerRef}
          style={styles.player}
          src={streamInfo?.url || ''}
          autoPlay={true}
          startPosition={startPosition}
          onPlaybackEvent={handlePlaybackEvent}
          onProgress={handleProgress}
          onError={(e) => setError(e.error)}
        />
      </TouchableOpacity>

      {/* Overlay Controls */}
      {showControls && (
        <Animated.View style={[styles.controlsOverlay, { opacity: controlsOpacity }]}>
          <PlayerControls
            isPlaying={isPlaying}
            onPlay={handlePlay}
            onPause={handlePause}
            onSeekBackward={handleSeekBackward}
            onSeekForward={handleSeekForward}
            onClose={() => navigation.goBack()}
          />

          <View style={styles.bottomControls}>
            <SeekBar
              currentTime={currentTime}
              duration={duration}
              onSeek={handleSeek}
            />

            <View style={styles.optionSelectors}>
              <SubtitleSelector
                tracks={subtitles}
                currentTrackId={currentSubtitleId}
                onSelectTrack={handleSubtitleSelect}
              />
              <AudioSelector
                tracks={audioTracks}
                currentTrackId={currentAudioId}
                onSelectTrack={handleAudioSelect}
              />
              <QualitySelector
                currentQuality={quality}
                onSelectQuality={setQuality}
              />
            </View>
          </View>
        </Animated.View>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#000',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#000',
  },
  errorContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#000',
    padding: 20,
  },
  errorText: {
    color: '#fff',
    fontSize: 16,
    textAlign: 'center',
    marginBottom: 20,
  },
  retryButton: {
    backgroundColor: '#0066cc',
    paddingHorizontal: 30,
    paddingVertical: 10,
    borderRadius: 5,
  },
  retryButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
  playerWrapper: {
    flex: 1,
  },
  player: {
    flex: 1,
  },
  controlsOverlay: {
    ...StyleSheet.absoluteFillObject,
    justifyContent: 'space-between',
    backgroundColor: 'rgba(0,0,0,0.4)',
  },
  bottomControls: {
    paddingHorizontal: 20,
    paddingBottom: 30,
  },
  optionSelectors: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    marginTop: 15,
    gap: 20,
  },
});

export default PlayerScreen;
```

---

## 6. Navigation & UI

### 6.1 Navigation Structure

```typescript
// src/navigation/RootNavigator.tsx
import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { useAuthStore } from '../stores/useAuthStore';
import { View, Text, StyleSheet } from 'react-native';

// Screens
import HomeScreen from '../screens/HomeScreen';
import LibraryScreen from '../screens/LibraryScreen';
import MediaDetailScreen from '../screens/MediaDetailScreen';
import SeasonDetailScreen from '../screens/SeasonDetailScreen';
import PlayerScreen from '../screens/PlayerScreen';
import SearchScreen from '../screens/SearchScreen';
import SettingsScreen from '../screens/SettingsScreen';
import DownloadsScreen from '../screens/DownloadsScreen';
import ProfilesScreen from '../screens/ProfilesScreen';
import LoginScreen from '../screens/LoginScreen';

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();

// Tab Bar Icon Component
const TabIcon = ({ name, focused }: { name: string; focused: boolean }) => {
  const icons: Record<string, string> = {
    Home: '🏠',
    Library: '📚',
    Search: '🔍',
    Downloads: '⬇️',
    Settings: '⚙️',
  };
  
  return (
    <View style={[styles.tabIcon, focused && styles.tabIconFocused]}>
      <Text style={styles.tabIconText}>{icons[name]}</Text>
    </View>
  );
};

// Home Stack
const HomeStackNavigator = () => (
  <Stack.Navigator
    screenOptions={{
      headerStyle: { backgroundColor: '#1a1a2e' },
      headerTintColor: '#fff',
      headerTitleStyle: { fontWeight: '600' },
    }}
  >
    <Stack.Screen name="HomeMain" component={HomeScreen} options={{ headerShown: false }} />
    <Stack.Screen name="MediaDetail" component={MediaDetailScreen} options={{ headerShown: false }} />
    <Stack.Screen name="SeasonDetail" component={SeasonDetailScreen} options={{ headerShown: false }} />
  </Stack.Navigator>
);

// Library Stack
const LibraryStackNavigator = () => (
  <Stack.Navigator
    screenOptions={{
      headerStyle: { backgroundColor: '#1a1a2e' },
      headerTintColor: '#fff',
    }}
  >
    <Stack.Screen name="LibraryMain" component={LibraryScreen} options={{ title: 'My Library' }} />
    <Stack.Screen name="MediaDetail" component={MediaDetailScreen} options={{ headerShown: false }} />
  </Stack.Navigator>
);

// Tab Navigator
const TabNavigator = () => (
  <Tab.Navigator
    screenOptions={({ route }) => ({
      headerShown: false,
      tabBarStyle: {
        backgroundColor: '#1a1a2e',
        borderTopColor: '#2d2d44',
        height: 60,
        paddingBottom: 8,
        paddingTop: 8,
      },
      tabBarActiveTintColor: '#0066cc',
      tabBarInactiveTintColor: '#888',
      tabBarIcon: ({ focused }) => <TabIcon name={route.name} focused={focused} />,
    })}
  >
    <Tab.Screen name="Home" component={HomeStackNavigator} />
    <Tab.Screen name="Library" component={LibraryStackNavigator} />
    <Tab.Screen name="Search" component={SearchScreen} options={{ headerShown: false }} />
    <Tab.Screen name="Downloads" component={DownloadsScreen} options={{ headerShown: false }} />
    <Tab.Screen name="Settings" component={SettingsScreen} options={{ headerShown: false }} />
  </Tab.Navigator>
);

// Root Navigator
const RootNavigator = () => {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);

  return (
    <NavigationContainer>
      <Stack.Navigator screenOptions={{ headerShown: false }}>
        {!isAuthenticated ? (
          <Stack.Screen name="Login" component={LoginScreen} />
        ) : (
          <>
            <Stack.Screen name="Main" component={TabNavigator} />
            <Stack.Screen
              name="Player"
              component={PlayerScreen}
              options={{
                presentation: 'fullScreenModal',
                animation: 'fade',
              }}
            />
            <Stack.Screen
              name="Profiles"
              component={ProfilesScreen}
              options={{
                presentation: 'modal',
              }}
            />
          </>
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
};

const styles = StyleSheet.create({
  tabIcon: {
    width: 30,
    height: 30,
    justifyContent: 'center',
    alignItems: 'center',
  },
  tabIconFocused: {
    transform: [{ scale: 1.1 }],
  },
  tabIconText: {
    fontSize: 20,
  },
});

export default RootNavigator;
```

### 6.2 Home Screen

```typescript
// src/screens/HomeScreen.tsx
import React, { useEffect, useState, useCallback } from 'react';
import {
  View,
  ScrollView,
  StyleSheet,
  RefreshControl,
  Dimensions,
  TouchableOpacity,
  Text,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { libraryManager } from '../api/LibraryManager';
import { authManager } from '../api/AuthManager';
import { MediaItem } from '../types/media';
import PosterCard from '../components/media/PosterCard';
import MediaList from '../components/media/MediaList';
import ContinueWatching from '../components/media/ContinueWatching';
import { useAuthStore } from '../stores/useAuthStore';

const { width: SCREEN_WIDTH } = Dimensions.get('window');
const POSTER_WIDTH = (SCREEN_WIDTH - 60) / 3;
const POSTER_HEIGHT = POSTER_WIDTH * 1.5;

type HomeNavigationProp = NativeStackNavigationProp<any>;

const HomeScreen: React.FC = () => {
  const navigation = useNavigation<HomeNavigationProp>();
  const user = useAuthStore((state) => state.user);

  const [recentlyAdded, setRecentlyAdded] = useState<MediaItem[]>([]);
  const [continueWatching, setContinueWatching] = useState<MediaItem[]>([]);
  const [libraries, setLibraries] = useState<any[]>([]);
  const [libraryItems, setLibraryItems] = useState<Record<string, MediaItem[]>>({});
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    loadHomeData();
  }, []);

  const loadHomeData = async () => {
    try {
      const [recent, continueList, libs] = await Promise.all([
        libraryManager.getRecentlyAdded(20),
        user ? libraryManager.getContinueWatching(user.id) : Promise.resolve([]),
        libraryManager.getLibraries(),
      ]);

      setRecentlyAdded(recent);
      setContinueWatching(continueList);
      setLibraries(libs);

      // Load items for each library (first page)
      const itemsPromises = libs.slice(0, 3).map(async (lib) => {
        const items = await libraryManager.getLibraryItems(lib.id, { limit: 10 });
        return { [lib.id]: items.items };
      });

      const itemsResults = await Promise.all(itemsPromises);
      const itemsMap = itemsResults.reduce((acc, curr) => ({ ...acc, ...curr }), {});
      setLibraryItems(itemsMap);
    } catch (error) {
      console.error('Failed to load home data:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleRefresh = async () => {
    setIsRefreshing(true);
    await loadHomeData();
    setIsRefreshing(false);
  };

  const handleMediaPress = (item: MediaItem) => {
    navigation.navigate('MediaDetail', { itemId: item.id });
  };

  const handlePlayPress = (item: MediaItem) => {
    navigation.navigate('Player', { itemId: item.id });
  };

  const handleContinueWatchingPress = (item: MediaItem) => {
    navigation.navigate('Player', {
      itemId: item.id,
      startPosition: item.user_data?.resume_position_ticks
        ? item.user_data.resume_position_ticks / 10000000
        : 0,
    });
  };

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <View>
          <Text style={styles.greeting}>Hello, {user?.display_name || 'User'}</Text>
          <Text style={styles.subtitle}>What would you like to watch?</Text>
        </View>
        <TouchableOpacity
          style={styles.profilesButton}
          onPress={() => navigation.navigate('Profiles')}
        >
          <Text style={styles.profilesButtonText}>👤</Text>
        </TouchableOpacity>
      </View>

      <ScrollView
        style={styles.scrollView}
        refreshControl={
          <RefreshControl
            refreshing={isRefreshing}
            onRefresh={handleRefresh}
            tintColor="#fff"
          />
        }
        showsVerticalScrollIndicator={false}
      >
        {/* Continue Watching */}
        {continueWatching.length > 0 && (
          <ContinueWatching
            items={continueWatching}
            onItemPress={handleContinueWatchingPress}
            onItemPlay={handlePlayPress}
          />
        )}

        {/* Recently Added */}
        {recentlyAdded.length > 0 && (
          <MediaList
            title="Recently Added"
            items={recentlyAdded}
            onItemPress={handleMediaPress}
            cardWidth={POSTER_WIDTH}
            cardHeight={POSTER_HEIGHT}
          />
        )}

        {/* Library Rows */}
        {libraries.slice(0, 3).map((library) => (
          <MediaList
            key={library.id}
            title={library.name}
            items={libraryItems[library.id] || []}
            onItemPress={handleMediaPress}
            cardWidth={POSTER_WIDTH}
            cardHeight={POSTER_HEIGHT}
          />
        ))}

        <View style={styles.bottomPadding} />
      </ScrollView>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingTop: 50,
    paddingBottom: 15,
    backgroundColor: '#0f0f1a',
  },
  greeting: {
    fontSize: 24,
    fontWeight: '700',
    color: '#fff',
  },
  subtitle: {
    fontSize: 14,
    color: '#888',
    marginTop: 4,
  },
  profilesButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#2d2d44',
    justifyContent: 'center',
    alignItems: 'center',
  },
  profilesButtonText: {
    fontSize: 20,
  },
  scrollView: {
    flex: 1,
  },
  bottomPadding: {
    height: 100,
  },
});

export default HomeScreen;
```

### 6.3 Media Detail Screen

```typescript
// src/screens/MediaDetailScreen.tsx
import React, { useEffect, useState } from 'react';
import {
  View,
  ScrollView,
  StyleSheet,
  Dimensions,
  TouchableOpacity,
  Text,
  Platform,
} from 'react-native';
import { useRoute, useNavigation, RouteProp } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import LinearGradient from 'react-native-linear-gradient';
import { libraryManager } from '../api/LibraryManager';
import { playbackManager } from '../api/PlaybackManager';
import { MediaItem, Series, Season, Episode } from '../types/media';
import PosterCard from '../components/media/PosterCard';
import EpisodeCard from '../components/media/EpisodeCard';
import SeasonCard from '../components/media/SeasonCard';

const { width: SCREEN_WIDTH } = Dimensions.get('window');

type DetailRouteParams = {
  MediaDetail: { itemId: string };
};

type DetailNavigationProp = NativeStackNavigationProp<any>;

const MediaDetailScreen: React.FC = () => {
  const route = useRoute<RouteProp<DetailRouteParams, 'MediaDetail'>>();
  const navigation = useNavigation<DetailNavigationProp>();
  const { itemId } = route.params;

  const [item, setItem] = useState<MediaItem | null>(null);
  const [seasons, setSeasons] = useState<Season[]>([]);
  const [selectedSeason, setSelectedSeason] = useState<Season | null>(null);
  const [episodes, setEpisodes] = useState<Episode[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    loadMediaDetails();
  }, [itemId]);

  useEffect(() => {
    if (selectedSeason) {
      loadEpisodes(selectedSeason.id);
    }
  }, [selectedSeason]);

  const loadMediaDetails = async () => {
    try {
      setIsLoading(true);
      const mediaItem = await libraryManager.getMediaItem(itemId);
      setItem(mediaItem);

      if (mediaItem.type === 'series') {
        const seasonList = await libraryManager.getSeasons(itemId);
        setSeasons(seasonList);
        if (seasonList.length > 0) {
          setSelectedSeason(seasonList[0]);
        }
      }
    } catch (error) {
      console.error('Failed to load media details:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const loadEpisodes = async (seasonId: string) => {
    try {
      const episodeList = await libraryManager.getEpisodes(seasonId);
      setEpisodes(episodeList);
    } catch (error) {
      console.error('Failed to load episodes:', error);
    }
  };

  const handlePlay = () => {
    navigation.navigate('Player', { itemId, startPosition: 0 });
  };

  const handleResume = () => {
    const resumePosition = item?.user_data?.resume_position_ticks
      ? item.user_data.resume_position_ticks / 10000000
      : 0;
    navigation.navigate('Player', { itemId, startPosition: resumePosition });
  };

  const handleEpisodePress = (episode: Episode) => {
    navigation.navigate('Player', {
      itemId: episode.id,
      startPosition: episode.user_data?.resume_position_ticks
        ? episode.user_data.resume_position_ticks / 10000000
        : 0,
    });
  };

  const formatRuntime = (ticks: number): string => {
    const minutes = Math.floor(ticks / 600000000);
    return `${minutes} min`;
  };

  if (isLoading || !item) {
    return (
      <View style={styles.loadingContainer}>
        <Text style={styles.loadingText}>Loading...</Text>
      </View>
    );
  }

  const isSeries = item.type === 'series';
  const hasResumePosition = item.user_data?.resume_position_ticks > 0;
  const userRating = item.user_data?.rating;

  return (
    <View style={styles.container}>
      <ScrollView style={styles.scrollView} bounces={false}>
        {/* Backdrop with Gradient */}
        <View style={styles.backdropContainer}>
          <Image
            source={{ uri: item.backdrop_url || item.poster_url }}
            style={styles.backdrop}
            resizeMode="cover"
          />
          <LinearGradient
            colors={['transparent', '#0f0f1a']}
            style={styles.backdropGradient}
          />
        </View>

        {/* Poster and Info */}
        <View style={styles.infoContainer}>
          <PosterCard
            item={item}
            width={SCREEN_WIDTH * 0.4}
            height={SCREEN_WIDTH * 0.4 * 1.5}
            onPress={() => {}}
          />

          <View style={styles.infoContent}>
            <Text style={styles.title}>{item.name}</Text>
            
            <View style={styles.metaRow}>
              {item.year && <Text style={styles.year}>{item.year}</Text>}
              {item.official_rating && (
                <>
                  <Text style={styles.dot}>•</Text>
                  <Text style={styles.rating}>{item.official_rating}</Text>
                </>
              )}
              {item.run_time_ticks && (
                <>
                  <Text style={styles.dot}>•</Text>
                  <Text style={styles.runtime}>{formatRuntime(item.run_time_ticks)}</Text>
                </>
              )}
            </View>

            {/* User Rating */}
            {userRating && (
              <View style={styles.userRating}>
                <Text style={styles.userRatingText}>★ {userRating}</Text>
              </View>
            )}

            {/* Play/Resume Button */}
            <TouchableOpacity
              style={[styles.playButton, styles.playButtonPrimary]}
              onPress={hasResumePosition ? handleResume : handlePlay}
            >
              <Text style={styles.playButtonIcon}>
                {hasResumePosition ? '▶️' : '▶️'}
              </Text>
              <Text style={styles.playButtonText}>
                {hasResumePosition ? 'Resume' : 'Play'}
              </Text>
            </TouchableOpacity>

            {/* Trailer Button */}
            <TouchableOpacity style={styles.playButton} onPress={() => {}}>
              <Text style={styles.playButtonText}>Trailer</Text>
            </TouchableOpacity>
          </View>
        </View>

        {/* Overview */}
        {item.overview && (
          <View style={styles.section}>
            <Text style={styles.overview}>{item.overview}</Text>
          </View>
        )}

        {/* Genres */}
        {item.genres && item.genres.length > 0 && (
          <View style={styles.genres}>
            {item.genres.map((genre) => (
              <View key={genre} style={styles.genreTag}>
                <Text style={styles.genreText}>{genre}</Text>
              </View>
            ))}
          </View>
        )}

        {/* Series Sections */}
        {isSeries && (
          <>
            {/* Season Selector */}
            <View style={styles.seasonSelector}>
              <ScrollView horizontal showsHorizontalScrollIndicator={false}>
                {seasons.map((season) => (
                  <SeasonCard
                    key={season.id}
                    season={season}
                    isSelected={selectedSeason?.id === season.id}
                    onPress={() => setSelectedSeason(season)}
                  />
                ))}
              </ScrollView>
            </View>

            {/* Episodes */}
            <View style={styles.episodesList}>
              {episodes.map((episode) => (
                <EpisodeCard
                  key={episode.id}
                  episode={episode}
                  onPress={() => handleEpisodePress(episode)}
                />
              ))}
            </View>
          </>
        )}

        <View style={styles.bottomPadding} />
      </ScrollView>

      {/* Back Button */}
      <TouchableOpacity
        style={styles.backButton}
        onPress={() => navigation.goBack()}
      >
        <Text style={styles.backButtonText}>←</Text>
      </TouchableOpacity>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#0f0f1a',
  },
  loadingText: {
    color: '#fff',
    fontSize: 16,
  },
  scrollView: {
    flex: 1,
  },
  backdropContainer: {
    height: 300,
    position: 'relative',
  },
  backdrop: {
    width: SCREEN_WIDTH,
    height: 300,
  },
  backdropGradient: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    height: 200,
  },
  infoContainer: {
    flexDirection: 'row',
    paddingHorizontal: 20,
    marginTop: -120,
    position: 'relative',
    zIndex: 1,
  },
  infoContent: {
    flex: 1,
    marginLeft: 20,
    paddingTop: 100,
  },
  title: {
    fontSize: 24,
    fontWeight: '700',
    color: '#fff',
    marginBottom: 8,
  },
  metaRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 10,
  },
  year: {
    color: '#aaa',
    fontSize: 14,
  },
  dot: {
    color: '#aaa',
    marginHorizontal: 6,
  },
  rating: {
    color: '#aaa',
    fontSize: 14,
  },
  runtime: {
    color: '#aaa',
    fontSize: 14,
  },
  userRating: {
    backgroundColor: '#2d2d44',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 4,
    alignSelf: 'flex-start',
    marginBottom: 15,
  },
  userRatingText: {
    color: '#ffc107',
    fontSize: 14,
    fontWeight: '600',
  },
  playButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 12,
    paddingHorizontal: 20,
    borderRadius: 8,
    marginBottom: 10,
    backgroundColor: '#2d2d44',
  },
  playButtonPrimary: {
    backgroundColor: '#0066cc',
  },
  playButtonIcon: {
    fontSize: 16,
    marginRight: 8,
  },
  playButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
  section: {
    paddingHorizontal: 20,
    marginTop: 20,
  },
  overview: {
    color: '#ccc',
    fontSize: 14,
    lineHeight: 22,
  },
  genres: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: 20,
    marginTop: 15,
    gap: 8,
  },
  genreTag: {
    backgroundColor: '#2d2d44',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 16,
  },
  genreText: {
    color: '#aaa',
    fontSize: 12,
  },
  seasonSelector: {
    marginTop: 30,
    paddingLeft: 20,
  },
  episodesList: {
    paddingHorizontal: 20,
    marginTop: 15,
  },
  bottomPadding: {
    height: 100,
  },
  backButton: {
    position: 'absolute',
    top: Platform.OS === 'ios' ? 50 : 20,
    left: 20,
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'center',
    alignItems: 'center',
  },
  backButtonText: {
    color: '#fff',
    fontSize: 24,
  },
});

export default MediaDetailScreen;
```

---

## 7. Authentication & Security

### 7.1 Secure Token Storage

```typescript
// src/services/SecureStorage.ts
import * as Keychain from 'react-native-keychain';
import AsyncStorage from '@react-native-async-storage/async-storage';

const SERVICE_NAME = 'com.phlix.mobile';
const ACCESS_TOKEN_KEY = 'access_token';
const REFRESH_TOKEN_KEY = 'refresh_token';

class SecureStorage {
  // Store tokens securely
  async storeTokens(accessToken: string, refreshToken: string): Promise<void> {
    try {
      // Store refresh token in Keychain (more secure)
      await Keychain.setGenericPassword(
        ACCESS_TOKEN_KEY,
        refreshToken,
        { service: `${SERVICE_NAME}.refresh` }
      );

      // Store access token in AsyncStorage (for quick access)
      // In production, consider storing access token in Keychain too
      await AsyncStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
    } catch (error) {
      console.error('Failed to store tokens:', error);
      throw error;
    }
  }

  // Retrieve access token
  async getAccessToken(): Promise<string | null> {
    try {
      return await AsyncStorage.getItem(ACCESS_TOKEN_KEY);
    } catch {
      return null;
    }
  }

  // Retrieve refresh token
  async getRefreshToken(): Promise<string | null> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: `${SERVICE_NAME}.refresh`,
      });
      return credentials ? credentials.password : null;
    } catch {
      return null;
    }
  }

  // Clear all tokens
  async clearTokens(): Promise<void> {
    try {
      await Keychain.resetGenericPassword({ service: `${SERVICE_NAME}.refresh` });
      await AsyncStorage.removeItem(ACCESS_TOKEN_KEY);
    } catch (error) {
      console.error('Failed to clear tokens:', error);
    }
  }

  // Biometric authentication
  async enableBiometric(): Promise<boolean> {
    try {
      const result = await Keychain.setGenericPassword(
        'biometric_enabled',
        'true',
        {
          service: `${SERVICE_NAME}.biometric`,
          accessControl: Keychain.ACCESS_CONTROL.BIOMETRY_ANY,
          accessible: Keychain.ACCESSIBLE.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY,
        }
      );
      return !!result;
    } catch {
      return false;
    }
  }

  async isBiometricEnabled(): Promise<boolean> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: `${SERVICE_NAME}.biometric`,
      });
      return !!credentials;
    } catch {
      return false;
    }
  }

  async authenticateWithBiometric(): Promise<boolean> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: `${SERVICE_NAME}.refresh`,
        authenticationPrompt: {
          title: 'Authenticate to access Phlix',
          subtitle: 'Use biometric authentication',
          cancel: 'Cancel',
        },
      });
      return !!credentials;
    } catch {
      return false;
    }
  }
}

export const secureStorage = new SecureStorage();
```

### 7.2 Auth Store with Zustand

```typescript
// src/stores/useAuthStore.ts
import { create } from 'zustand';
import { authManager } from '../api/AuthManager';
import { secureStorage } from '../services/SecureStorage';

interface User {
  id: string;
  username: string;
  display_name: string;
  avatar_url?: string;
}

interface Server {
  id: string;
  name: string;
  url: string;
  version: string;
}

interface AuthState {
  user: User | null;
  server: Server | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  error: string | null;
  
  // Actions
  login: (serverUrl: string, username: string, password: string) => Promise<void>;
  loginWithToken: (serverUrl: string, token: string) => Promise<void>;
  logout: () => Promise<void>;
  checkAuth: () => Promise<void>;
  setUser: (user: User) => void;
  setServer: (server: Server) => void;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  server: null,
  isAuthenticated: false,
  isLoading: true,
  error: null,

  login: async (serverUrl: string, username: string, password: string) => {
    set({ isLoading: true, error: null });
    try {
      const response = await authManager.login(serverUrl, username, password);
      
      await secureStorage.storeTokens(response.access_token, response.refresh_token);
      
      set({
        user: response.user,
        server: response.server,
        isAuthenticated: true,
        isLoading: false,
      });
    } catch (error) {
      set({
        error: error instanceof Error ? error.message : 'Login failed',
        isLoading: false,
      });
      throw error;
    }
  },

  loginWithToken: async (serverUrl: string, token: string) => {
    set({ isLoading: true, error: null });
    try {
      const response = await authManager.loginWithToken(serverUrl, token);
      
      await secureStorage.storeTokens(response.access_token, response.refresh_token);
      
      set({
        user: response.user,
        server: response.server,
        isAuthenticated: true,
        isLoading: false,
      });
    } catch (error) {
      set({
        error: error instanceof Error ? error.message : 'Login failed',
        isLoading: false,
      });
      throw error;
    }
  },

  logout: async () => {
    set({ isLoading: true });
    try {
      await authManager.logout();
      await secureStorage.clearTokens();
      set({
        user: null,
        server: null,
        isAuthenticated: false,
        isLoading: false,
      });
    } catch (error) {
      // Clear state even on error
      await secureStorage.clearTokens();
      set({
        user: null,
        server: null,
        isAuthenticated: false,
        isLoading: false,
      });
    }
  },

  checkAuth: async () => {
    set({ isLoading: true });
    try {
      const isAuth = await authManager.isAuthenticated();
      if (isAuth) {
        const user = await authManager.getCurrentUser();
        const server = await authManager.getCurrentServer();
        set({
          user,
          server,
          isAuthenticated: true,
          isLoading: false,
        });
      } else {
        set({ isAuthenticated: false, isLoading: false });
      }
    } catch {
      set({ isAuthenticated: false, isLoading: false });
    }
  },

  setUser: (user) => set({ user }),
  setServer: (server) => set({ server }),
}));
```

---

## 8. Background Playback & Media Session

### 8.1 Background Audio (iOS)

```swift
// ios/LocalPods/PhlixAudioSession/PhlixAudioSession.swift
import AVFoundation
import MediaPlayer

class PhlixAudioSession {
    static let shared = PhlixAudioSession()
    
    private let audioSession = AVAudioSession.sharedInstance()
    
    func configure() {
        do {
            // Set up audio session for playback
            try audioSession.setCategory(
                .playback,
                mode: .moviePlayback,
                options: [.allowAirPlay, .allowBluetooth, .allowBluetoothA2DP]
            )
            try audioSession.setActive(true)
            
            // Set up remote command center
            setupRemoteCommandCenter()
            setupNowPlayingInfo()
        } catch {
            print("Failed to configure audio session: \(error)")
        }
    }
    
    private func setupRemoteCommandCenter() {
        let commandCenter = MPRemoteCommandCenter.shared()
        
        // Play command
        commandCenter.playCommand.isEnabled = true
        commandCenter.playCommand.addTarget { [weak self] _ in
            self?.handlePlay()
            return .success
        }
        
        // Pause command
        commandCenter.pauseCommand.isEnabled = true
        commandCenter.pauseCommand.addTarget { [weak self] _ in
            self?.handlePause()
            return .success
        }
        
        // Skip forward/backward
        commandCenter.skipForwardCommand.isEnabled = true
        commandCenter.skipForwardCommand.preferredIntervals = [15]
        commandCenter.skipForwardCommand.addTarget { [weak self] event in
            if let skipEvent = event as? MPSkipIntervalCommandEvent {
                self?.handleSkipForward(skipEvent.interval)
            }
            return .success
        }
        
        commandCenter.skipBackwardCommand.isEnabled = true
        commandCenter.skipBackwardCommand.preferredIntervals = [15]
        commandCenter.skipBackwardCommand.addTarget { [weak self] event in
            if let skipEvent = event as? MPSkipIntervalCommandEvent {
                self?.handleSkipBackward(skipEvent.interval)
            }
            return .success
        }
        
        // Seek command
        commandCenter.changePlaybackPositionCommand.isEnabled = true
        commandCenter.changePlaybackPositionCommand.addTarget { [weak self] event in
            if let positionEvent = event as? MPChangePlaybackPositionCommandEvent {
                self?.handleSeek(to: positionEvent.positionTime)
            }
            return .success
        }
    }
    
    private func setupNowPlayingInfo() {
        var nowPlayingInfo = [String: Any]()
        
        nowPlayingInfo[MPMediaItemPropertyTitle] = "Media Title"
        nowPlayingInfo[MPMediaItemPropertyArtist] = "Phlix"
        nowPlayingInfo[MPNowPlayingInfoPropertyPlaybackRate] = 1.0
        nowPlayingInfo[MPNowPlayingInfoPropertyElapsedPlaybackTime] = 0
        nowPlayingInfo[MPMediaItemPropertyPlaybackDuration] = 0
        
        MPNowPlayingInfoCenter.default().nowPlayingInfo = nowPlayingInfo
    }
    
    func updateNowPlaying(
        title: String,
        artist: String?,
        duration: TimeInterval,
        elapsed: TimeInterval,
        isPlaying: Bool
    ) {
        var nowPlayingInfo = [String: Any]()
        
        nowPlayingInfo[MPMediaItemPropertyTitle] = title
        if let artist = artist {
            nowPlayingInfo[MPMediaItemPropertyArtist] = artist
        }
        nowPlayingInfo[MPNowPlayingInfoPropertyPlaybackRate] = isPlaying ? 1.0 : 0.0
        nowPlayingInfo[MPNowPlayingInfoPropertyElapsedPlaybackTime] = elapsed
        nowPlayingInfo[MPMediaItemPropertyPlaybackDuration] = duration
        
        MPNowPlayingInfoCenter.default().nowPlayingInfo = nowPlayingInfo
    }
    
    // MARK: - Command Handlers (to be connected to React Native)
    private func handlePlay() {
        NotificationCenter.default.post(name: .phlixPlay, object: nil)
    }
    
    private func handlePause() {
        NotificationCenter.default.post(name: .phlixPause, object: nil)
    }
    
    private func handleSkipForward(_ interval: TimeInterval) {
        NotificationCenter.default.post(
            name: .phlixSkipForward,
            object: nil,
            userInfo: ["interval": interval]
        )
    }
    
    private func handleSkipBackward(_ interval: TimeInterval) {
        NotificationCenter.default.post(
            name: .phlixSkipBackward,
            object: nil,
            userInfo: ["interval": interval]
        )
    }
    
    private func handleSeek(to position: TimeInterval) {
        NotificationCenter.default.post(
            name: .phlixSeek,
            object: nil,
            userInfo: ["position": position]
        )
    }
}

// Notification names
extension Notification.Name {
    static let phlixPlay = Notification.Name("phlixPlay")
    static let phlixPause = Notification.Name("phlixPause")
    static let phlixSkipForward = Notification.Name("phlixSkipForward")
    static let phlixSkipBackward = Notification.Name("phlixSkipBackward")
    static let phlixSeek = Notification.Name("phlixSeek")
}
```

### 8.2 Background Audio (Android)

```kotlin
// android/app/src/main/java/com/phlixmobile/player/PhlixMediaSessionManager.kt
package com.phlixmobile.player

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.support.v4.media.MediaMetadataCompat
import android.support.v4.media.session.MediaSessionCompat
import android.support.v4.media.session.PlaybackStateCompat
import androidx.core.app.NotificationCompat
import com.facebook.react.bridge.ReactContext
import com.phlixmobile.MainActivity
import com.phlixmobile.R

class PhlixMediaSessionManager(private val reactContext: ReactContext) {
    
    private var mediaSession: MediaSessionCompat? = null
    private val notificationManager: NotificationManager =
        reactContext.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
    
    companion object {
        const val CHANNEL_ID = "phlix_playback_channel"
        const val NOTIFICATION_ID = 1
    }
    
    init {
        createNotificationChannel()
        setupMediaSession()
    }
    
    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "Phlix Playback",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "Media playback controls"
                setShowBadge(false)
            }
            notificationManager.createNotificationChannel(channel)
        }
    }
    
    private fun setupMediaSession() {
        mediaSession = MediaSessionCompat(reactContext, "PhlixMediaSession").apply {
            setFlags(
                MediaSessionCompat.FLAG_HANDLES_MEDIA_BUTTONS or
                MediaSessionCompat.FLAG_HANDLES_TRANSPORT_CONTROLS
            )
            
            setCallback(object : MediaSessionCompat.Callback() {
                override fun onPlay() {
                    sendBroadcast(Intent("com.phlix.PLAY"))
                }
                
                override fun onPause() {
                    sendBroadcast(Intent("com.phlix.PAUSE"))
                }
                
                override fun onSkipToNext() {
                    sendBroadcast(Intent("com.phlix.SKIP_FORWARD"))
                }
                
                override fun onSkipToPrevious() {
                    sendBroadcast(Intent("com.phlix.SKIP_BACKWARD"))
                }
                
                override fun onSeekTo(pos: Long) {
                    val intent = Intent("com.phlix.SEEK").apply {
                        putExtra("position", pos)
                    }
                    sendBroadcast(intent)
                }
                
                override fun onStop() {
                    sendBroadcast(Intent("com.phlix.STOP"))
                }
            })
            
            isActive = true
        }
    }
    
    fun updatePlaybackState(
        isPlaying: Boolean,
        position: Long,
        duration: Long,
        playbackSpeed: Float = 1f
    ) {
        val state = PlaybackStateCompat.Builder()
            .setActions(
                PlaybackStateCompat.ACTION_PLAY or
                PlaybackStateCompat.ACTION_PAUSE or
                PlaybackStateCompat.ACTION_SKIP_TO_NEXT or
                PlaybackStateCompat.ACTION_SKIP_TO_PREVIOUS or
                PlaybackStateCompat.ACTION_SEEK_TO or
                PlaybackStateCompat.ACTION_STOP
            )
            .setState(
                if (isPlaying) PlaybackStateCompat.STATE_PLAYING else PlaybackStateCompat.STATE_PAUSED,
                position,
                playbackSpeed
            )
            .build()
        
        mediaSession?.setPlaybackState(state)
    }
    
    fun updateMetadata(title: String, artist: String?, duration: Long) {
        val metadata = MediaMetadataCompat.Builder()
            .putString(MediaMetadataCompat.METADATA_KEY_TITLE, title)
            .putString(MediaMetadataCompat.METADATA_KEY_ARTIST, artist ?: "Phlix")
            .putLong(MediaMetadataCompat.METADATA_KEY_DURATION, duration)
            .build()
        
        mediaSession?.setMetadata(metadata)
    }
    
    fun showNotification(title: String, isPlaying: Boolean) {
        val contentIntent = PendingIntent.getActivity(
            reactContext,
            0,
            Intent(reactContext, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE
        )
        
        val playPauseAction = if (isPlaying) {
            NotificationCompat.Action(
                android.R.drawable.ic_media_pause,
                "Pause",
                PendingIntent.getBroadcast(
                    reactContext,
                    0,
                    Intent("com.phlix.PAUSE"),
                    PendingIntent.FLAG_IMMUTABLE
                )
            )
        } else {
            NotificationCompat.Action(
                android.R.drawable.ic_media_play,
                "Play",
                PendingIntent.getBroadcast(
                    reactContext,
                    0,
                    Intent("com.phlix.PLAY"),
                    PendingIntent.FLAG_IMMUTABLE
                )
            )
        }
        
        val notification = NotificationCompat.Builder(reactContext, CHANNEL_ID)
            .setContentTitle(title)
            .setContentText("Phlix")
            .setSmallIcon(android.R.drawable.ic_media_play)
            .setContentIntent(contentIntent)
            .addAction(android.R.drawable.ic_media_previous, "Previous", null)
            .addAction(playPauseAction)
            .addAction(android.R.drawable.ic_media_next, "Next", null)
            .setStyle(
                androidx.media.app.NotificationCompat.MediaStyle()
                    .setMediaSession(mediaSession?.sessionToken)
                    .setShowActionsInCompactView(0, 1, 2)
            )
            .setOngoing(isPlaying)
            .build()
        
        notificationManager.notify(NOTIFICATION_ID, notification)
    }
    
    fun release() {
        mediaSession?.release()
        mediaSession = null
    }
}
```

---

## 9. Offline Support & Downloads

### 9.1 Download Service

```typescript
// src/services/DownloadService.ts
import { NativeModules, Platform } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { MediaItem } from '../types/media';

interface DownloadTask {
  id: string;
  itemId: string;
  item: MediaItem;
  status: 'pending' | 'downloading' | 'paused' | 'completed' | 'failed';
  progress: number;
  downloadedBytes: number;
  totalBytes: number;
  localPath: string;
  createdAt: number;
  completedAt?: number;
}

const DOWNLOADS_KEY = 'phlix_downloads';

class DownloadService {
  private downloads: Map<string, DownloadTask> = new Map();
  private listeners: Set<(task: DownloadTask) => void> = new Set();

  constructor() {
    this.loadDownloads();
  }

  // Load downloads from storage
  private async loadDownloads(): Promise<void> {
    try {
      const data = await AsyncStorage.getItem(DOWNLOADS_KEY);
      if (data) {
        const parsed = JSON.parse(data) as DownloadTask[];
        parsed.forEach((task) => {
          this.downloads.set(task.id, task);
        });
      }
    } catch (error) {
      console.error('Failed to load downloads:', error);
    }
  }

  // Save downloads to storage
  private async saveDownloads(): Promise<void> {
    try {
      const data = Array.from(this.downloads.values());
      await AsyncStorage.setItem(DOWNLOADS_KEY, JSON.stringify(data));
    } catch (error) {
      console.error('Failed to save downloads:', error);
    }
  }

  // Subscribe to download updates
  subscribe(callback: (task: DownloadTask) => void): () => void {
    this.listeners.add(callback);
    return () => this.listeners.delete(callback);
  }

  // Notify listeners
  private notifyListeners(task: DownloadTask): void {
    this.listeners.forEach((listener) => listener(task));
  }

  // Start download
  async startDownload(item: MediaItem): Promise<string> {
    const taskId = `download_${item.id}_${Date.now()}`;
    
    // Get stream info for download
    const streamInfo = await playbackManager.getStreamUrl(item.id, {
      quality: 'original',
    });

    const task: DownloadTask = {
      id: taskId,
      itemId: item.id,
      item,
      status: 'pending',
      progress: 0,
      downloadedBytes: 0,
      totalBytes: streamInfo.size || 0,
      localPath: this.getLocalPath(item),
      createdAt: Date.now(),
    };

    this.downloads.set(taskId, task);
    await this.saveDownloads();
    this.notifyListeners(task);

    // Start native download
    if (Platform.OS === 'ios') {
      PhlixDownloader.startDownload(taskId, streamInfo.url, task.localPath);
    } else {
      PhlixDownloader.startDownload(taskId, streamInfo.url, task.localPath);
    }

    return taskId;
  }

  // Pause download
  async pauseDownload(taskId: string): Promise<void> {
    const task = this.downloads.get(taskId);
    if (task && task.status === 'downloading') {
      PhlixDownloader.pauseDownload(taskId);
      task.status = 'paused';
      this.downloads.set(taskId, task);
      await this.saveDownloads();
      this.notifyListeners(task);
    }
  }

  // Resume download
  async resumeDownload(taskId: string): Promise<void> {
    const task = this.downloads.get(taskId);
    if (task && task.status === 'paused') {
      PhlixDownloader.resumeDownload(taskId);
      task.status = 'downloading';
      this.downloads.set(taskId, task);
      await this.saveDownloads();
      this.notifyListeners(task);
    }
  }

  // Cancel download
  async cancelDownload(taskId: string): Promise<void> {
    const task = this.downloads.get(taskId);
    if (task) {
      PhlixDownloader.cancelDownload(taskId);
      this.downloads.delete(taskId);
      await this.saveDownloads();
      
      // Delete local file
      // FileSystem.delete(task.localPath);
    }
  }

  // Get download progress
  getProgress(taskId: string): number {
    const task = this.downloads.get(taskId);
    if (!task || task.totalBytes === 0) return 0;
    return task.downloadedBytes / task.totalBytes;
  }

  // Get local file path for item
  private getLocalPath(item: MediaItem): string {
    const filename = `${item.id}_${item.name.replace(/[^a-z0-9]/gi, '_')}.mp4`;
    if (Platform.OS === 'ios') {
      return `${NativeModules.PhlixDownloader?.documentsPath || ''}/${filename}`;
    }
    return `/storage/emulated/0/Download/Phlix/${filename}`;
  }

  // Get all downloads
  getAllDownloads(): DownloadTask[] {
    return Array.from(this.downloads.values());
  }

  // Get completed downloads
  getCompletedDownloads(): DownloadTask[] {
    return Array.from(this.downloads.values()).filter(
      (task) => task.status === 'completed'
    );
  }

  // Get download by item ID
  getDownloadForItem(itemId: string): DownloadTask | undefined {
    return Array.from(this.downloads.values()).find(
      (task) => task.itemId === itemId && task.status === 'completed'
    );
  }

  // Handle download progress from native module
  handleProgress(taskId: string, downloadedBytes: number, totalBytes: number): void {
    const task = this.downloads.get(taskId);
    if (task) {
      task.downloadedBytes = downloadedBytes;
      task.totalBytes = totalBytes;
      task.progress = totalBytes > 0 ? downloadedBytes / totalBytes : 0;
      task.status = 'downloading';
      this.downloads.set(taskId, task);
      this.notifyListeners(task);
    }
  }

  // Handle download completion
  handleComplete(taskId: string): void {
    const task = this.downloads.get(taskId);
    if (task) {
      task.status = 'completed';
      task.progress = 1;
      task.completedAt = Date.now();
      this.downloads.set(taskId, task);
      this.saveDownloads();
      this.notifyListeners(task);
    }
  }

  // Handle download error
  handleError(taskId: string, error: string): void {
    const task = this.downloads.get(taskId);
    if (task) {
      task.status = 'failed';
      this.downloads.set(taskId, task);
      this.saveDownloads();
      this.notifyListeners(task);
    }
  }
}

export const downloadService = new DownloadService();
export default downloadService;
```

---

## 10. Push Notifications

### 10.1 iOS Push Notifications

```typescript
// src/services/NotificationService.ts
import PushNotification, { Importance } from 'react-native-push-notification';
import { Platform } from 'react-native';

class NotificationService {
  constructor() {
    this.configure();
  }

  private configure() {
    PushNotification.configure({
      onRegister: function (token) {
        console.log('Push Notification Token:', token);
        // Send token to server
        this.registerTokenWithServer(token.token);
      }.bind(this),

      onNotification: function (notification) {
        console.log('Notification Received:', notification);
        
        // Handle notification based on type
        const { type, data } = notification.data;
        
        switch (type) {
          case 'library_update':
            this.handleLibraryUpdate(data);
            break;
          case 'new_content':
            this.handleNewContent(data);
            break;
          case 'sync_complete':
            this.handleSyncComplete(data);
            break;
          default:
            console.log('Unknown notification type:', type);
        }
      },

      onAction: function (notification) {
        console.log('Notification Action:', notification.action);
        // Handle notification action
      },

      permissions: {
        alert: true,
        badge: true,
        sound: true,
      },

      popInitialNotification: true,
      requestPermissions: Platform.OS === 'ios',
    });

    // Create notification channel for Android
    if (Platform.OS === 'android') {
      PushNotification.createChannel(
        {
          channelId: 'phlix-general',
          channelName: 'General',
          channelDescription: 'General notifications',
          importance: Importance.HIGH,
          vibrate: true,
        },
        (created) => console.log(`Channel created: ${created}`)
      );

      PushNotification.createChannel(
        {
          channelId: 'phlix-playback',
          channelName: 'Playback',
          channelDescription: 'Media playback notifications',
          importance: Importance.LOW,
          playSound: false,
          vibrate: false,
        },
        (created) => console.log(`Playback channel created: ${created}`)
      );
    }
  }

  private async registerTokenWithServer(token: string): Promise<void> {
    try {
      await apiClient.post('/users/push-token', { token });
    } catch (error) {
      console.error('Failed to register push token:', error);
    }
  }

  // Request notification permissions
  async requestPermissions(): Promise<boolean> {
    return new Promise((resolve) => {
      PushNotification.requestPermissions().then((permissions) => {
        resolve(permissions.alert);
      });
    });
  }

  // Local notification
  showLocalNotification(notification: {
    title: string;
    message: string;
    type?: string;
    data?: any;
  }) {
    PushNotification.localNotification({
      title: notification.title,
      message: notification.message,
      userInfo: {
        type: notification.type,
        ...notification.data,
      },
      channelId: 'phlix-general',
      importance: 'high',
      priority: 'high',
    });
  }

  // Playback notification (Android)
  showPlaybackNotification(title: string, isPlaying: boolean) {
    PushNotification.localNotification({
      title: title,
      message: isPlaying ? 'Now Playing' : 'Paused',
      channelId: 'phlix-playback',
      importance: 'low',
      priority: 'low',
      ongoing: true,
      autoCancel: false,
      playSound: false,
      vibrate: false,
    });
  }

  // Cancel playback notification
  cancelPlaybackNotification() {
    PushNotification.cancelLocalNotification('phlix-playback');
  }

  // Handle library update notification
  private handleLibraryUpdate(data: any): void {
    // Navigate to library or refresh content
    // This would typically be handled by the navigation service
    console.log('Library updated:', data);
  }

  // Handle new content notification
  private handleNewContent(data: any): void {
    // Navigate to new content
    console.log('New content available:', data);
  }

  // Handle sync complete notification
  private handleSyncComplete(data: any): void {
    console.log('Sync complete:', data);
  }

  // Badges
  setBadgeCount(count: number) {
    PushNotification.setApplicationIconBadgeNumber(count);
  }

  // Cancel all notifications
  cancelAll() {
    PushNotification.cancelAllLocalNotifications();
  }
}

export const notificationService = new NotificationService();
```

---

## 11. Platform-Specific Features

### 11.1 iOS Features

#### Picture-in-Picture

```swift
// Enable PiP in AppDelegate
func application(_ application: UIApplication, 
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
    
    // Enable Picture in Picture
    // Note: Requires AVPictureInPictureController key in Info.plist
    return true
}

// PiP support in player
extension PhlixPlayerView {
    func setupPictureInPicture() {
        if AVPictureInPictureController.isPictureInPictureSupported() {
            pipController = AVPictureInPictureController(playerLayer: playerLayer)
            pipController?.delegate = self
        }
    }
    
    func startPiP() {
        pipController?.startPictureInPicture()
    }
    
    func stopPiP() {
        pipController?.stopPictureInPicture()
    }
}
```

#### AirPlay Support

```swift
// AirPlay is automatically supported via AVPlayerLayer
// Add AirPlay route button to player controls

let routePickerView = AVRoutePickerView()
routePickerView.tintColor = .white
routePickerView.activeTintColor = .blue

// Add to player controls view
```

### 11.2 Android Features

#### Picture-in-Picture

```kotlin
// Enable PiP in AndroidManifest.xml already done

// PiP handling in MainActivity
override fun onUserLeaveHint() {
    super.onUserLeaveHint()
    // User pressed home button, enter PiP if playing
    if (isPlaying) {
        enterPictureInPictureMode()
    }
}

fun enterPictureInPictureMode() {
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        val params = PictureInPictureParams.Builder()
            .setAspectRatio(Rational(16, 9))
            .build()
        enterPictureInPictureMode(params)
    }
}
```

#### Chromecast

```typescript
// src/components/player/ChromecastButton.tsx
import React from 'react';
import { View, TouchableOpacity, Text, StyleSheet } from 'react-native';

// Note: Full Chromecast implementation requires
// react-native-google-cast or similar library

const ChromecastButton: React.FC = () => {
  const handlePress = () => {
    // Show Cast dialog
    // CastContext.getCastContext().showCastDialog()
  };

  return (
    <TouchableOpacity style={styles.button} onPress={handlePress}>
      <Text style={styles.icon}>📺</Text>
    </TouchableOpacity>
  );
};

const styles = StyleSheet.create({
  button: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: 'rgba(255,255,255,0.2)',
    justifyContent: 'center',
    alignItems: 'center',
  },
  icon: {
    fontSize: 20,
  },
});

export default ChromecastButton;
```

---

## 12. Testing

### 12.1 Unit Tests

```bash
# Install testing dependencies
npm install --save-dev jest @testing-library/react-native @testing-library/jest-native

# Run tests
npm test

# Run with coverage
npm test -- --coverage
```

```typescript
// src/__tests__/api/AuthManager.test.ts
import { authManager } from '../api/AuthManager';

// Mock AsyncStorage
jest.mock('@react-native-async-storage/async-storage', () => ({
  setItem: jest.fn(() => Promise.resolve()),
  getItem: jest.fn(() => Promise.resolve(null)),
  multiRemove: jest.fn(() => Promise.resolve()),
}));

// Mock axios
jest.mock('axios');

describe('AuthManager', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  describe('login', () => {
    it('should store tokens on successful login', async () => {
      const mockResponse = {
        data: {
          access_token: 'test-access-token',
          refresh_token: 'test-refresh-token',
          user: { id: '1', username: 'test', display_name: 'Test User' },
          server: { id: '1', name: 'Test Server', url: 'http://localhost', version: '1.0' },
        },
      };

      axios.post.mockResolvedValue(mockResponse);

      const result = await authManager.login('http://localhost', 'test', 'password');

      expect(result.access_token).toBe('test-access-token');
      expect(result.user.username).toBe('test');
    });

    it('should throw error on failed login', async () => {
      axios.post.mockRejectedValue(new Error('Invalid credentials'));

      await expect(
        authManager.login('http://localhost', 'test', 'wrong')
      ).rejects.toThrow('Invalid credentials');
    });
  });
});
```

### 12.2 Integration Tests

```typescript
// src/__tests__/screens/HomeScreen.test.tsx
import React from 'react';
import { render, waitFor, fireEvent } from '@testing-library/react-native';
import HomeScreen from '../screens/HomeScreen';

// Mock dependencies
jest.mock('../api/LibraryManager');
jest.mock('../stores/useAuthStore');

describe('HomeScreen', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('should display loading state initially', () => {
    const { getByText } = render(<HomeScreen />);
    expect(getByText('Loading...')).toBeTruthy();
  });

  it('should display recently added items after loading', async () => {
    const mockItems = [
      { id: '1', name: 'Movie 1', type: 'movie', poster_url: 'http://example.com/poster1.jpg' },
      { id: '2', name: 'Movie 2', type: 'movie', poster_url: 'http://example.com/poster2.jpg' },
    ];

    libraryManager.getRecentlyAdded.mockResolvedValue(mockItems);

    const { getByText } = render(<HomeScreen />);

    await waitFor(() => {
      expect(getByText('Recently Added')).toBeTruthy();
    });
  });

  it('should navigate to media detail on press', async () => {
    const mockItem = { id: '1', name: 'Test Movie', type: 'movie' };
    libraryManager.getRecentlyAdded.mockResolvedValue([mockItem]);

    const navigate = jest.fn();
    const { getByText } = render(<HomeScreen navigation={{ navigate }} />);

    await waitFor(() => {
      fireEvent.press(getByText('Test Movie'));
    });

    expect(navigate).toHaveBeenCalledWith('MediaDetail', { itemId: '1' });
  });
});
```

### 12.3 E2E Tests ( Detox)

```bash
# Install Detox
npm install --save-dev detox

# Initialize Detox
npx detox init

# Build iOS app
npx detox build --configuration ios.sim.debug

# Run tests
npx detox test --configuration ios.sim.debug
```

```typescript
// e2e/config.ts
import { config } from 'detox';
import { join } from 'path';

config = {
  testEnvironment: './node_modules/wdio-detox/environment',
  specs: ['e2e/**/*.spec.ts'],
  
  configurations: {
    'ios.sim.debug': {
      type: 'ios.simulator',
      binaryPath: join(__dirname, 'ios/build/Build/Products/Debug-iphonesimulator/PhlixMobile.app'),
      build: 'xcodebuild -workspace ios/PhlixMobile.xcworkspace -scheme PhlixMobile -configuration Debug -sdk iphonesimulator -derivedDataPath ios/build',
      type: 'ios.simulator',
    },
  },
};
```

```typescript
// e2e/onboarding.spec.ts
import { describe, it, beforeEach } from 'detox';
import { expect } from 'detox';

describe('Onboarding Flow', () => {
  beforeEach(async () => {
    await device.reloadReactNative();
  });

  it('should show login screen on first launch', async () => {
    await expect(element(by.id('loginScreen'))).toBeVisible();
  });

  it('should login successfully with valid credentials', async () => {
    await element(by.id('serverInput')).typeText('http://localhost:8096');
    await element(by.id('usernameInput')).typeText('testuser');
    await element(by.id('passwordInput')).typeText('password');
    await element(by.id('loginButton')).tap();
    
    await expect(element(by.id('homeScreen'))).toBeVisible();
  });
});
```

---

## 13. App Store Submission

### 13.1 iOS App Store

```bash
# Create App Store distribution build
cd ios

# Update bundle version and build number in project.pbxproj
# Or use fastlane

# Build for App Store
xcodebuild -workspace PhlixMobile.xcworkspace \
  -scheme PhlixMobile \
  -configuration Release \
  -archivePath build/PhlixMobile.xcarchive \
  archive

# Export for App Store Connect
xcodebuild -exportArchive \
  -archivePath build/PhlixMobile.xcarchive \
  -exportOptionsPlist ExportOptions.plist \
  -exportPath build/output

# Or use Transporter app to upload
```

**Required Assets:**
- App Icon (1024x1024)
- Screenshots (multiple sizes for all iPhone/iPad sizes)
- App Preview videos (optional but recommended)
- Privacy policy URL
- Support URL

**Required Info.plist Entries:**
```xml
<key>CFBundleDisplayName</key>
<string>Phlix</string>
<key>LSRequiresIPhoneOS</key>
<true/>
<key>UILaunchStoryboardName</key>
<string>LaunchScreen</string>
<key>UIBackgroundModes</key>
<array>
    <string>audio</string>
    <string>fetch</string>
    <string>remote-notification</string>
</array>
```

### 13.2 Android Play Store

```bash
# Create release build
cd android

# Build release APK
./gradlew assembleRelease

# Or build App Bundle
./gradlew bundleRelease

# Sign APK (if not using Play Signing)
jarsigner -verbose -sigalg SHA1withRSA \
  -digestalg SHA1 \
  -keystore your-keystore.keystore \
  app/build/outputs/apk/release/app-release.apk \
  alias_name

# Upload using Play Console or fastlane
```

**Required Assets:**
- App Icon (512x512)
- Feature Graphic (1024x500)
- Screenshots (phone, 7" tablet, 10" tablet)
- App description
- Privacy policy URL
- Short description (80 characters)
- Long description (4000 characters)

**AndroidManifest.xml Requirements:**
```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_MEDIA_PLAYBACK" />
```

### 13.3 Fastlane Deployment

```ruby
# Fastfile
default_platform(:ios)

platform :ios do
  desc "Build and deploy to App Store"
  lane :deploy do
    # Increment build number
    increment_build_number(xcodeproj: "PhlixMobile.xcodeproj")
    
    # Build
    build_app(
      workspace: "PhlixMobile.xcworkspace",
      scheme: "PhlixMobile",
      configuration: "Release"
    )
    
    # Upload to App Store Connect
    upload_to_app_store(
      app_identifier: "com.phlix.mobile",
      skip_binary_upload: false
    )
  end
end

platform :android do
  desc "Build and deploy to Play Store"
  lane :deploy do
    # Build release
    gradle(task: "assembleRelease")
    
    # Upload to Play Store
    upload_to_play_store(
      package_name: "com.phlix.mobile",
      track: "production",
      json_key_data: ENV["PLAY_STORE_JSON_KEY"]
    )
  end
end
```

---

## 14. Implementation Checklist

### Phase 1: Project Setup
- [ ] Initialize React Native project
- [ ] Install all dependencies
- [ ] Configure iOS CocoaPods
- [ ] Configure Android build
- [ ] Set up project structure
- [ ] Configure ESLint and Prettier

### Phase 2: API Client
- [ ] Implement base API client (Axios)
- [ ] Add authentication interceptors
- [ ] Implement token refresh logic
- [ ] Create AuthManager
- [ ] Create LibraryManager
- [ ] Create PlaybackManager
- [ ] Add TypeScript types

### Phase 3: Native Player Module
- [ ] Create iOS PhlixPlayerView (AVKit)
- [ ] Create Android PhlixPlayerView (ExoPlayer)
- [ ] Implement playback controls
- [ ] Add progress tracking
- [ ] Handle errors gracefully
- [ ] Test on both platforms

### Phase 4: UI/Navigation
- [ ] Set up React Navigation
- [ ] Create tab navigator
- [ ] Implement HomeScreen
- [ ] Implement LibraryScreen
- [ ] Implement MediaDetailScreen
- [ ] Implement PlayerScreen
- [ ] Implement SearchScreen
- [ ] Implement SettingsScreen

### Phase 5: Background Playback
- [ ] Configure iOS audio session
- [ ] Configure Android media session
- [ ] Implement lock screen controls
- [ ] Add notification controls
- [ ] Test background playback
- [ ] Test PiP mode

### Phase 6: Offline Support
- [ ] Implement DownloadService
- [ ] Create download manager native module
- [ ] Add offline playback
- [ ] Implement download queue
- [ ] Add storage management
- [ ] Test downloads

### Phase 7: Polish & Distribution
- [ ] Add push notifications
- [ ] Implement biometric auth
- [ ] Optimize performance
- [ ] Test on multiple devices
- [ ] Create App Store assets
- [ ] Submit for review

---

## Appendix A: Type Definitions

```typescript
// src/types/media.ts
export interface MediaItem {
  id: string;
  name: string;
  type: 'movie' | 'series' | 'music' | 'photo';
  overview?: string;
  poster_url?: string;
  backdrop_url?: string;
  year?: number;
  official_rating?: string;
  run_time_ticks?: number;
  genres?: string[];
  user_data?: UserData;
}

export interface UserData {
  playback_position_ticks?: number;
  resume_position_ticks?: number;
  is_watched?: boolean;
  rating?: number;
  favorite?: boolean;
}

export interface Series extends MediaItem {
  type: 'series';
  series_name?: string;
}

export interface Season {
  id: string;
  series_id: string;
  name: string;
  overview?: string;
  poster_url?: string;
  season_number: number;
  episode_count: number;
}

export interface Episode {
  id: string;
  season_id: string;
  series_id: string;
  name: string;
  overview?: string;
  poster_url?: string;
  episode_number: number;
  season_number: number;
  run_time_ticks?: number;
  user_data?: UserData;
}

// src/types/playback.ts
export interface StreamInfo {
  url: string;
  protocol: 'hls' | 'http';
  container: string;
  size: number;
  bitrate: number;
  duration_seconds: number;
}

export interface SubtitleTrack {
  id: string;
  codec: string;
  language: string;
  display_title: string;
  url?: string;
}

export interface AudioTrack {
  id: string;
  codec: string;
  language: string;
  display_title: string;
  channels: number;
  url?: string;
}
```

---

## Appendix B: Native Module Integration

### iOS Swift Integration

```swift
// ios/LocalPods/PhlixPlayer/PhlixPlayerViewManager.swift
@objc(PhlixPlayerViewManager)
class PhlixPlayerViewManager: RCTViewManager {
    override func view() -> UIView! {
        return PhlixPlayerViewWrapper()
    }
    
    override static func requiresMainQueueSetup() -> Bool {
        return true
    }
}
```

### Android Kotlin Integration

```kotlin
// android/app/src/main/java/com/phlixmobile/player/PhlixPlayerPackage.kt
package com.phlixmobile.player

import com.facebook.react.ReactPackage
import com.facebook.react.bridge.NativeModule
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.uimanager.ViewManager

class PhlixPlayerPackage : ReactPackage {
    override fun createNativeModules(reactContext: ReactApplicationContext): List<NativeModule> {
        return emptyList()
    }

    override fun createViewManagers(reactContext: ReactApplicationContext): List<ViewManager<*, *>> {
        return listOf(PhlixPlayerView(reactContext))
    }
}
```

---

**Document Status:** Complete  
**Next Steps:** Begin Phase 1 implementation (Project Setup)
