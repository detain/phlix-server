# Phlix Media Server - Roku Application

**Document Version:** 1.0  
**Platform:** Roku  
**Technology:** BrightScript, SceneGraph (RSG)  

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
9. [Roku Store Submission](#9-roku-store-submission)
10. [Implementation Checklist](#10-implementation-checklist)

---

## 1. Overview

### 1.1 Platform Capabilities

Roku devices support:
- **Video Codecs**: H.264, H.265/HEVC (most models), VP9 (Roku 4K+)
- **Audio Codecs**: AAC, AC3, EAC3, DTS, MP3, FLAC, WMA
- **Containers**: MP4, MKV, MOV, TS, WebM (Roku 4K+)
- **Streaming**: HLS, HTTP Progressive, HTTPS
- **Features**: 4K HDR on supported models, surround sound

### 1.2 BrightScript Components

| Component | Purpose |
|-----------|---------|
| `roVideoPlayer` | Basic video playback |
| `roVideoScreen` | Full-screen video playback |
| `roAudioPlayer` | Audio playback |
| `roSpringboardScreen` | Detail screens with actions |
| `roGridScreen` | Grid-based browsing |
| `roPosterScreen` | Poster-based browsing |
| `roParagraphScreen` | Text display |
| `roUrlTransfer` | HTTP requests |
| `roRegistry` | Persistent storage |
| `ifHttpAgent` | HTTP agent interface |

---

## 2. Development Environment

### 2.1 Required Software

```
1. Visual Studio Code with "BrightScript" extension
2. Or BrightScript IDE (Eclipse-based)
3. Roku SDK (included with device)
4. Git
5. Node.js (for build scripts)
```

### 2.2 Environment Setup

```bash
# Create project directory
mkdir phlix-roku
cd phlix-roku

# Initialize git
git init

# Create directory structure
mkdir -p source/components
mkdir -p source/lib
mkdir -p source/pages
mkdir -p images
mkdir -p tests
```

### 2.3 Developer Account Setup

1. **Create Roku Developer Account**
   - Visit https://developer.roku.com
   - Register as a developer

2. **Enable Developer Mode on Device**
   ```
   Home: Press Home 5 times
   Up: Press Up 2 times
   Right: Press Right
   Left: Press Left
   Right: Press Left
   Right: Press Left
   ```
   - Note the IP address shown
   - Go to http://<roku-ip> in browser
   - Enable dev mode and set password

3. **Package Manager (Optional)**
   ```bash
   npm install -g rokupkg
   ```

### 2.4 Project Configuration

```json
// manifest
title=Phlix
major_version=1
minor_version=0
build_version=1

mm_icon_focus_hd=images/icon-focus-hd.png
mm_icon_side_hd=images/icon-side-hd.png
splash_screen_sd=images/splash-sd.png
splash_screen_hd=images/splash-hd.png
splash_screen_fhd=images/splash-fhd.png

splash_color=#1a1a2e
splash_width=960
splash_height=540

ui_resolutions=hd
```

---

## 3. Project Structure

```
phlix-roku/
├── source/
│   ├── main.brs              # Main entry point
│   ├── lib/
│   │   ├── ApiClient.brs     # API client
│   │   ├── AuthManager.brs   # Authentication
│   │   ├── SessionManager.brs # Session management
│   │   ├── LibraryManager.brs # Library browsing
│   │   ├── Storage.brs       # Persistent storage
│   │   ├── TaskManager.brs   # Background tasks
│   │   └── Utilities.brs     # Helper functions
│   ├── components/
│   │   ├── PhlixApp.brs      # Main app component
│   │   ├── HomeScene.brs     # Home screen
│   │   ├── LibraryScene.brs  # Library browser
│   │   ├── DetailScene.brs   # Item detail
│   │   ├── PlayerScene.brs   # Video player
│   │   ├── LoginScene.brs    # Login screen
│   │   └── GridItem.brs      # Grid item component
│   ├── pages/
│   │   ├── HomePage.brs
│   │   ├── LibraryPage.brs
│   │   └── SettingsPage.brs
│   └── data/
│       └── Theme.brs         # Theme constants
├── images/
│   ├── icon-focus-hd.png
│   ├── icon-side-hd.png
│   ├── splash-sd.png
│   ├── splash-hd.png
│   ├── splash-fhd.png
│   └── placeholder.png
├── tests/
│   ├── unit/
│   └── integration/
├── manifest
├── Makefile
└── README.md
```

---

## 4. API Client Implementation

### 4.1 ApiClient Library

```brightscript
' source/lib/ApiClient.brs

' ===========================================
' Phlix API Client for Roku
' Handles all communication with Phlix Media Server
' ===========================================

function ApiClient(baseUrl as String) as Object
    obj = {
        baseUrl: baseUrl
        token: ""
        sessionId: ""
        deviceId: ""
        deviceName: "Roku"
        deviceType: "roku"
        user: invalid
        
        ' Device profile for playback decisions
        deviceProfile: {
            Name: "Roku"
            MaxStreamingBitrate: 30000000
            MaxStaticBitrate: 30000000
            SupportedMediaTypes: ["Video", "Audio"]
            DirectPlayProfiles: [{
                Container: "mp4,m4v,mkv"
                Type: "Video"
                VideoCodec: "h264,hevc"
                AudioCodec: "aac,ac3,eac3,mp3,pcm"
            }]
            TranscodingProfiles: [{
                Container: "ts"
                Type: "Video"
                VideoCodec: "h264"
                AudioCodec: "aac,ac3"
            }]
        }
        
        ' Set authentication token
        setToken: function(token as String)
            m.token = token
            if token <> "" then
                Storage.set("auth_token", token)
            else
                Storage.delete("auth_token")
            end if
        end function
        
        ' Set session ID
        setSession: function(sessionId as String)
            m.sessionId = sessionId
            if sessionId <> "" then
                Storage.set("session_id", sessionId)
            else
                Storage.delete("session_id")
            end if
        end function
        
        ' Restore session from storage
        restoreSession: function() as Boolean
            token = Storage.get("auth_token")
            sessionId = Storage.get("session_id")
            
            if token <> "" then
                m.token = token
                if sessionId <> "" then m.sessionId = sessionId
                
                ' Validate token with server
                user = m.request("GET", "/Users/Me", {})
                if user <> invalid then
                    m.user = user
                    return true
                end if
            end if
            
            m.setToken("")
            m.setSession("")
            return false
        end function
        
        ' Make HTTP request
        request: function(method as String, path as String, body as Object) as Object
            url = m.baseUrl + "/api/v1" + path
            
            http = CreateObject("roUrlTransfer")
            http.SetUrl(url)
            http.SetTimeout(30000)
            http.EnableEncodings(true)
            
            ' Set headers
            http.AddHeader("Content-Type", "application/json")
            http.AddHeader("X-Phlix-Device-ID", m.deviceId)
            http.AddHeader("X-Phlix-Device-Name", m.deviceName)
            http.AddHeader("X-Phlix-Device-Type", m.deviceType)
            
            if m.token <> "" then
                http.AddHeader("Authorization", "Bearer " + m.token)
            end if
            
            if m.sessionId <> "" then
                http.AddHeader("X-Phlix-Session-ID", m.sessionId)
            end if
            
            ' Prepare body
            response = invalid
            if body <> invalid and (method = "POST" or method = "PUT" or method = "PATCH") then
                jsonBody = FormatJSON(body)
                http.SetRequest("POST")
                response = http.PostFromString(jsonBody)
            else
                response = http.GetToString()
            end if
            
            if response <> "" then
                return ParseJSON(response)
            end if
            
            return invalid
        end function
        
        ' Authentication methods
        login: function(username as String, password as String) as Object
            deviceInfo = {
                device_id: m.deviceId
                device_name: m.deviceName
                device_type: m.deviceType
            }
            
            result = m.request("POST", "/Auth/Login", {
                username: username
                password: password
                device_id: deviceInfo.device_id
                device_name: deviceInfo.device_name
                device_type: deviceInfo.device_type
            })
            
            if result <> invalid then
                m.setToken(result.token)
                m.setSession(result.session_id)
                m.user = result.user
            end if
            
            return result
        end function
        
        logout: function()
            if m.sessionId <> "" then
                m.request("DELETE", "/Sessions/" + m.sessionId, {})
            end if
            m.setToken("")
            m.setSession("")
            m.user = invalid
        end function
        
        ' Session management
        createSession: function() as Object
            if m.user = invalid then
                print "Not logged in"
                return invalid
            end if
            
            deviceInfo = {
                device_id: m.deviceId
                device_name: m.deviceName
                device_type: m.deviceType
                capabilities: m.deviceProfile
            }
            
            result = m.request("POST", "/Sessions", deviceInfo)
            if result <> invalid then
                m.setSession(result.id)
            end if
            
            return result
        end function
        
        getSessions: function() as Object
            return m.request("GET", "/Sessions", {})
        end function
        
        ' Library browsing
        getLibraries: function() as Object
            return m.request("GET", "/Library/VirtualFolders", {})
        end function
        
        getLibraryItems: function(libraryId as String, options = {} as Object) as Object
            params = []
            params.push("parentId=" + libraryId)
            params.push("includeItemTypes=" + chr(37) + "Movie,Series")
            params.push("limit=" + str(50).trim())
            params.push("startIndex=" + str(0).trim())
            params.push("sortBy=SortName")
            params.push("sortOrder=Ascending")
            
            query = "?" + Join(params, "&")
            return m.request("GET", "/Items" + query, {})
        end function
        
        getItem: function(itemId as String) as Object
            return m.request("GET", "/Items/" + itemId, {})
        end function
        
        getItemPlaybackInfo: function(itemId as String) as Object
            query = "?deviceProfile=roku&maxStreamingBitrate=" + str(m.deviceProfile.MaxStreamingBitrate).trim()
            return m.request("GET", "/Items/" + itemId + "/PlaybackInfo" + query, {})
        end function
        
        ' Playback control
        playItem: function(itemId as String, options = {} as Object) as Object
            startPosition = 0
            if options.DoesExist("startPosition") then startPosition = options.startPosition
            
            return m.request("POST", "/Sessions/Play", {
                item_id: itemId
                start_position_ticks: startPosition
                device_profile: m.deviceType
            })
        end function
        
        stopPlayback: function() as Object
            return m.request("POST", "/Playstate", {
                session_id: m.sessionId
                command: "stop"
            })
        end function
        
        pausePlayback: function() as Object
            return m.request("POST", "/Playstate", {
                session_id: m.sessionId
                command: "pause"
            })
        end function
        
        resumePlayback: function() as Object
            return m.request("POST", "/Playstate", {
                session_id: m.sessionId
                command: "play"
            })
        end function
        
        seekPlayback: function(positionTicks as Integer) as Object
            return m.request("POST", "/Playstate", {
                session_id: m.sessionId
                command: "seek"
                data: { position_ticks: positionTicks }
            })
        end function
        
        reportProgress: function(positionTicks as Integer, isPaused as Boolean) as Object
            return m.request("POST", "/Playstate/Progress", {
                session_id: m.sessionId
                position_ticks: positionTicks
                is_paused: isPaused
            })
        end function
        
        ' User data
        markWatched: function(itemId as String) as Object
            return m.updateUserData(itemId, { is_watched: true })
        end function
        
        markUnwatched: function(itemId as String) as Object
            return m.updateUserData(itemId, { is_watched: false })
        end function
        
        updateUserData: function(itemId as String, userData as Object) as Object
            return m.request("POST", "/Items/" + itemId + "/UserData", userData)
        end function
    }
    
    ' Generate device ID if not exists
    obj.deviceId = Storage.get("device_id")
    if obj.deviceId = "" or obj.deviceId = invalid then
        obj.deviceId = "roku-" + str(Rnd(999999999)).trim() + "-" + str(Rnd(999999999)).trim()
        Storage.set("device_id", obj.deviceId)
    end if
    
    return obj
end function
```

### 4.2 Storage Library

```brightscript
' source/lib/Storage.brs

' ===========================================
' Persistent Storage for Roku
' Uses roRegistry for key-value storage
' ===========================================

function Storage() as Object
    obj = {
        registry: CreateObject("roRegistrySection", "phlix")
        
        get: function(key as String) as String
            return m.registry.Read(key)
        end function
        
        set: function(key as String, value as String)
            m.registry.Write(key, value)
            m.registry.Flush()
        end function
        
        delete: function(key as String)
            m.registry.Delete(key)
            m.registry.Flush()
        end function
        
        clear: function()
            m.registry.DeleteAll()
            m.registry.Flush()
        end function
    }
    
    return obj
end function
```

---

## 5. Player Implementation

### 5.1 Player Scene

```brightscript
' source/components/PlayerScene.brs

' ===========================================
' Phlix Player Scene
' Handles video playback on Roku
' ===========================================

sub Init()
    m.top.SetFocus(true)
    
    ' Create video player
    m.videoPlayer = m.top.FindNode("videoPlayer")
    m.videoPlayer.EnableCookies()
    m.videoPlayer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    
    ' Set up listeners
    m.videoPlayer.ObserveField("state", "OnPlayerStateChange")
    m.videoPlayer.ObserveField("position", "OnPositionUpdate")
    
    ' UI nodes
    m.progressBar = m.top.FindNode("progressBar")
    m.timeLabel = m.top.FindNode("timeLabel")
    m.titleLabel = m.top.FindNode("titleLabel")
    m.backButton = m.top.FindNode("backButton")
    
    ' Setup button handlers
    m.backButton.ObserveField("buttonSelected", "OnBackPressed")
end sub

sub Show(itemId as String, playbackInfo as Object)
    m.itemId = itemId
    m.playbackInfo = playbackInfo
    m.isPlaying = false
    m.lastReportedPosition = 0
    
    ' Set title
    if m.titleLabel <> invalid then
        m.titleLabel.text = playbackInfo.item.name
    end if
    
    ' Determine stream URL
    streamUrl = playbackInfo.playback_info.url
    if streamUrl = invalid or streamUrl = "" then
        print "No stream URL available"
        return
    end if
    
    ' Configure stream
    stream = CreateObject("roSGNode", "ContentNode")
    stream.url = streamUrl
    stream.streamformat = playbackInfo.playback_info.container
    
    if playbackInfo.playback_info.transcoded = true then
        stream.streamformat = "hls"
    end if
    
    ' Set content and start playback
    m.videoPlayer.content = stream
    m.videoPlayer.control = "play"
    m.isPlaying = true
    
    ' Start progress reporting
    startProgressTimer()
end sub

sub OnPlayerStateChange(event as Object)
    state = event.getData()
    
    if state = "error" then
        print "Video playback error: "; m.videoPlayer.errorCode
        ShowErrorDialog("Playback failed. Please try again.")
    else if state = "playing" then
        m.isPlaying = true
        ShowControls(false)
    else if state = "paused" then
        m.isPlaying = false
    else if state = "stopped" then
        m.isPlaying = false
        ClosePlayer()
    end if
end sub

sub OnPositionUpdate(event as Object)
    position = event.getData()
    duration = m.videoPlayer.duration
    
    if duration > 0 then
        ' Update progress bar
        progress = (position / duration) * 100
        if m.progressBar <> invalid then
            m.progressBar.width = Int(854 * progress / 100)
        end if
        
        ' Update time label
        if m.timeLabel <> invalid then
            currentTime = FormatTime(position)
            totalTime = FormatTime(duration)
            m.timeLabel.text = currentTime + " / " + totalTime
        end if
        
        ' Report progress to server (every 10 seconds)
        positionTicks = Int(position * 10000000)
        if positionTicks - m.lastReportedPosition > 100000000 then
            ReportProgress(positionTicks)
            m.lastReportedPosition = positionTicks
        end if
    end if
end sub

sub OnBackPressed()
    StopPlayback()
    ClosePlayer()
end sub

sub ShowControls(show as Boolean)
    ' Animate controls visibility
end sub

sub FormatTime(seconds as Float) as String
    hours = Int(seconds / 3600)
    minutes = Int((seconds mod 3600) / 60)
    secs = Int(seconds mod 60)
    
    if hours > 0 then
        return str(hours).trim() + ":" + str(minutes).Trim().Right(2).Repl(" ", "0") + ":" + str(secs).Trim().Right(2).Repl(" ", "0")
    else
        return str(minutes).Trim() + ":" + str(secs).Trim().Right(2).Repl(" ", "0")
    end if
end sub

sub StopPlayback()
    if m.videoPlayer <> invalid then
        m.videoPlayer.control = "stop"
    end if
    
    ' Report final position
    position = m.videoPlayer.position
    if position > 0 then
        ReportProgress(Int(position * 10000000))
    end if
    
    ' Stop progress timer
    stopProgressTimer()
end sub

sub ReportProgress(positionTicks as Integer)
    ' Report to Phlix server
    api.reportProgress(positionTicks, not m.isPlaying)
end sub

sub ClosePlayer()
    ' Navigate back
    m.top.Close()
end sub

' Progress timer
m.progressTimer = invalid

sub startProgressTimer()
    if m.progressTimer = invalid then
        m.progressTimer = CreateObject("roTimer")
        m.progressTimer.SetPort(m.top.GetNodePort())
        m.progressTimer.StartPeriod(1)
        m.progressTimer ObserveField("fire", "OnTimerFire")
    end if
end sub

sub stopProgressTimer()
    if m.progressTimer <> invalid then
        m.progressTimer.Stop()
        m.progressTimer = invalid
    end if
end sub

sub OnTimerFire()
    ' Keep Roku awake during playback
end sub

sub OnKeyEvent(key as String, press as Boolean) as Boolean
    handled = false
    
    if press then
        if key = "back" then
            OnBackPressed()
            handled = true
        else if key = "play" then
            if m.isPlaying then
                m.videoPlayer.control = "pause"
            else
                m.videoPlayer.control = "resume"
            end if
            handled = true
        else if key = "pause" then
            m.videoPlayer.control = "pause"
            handled = true
        else if key = "rewind" then
            SeekRelative(-10)
            handled = true
        else if key = "fastforward" then
            SeekRelative(10)
            handled = true
        else if key = "left" then
            SeekRelative(-30)
            handled = true
        else if key = "right" then
            SeekRelative(30)
            handled = true
        end if
    end if
    
    return handled
end sub

sub SeekRelative(seconds as Float)
    if m.videoPlayer = invalid then return
    
    position = m.videoPlayer.position
    duration = m.videoPlayer.duration
    
    newPosition = position + seconds
    if newPosition < 0 then newPosition = 0
    if newPosition > duration then newPosition = duration
    
    m.videoPlayer.seek = newPosition
end sub
```

### 5.2 Player Scene XML

```xml
<?xml version="1.0" encoding="utf-8"?>
<component name="PlayerScene" extends="Scene">
    <interface>
        <field id="itemId" type="string" />
        <field id="playbackInfo" type="assocarray" />
    </interface>
    
    <children>
        <!-- Video Player -->
        <Video height="720" width="1280" />
        
        <!-- Overlay UI -->
        <Rectangle height="720" width="1280" transparency="0.7" visible="false" id="controlsOverlay">
            <!-- Top Bar -->
            <Rectangle height="100" width="1280" translation="[0,0]">
                <Rectangle height="100" width="200" translation="[20,20]">
                    <Button height="60" width="160" title="Back" id="backButton" />
                </Rectangle>
            </Rectangle>
            
            <!-- Title -->
            <Label height="60" width="900" translation="[200,30]" 
                   font="font:MediumSystemFont" 
                   color="#FFFFFF" 
                   id="titleLabel" />
            
            <!-- Center Controls -->
            <HStack height="120" width="400" translation="[440,300]">
                <Button height="100" width="100" id="rewindButton" />
                <Button height="120" width="120" id="playPauseButton" />
                <Button height="100" width="100" id="forwardButton" />
            </HStack>
            
            <!-- Bottom Bar -->
            <Rectangle height="120" width="1280" translation="[0,580]">
                <Rectangle height="80" width="854" translation="[213,40]">
                    <Rectangle height="8" width="854" color="#404040" />
                    <Rectangle height="8" width="0" color="#0095d5" id="progressBar" />
                </Rectangle>
                <Label height="40" width="200" translation="[540,25]" 
                       font="font:SmallSystemFont" 
                       color="#FFFFFF" 
                       id="timeLabel" />
            </Rectangle>
        </Rectangle>
    </children>
</component>
```

---

## 6. Remote Control Handling

### 6.1 Key Event Handling

Roku remote handling is built into the Scene's `OnKeyEvent` method:

```brightscript
' Key handling in scene or component

' Remote button codes:
' "up" - Up arrow
' "down" - Down arrow  
' "left" - Left arrow
' "right" - Right arrow
' "select" - OK button
' "back" - Back button
' "play" - Play button
' "pause" - Pause button
' "rewind" - Rewind button
' "fastforward" - Fast forward button
' "replay" - Replay button
' "info" - Info button
' "options" - Options button

function OnKeyEvent(key as String, press as Boolean) as Boolean
    handled = false
    
    if not press then
        return false
    end if
    
    if key = "back" then
        HandleBack()
        handled = true
    else if key = "play" then
        HandlePlayPause()
        handled = true
    else if key = "pause" then
        HandlePause()
        handled = true
    else if key = "stop" then
        HandleStop()
        handled = true
    else if key = "rewind" then
        HandleSeek(-10)
        handled = true
    else if key = "fastforward" then
        HandleSeek(10)
        handled = true
    else if key = "left" then
        HandleNavLeft()
        handled = true
    else if key = "right" then
        HandleNavRight()
        handled = true
    else if key = "up" then
        HandleNavUp()
        handled = true
    else if key = "down" then
        HandleNavDown()
        handled = true
    else if key = "info" then
        HandleToggleInfo()
        handled = true
    else if key = "replay" then
        HandleSeekToStart()
        handled = true
    end if
    
    return handled
end function
```

---

## 7. User Interface

### 7.1 Home Scene

```brightscript
' source/components/HomeScene.brs

sub Init()
    m.top.SetFocus(true)
    
    ' Create poster grid
    m.posterGrid = m.top.FindNode("libraryGrid")
    m.posterGrid.ObserveField("itemSelected", "OnItemSelected")
    m.posterGrid.ObserveField("itemFocused", "OnItemFocused")
    
    ' Load libraries on init
    LoadLibraries()
end sub

sub LoadLibraries()
    libraries = api.getLibraries()
    
    if libraries = invalid then
        return
    end if
    
    ' Create content node for grid
    content = CreateObject("roSGNode", "ContentNode")
    
    for each library in libraries
        item = content.AddChild({
            Title: library.name
            Description: "Library"
            HDPosterUrl: "pkg:/images/placeholder.png"
            ShortDescriptionLine1: library.name
            Type: "library"
            id: library.id
        })
    end for
    
    m.posterGrid.content = content
end sub

sub OnItemSelected(event as Object)
    index = event.getData()
    content = m.posterGrid.content.GetChild(index)
    
    if content.Type = "library" then
        ' Navigate to library
        ShowLibrary(content.id)
    else
        ' Navigate to item detail
        ShowItemDetail(content.id)
    end if
end sub

sub OnItemFocused(event as Object)
    index = event.getData()
    content = m.posterGrid.content.GetChild(index)
    
    ' Show item description
    if m.descriptionLabel <> invalid then
        m.descriptionLabel.text = content.ShortDescriptionLine1
    end if
end sub

sub ShowLibrary(libraryId as String)
    scene = CreateObject("roSGNode", "LibraryScene")
    scene.LoadLibrary(libraryId)
    m.top.Append(scene)
end sub

sub ShowItemDetail(itemId as String)
    scene = CreateObject("roSGNode", "DetailScene")
    scene.LoadItem(itemId)
    m.top.Append(scene)
end sub
```

### 7.2 Home Scene XML

```xml
<?xml version="1.0" encoding="utf-8"?>
<component name="HomeScene" extends="Scene">
    <children>
        <!-- Header -->
        <Rectangle height="100" width="1280" color="#1a1a2e">
            <Label height="60" width="400" translation="[40,20]" 
                   text="Phlix" 
                   font="font:LargeBoldSystemFont" 
                   color="#FFFFFF" />
        </Rectangle>
        
        <!-- Library Grid -->
        <PosterGrid height="580" width="1280" translation="[0,100]"
                    numColumns="4" numRows="2"
                    itemSpacing="[30, 20]"
                    basePosterSize="[280, 380]"
                    caption1Icon="handle://leftbottom"
                    id="libraryGrid">
            <ContentEmitter />
        </PosterGrid>
        
        <!-- Description Area -->
        <Rectangle height="40" width="1280" translation="[0,680]" color="#0d0d1a">
            <Label height="40" width="1200" translation="[40,8]" 
                   text="Select a library" 
                   font="font:MediumSystemFont" 
                   color="#AAAAAA" 
                   id="descriptionLabel" />
        </Rectangle>
    </children>
</component>
```

---

## 8. Testing

### 8.1 Unit Testing

```brightscript
' tests/Unit/ApiClient.test.brs

sub TestApiClient()
    ' Test initialization
    client = ApiClient("http://localhost:8096")
    assertEqual(client.deviceType, "roku")
    assertEqual(client.baseUrl, "http://localhost:8096")
end sub

sub TestStorage()
    storage = Storage()
    storage.set("test_key", "test_value")
    assertEqual(storage.get("test_key"), "test_value")
    storage.delete("test_key")
    assertEqual(storage.get("test_key"), "")
end sub
```

### 8.2 Device Testing

```bash
# Deploy to device
curl -v -u rokudev:password -X POST \
    http://<roku-ip>:8060/install/app \
    -F "archive=@phlix.zip" \
    -F "manifest=@manifest"

# Or use BrightScript IDE
```

---

## 9. Roku Store Submission

### 9.1 Pre-Submission Checklist

```
□ Developer account created at developer.roku.com
□ App icon (540x405 PNG)
□ Screenshots (1280x720 PNG, min 3, max 10)
□ App channel name
□ Description (max 1000 characters)
□ Category selection
□ Content rating submission
□ Privacy policy URL
□ Separate Roku TV and Roku Streaming Stick packages
□ Test on actual Roku device
□ Test with multiple resolutions (if applicable)
```

### 9.2 Packaging

```bash
# Create ZIP package
zip -r phlix.zip manifest source images

# Or use makefile
make package
```

---

## 10. Implementation Checklist

### Phase 1: Environment Setup
- [ ] Create Roku developer account
- [ ] Enable developer mode on device
- [ ] Setup VS Code + BrightScript extension
- [ ] Create project structure
- [ ] Configure manifest

### Phase 2: API Client
- [ ] Implement ApiClient library
- [ ] Implement Storage library
- [ ] Test API connectivity

### Phase 3: Navigation
- [ ] Create HomeScene
- [ ] Create LibraryScene
- [ ] Create DetailScene
- [ ] Setup navigation

### Phase 4: Player
- [ ] Create PlayerScene
- [ ] Handle video playback
- [ ] Handle remote controls
- [ ] Progress reporting

### Phase 5: Testing
- [ ] Unit tests
- [ ] Device testing
- [ ] Error handling

### Phase 6: Submission
- [ ] Create assets
- [ ] Package app
- [ ] Submit to store

---

**Document Version:** 1.0  
**Last Updated:** 2026-05-14  
