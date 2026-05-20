# P.1c Security Audit: Phlex Client Repositories

**Audit Date:** 2026-05-19
**Auditor:** CodeReviewer Agent
**Scope:** Auth token handling, HTTPS/certificate validation, relay traffic, sensitive data in logs

---

## Executive Summary

| Client | Repository | Critical | High | Medium | Low | Informational |
|--------|------------|----------|------|--------|-----|---------------|
| Mobile | phlex-mobile-client (React Native) | 0 | 0 | 2 | 0 | 2 |
| Tizen | phlex-tizen-client (Vanilla JS) | 0 | 1 | 0 | 2 | 1 |
| Roku | phlex-roku-client (BrightScript) | 0 | 0 | 0 | 1 | 2 |
| Windows | phlex-windows-client (Electron) | 0 | 1 | 0 | 0 | 2 |
| **TOTAL** | | **0** | **2** | **2** | **3** | **7** |

---

## Detailed Findings

---

## 1. Mobile Client — `/home/sites/phlex-mobile-client/`

**Stack:** React Native 0.85 + TypeScript + Zustand + react-native-keychain

### Finding M-1: Access Token Stored in AsyncStorage (Not Keychain)

**Severity:** Medium

**Affected File(s):** `src/services/SecureStorage.ts` line 21

**Description:**
The `SecureStorage` class stores the **refresh token** in the device Keychain (secure), but stores the **access token** in AsyncStorage (unencrypted). The code itself acknowledges this is suboptimal:

```typescript
// Line 19-21:
await AsyncStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
// In production, consider storing access token in Keychain too
```

AsyncStorage data is unencrypted and accessible to any app with root access on Android or through iOS backups. While less sensitive than the refresh token, an exposed access token grants temporary API access.

**Remediation:**
Store both access and refresh tokens in Keychain. If quick access is needed, consider a separate encrypted store with shorter TTL, or use the Keychain's accessibility options to allow access only when device is unlocked.

---

### Finding M-2: Push Notification Tokens Logged to Console

**Severity:** Medium

**Affected File(s):** `src/services/NotificationService.ts` lines 15, 21, 37, 42, 143, 149, 154

**Description:**
The `NotificationService` logs push notification tokens and notification data to the console:

```typescript
// Line 15:
console.log('Push Notification Token:', token);

// Line 21:
console.log('Notification Received:', notification);

// Line 37:
console.log('Unknown notification type:', type);

// Lines 143, 149, 154:
console.log('Library updated:', data);
console.log('New content available:', data);
console.log('Sync complete:', data);
```

While these are not auth JWTs, push tokens can be used to send notifications to the user's device. In production builds, React Native strips `console.log` calls, but during development or if a user has debug builds, these could leak to logs.

**Remediation:**
Remove all `console.log` statements from `NotificationService.ts` or wrap them with a debug guard that checks `__DEV__`. Replace with structured debug logging that is stripped in production.

---

### Finding M-3: WebSocket Uses Appropriate Protocol (wss/ws)

**Severity:** Informational

**Affected File(s):** `src/syncplay/SyncPlayService.ts` line 527

**Description:**
The SyncPlay service correctly negotiates WebSocket protocol based on the server URL:

```typescript
const protocol = effectiveServerUrl.startsWith('https') ? 'wss' : 'ws';
```

No insecure `ws://` connections are made when the server uses HTTPS. Relay traffic is properly encrypted when using `wss://`.

**Remediation:**
No action required. This is correct behavior.

---

### Finding M-4: Refresh Token Properly Uses Keychain

**Severity:** Informational

**Affected File(s):** `src/services/SecureStorage.ts` lines 13-17

**Description:**
Refresh tokens are correctly stored using `react-native-keychain` with a service-specific identifier:

```typescript
await Keychain.setGenericPassword(
  ACCESS_TOKEN_KEY,
  refreshToken,
  { service: `${SERVICE_NAME}.refresh` }
);
```

This uses Keychain's secure storage and respects device security policies.

**Remediation:**
No action required. Good security practice.

---

## 2. Tizen Client — `/home/sites/phlex-tizen-client/`

**Stack:** Vanilla JS (ES2022) + Webpack + Chrome 100 target + Tizen 2.3+

### Finding T-1: Auth Token Stored in localStorage (XSS Risk)

**Severity:** High

**Affected File(s):** `app/js/api/ApiClient.js` lines 50, 52; `app/js/utils/Storage.js` lines 14, 36

**Description:**
Auth tokens are stored in `localStorage`, which is accessible to any JavaScript running on the page:

```javascript
// ApiClient.js line 50:
Storage.set('auth_token', token);

// Storage.js line 36:
localStorage.setItem(key, stringValue);
```

On Tizen TVs, the WebKit engine runs each app in an isolated context, but if any XSS vulnerability exists in the app or any injected script, the auth token can be exfiltrated. Additionally, localStorage data persists across sessions and is not encrypted.

**Remediation:**
Use a more secure storage mechanism. Consider:
1. Encrypting tokens before storing in localStorage using a device-specific key
2. Using Tizen's secure storage APIs if available
3. Storing only opaque session references server-side and using short-lived tokens

---

### Finding T-2: Debug Logging Enabled by Default (Could Expose URLs)

**Severity:** Low

**Affected File(s):** `app/js/utils/Logger.js` line 13

**Description:**
The Logger is initialized with DEBUG level logging enabled by default:

```javascript
currentLevel: 0, // DEBUG
```

This means all debug, info, warn, and error messages are logged. While the current code doesn't directly log auth tokens, the SyncPlay service logs WebSocket URLs at INFO level:

```javascript
// SyncPlayService.js line 217:
Logger.info('Connecting to SyncPlay WebSocket', { url: this.wsUrl });
```

On a Tizen TV, these logs are accessible via the Tizen Studio debugger and could contain sensitive connection metadata.

**Remediation:**
Change the default log level to INFO or WARN in production builds. Use a build flag to disable DEBUG logging in release builds.

---

### Finding T-3: SyncPlay WebSocket URL Logging

**Severity:** Low

**Affected File(s):** `app/js/syncplay/SyncPlayService.js` line 217

**Description:**
When connecting to SyncPlay, the WebSocket URL is logged:

```javascript
Logger.info('Connecting to SyncPlay WebSocket', { url: this.wsUrl });
```

While the URL itself doesn't contain auth tokens (they're sent as headers), it does reveal the server infrastructure.

**Remediation:**
Log only that a connection is being attempted, not the full URL. Or reduce log level to DEBUG only.

---

### Finding T-4: No Direct IP Connection Bypass

**Severity:** Informational

**Affected File(s):** `app/js/hub/hubConfig.js` lines 48-58

**Description:**
The hub configuration properly routes through the configured hub URL. No direct IP connections that bypass the relay were found.

**Remediation:**
No action required.

---

## 3. Roku Client — `/home/sites/phlex-roku-client/`

**Stack:** BrightScript + SceneGraph XML + roRegistry + roUrlTransfer

### Finding R-1: Auth Tokens Stored in roRegistry (Good Practice)

**Severity:** Low

**Affected File(s):** `source/lib/Storage.brs` lines 10-18

**Description:**
Roku stores auth tokens in the device registry using `roRegistrySection`:

```brightscript
registry: CreateObject("roRegistrySection", "phlex")

get: function(key as String) as String
    return m.registry.Read(key)
end function

set: function(key as String, value as String)
    m.registry.Write(key, value)
    m.registry.Flush()
end function
```

The Roku registry is device-specific and not accessible to other channels. This is a more secure approach than localStorage on web-based platforms.

**Remediation:**
No action required. Good security practice for the Roku platform.

---

### Finding R-2: SyncPlay Uses HTTP Headers for Auth (Not URL Parameters)

**Severity:** Informational

**Affected File(s):** `source/syncplay/SyncPlayService.brs` lines 316-319

**Description:**
When using HTTP long-polling (since Roku doesn't support native WebSocket), auth is passed via headers rather than URL parameters:

```brightscript
if authHeader <> "" then
    http.AddHeader("Authorization", authHeader)
end if
```

This prevents auth tokens from appearing in server logs or URLs.

**Remediation:**
No action required. Good security practice.

---

### Finding R-3: No Native WebSocket Support (Uses HTTP Polling Fallback)

**Severity:** Informational

**Affected File(s):** `source/syncplay/SyncPlayService.brs` lines 109-112

**Description:**
Roku's `roUrlTransfer` doesn't support native WebSocket, so the client falls back to HTTP long-polling. The code comments acknowledge this:

```brightscript
' On Roku, we use HTTP-based WebSocket emulation via long-polling
' since roUrlTransfer doesn't support native WebSocket
```

This is a platform limitation, not a security issue. The HTTP polling still uses HTTPS and passes auth via headers.

**Remediation:**
No action required. Platform limitation handled appropriately.

---

## 4. Windows Client — `/home/sites/phlex-windows-client/`

**Stack:** Electron + React 18 + TypeScript + Vite + Zustand

### Finding W-1: Auth Token Stored in localStorage (Same Risk as Tizen)

**Severity:** High

**Affected File(s):** `src/renderer/utils/api.ts` lines 162, 164, 178-179

**Description:**
Auth tokens are stored in `localStorage` in plain text:

```typescript
// api.ts line 162:
localStorage.setItem('auth_token', token);

// api.ts line 164:
localStorage.removeItem('auth_token');

// hubStore.ts line 71:
localStorage.setItem('hub_session', JSON.stringify(session));
```

In Electron, `localStorage` in the renderer process is backed by a plain-text JSON file in the user's AppData folder (`%APPDATA%\phlex-windows\Locale Storage\`). Any process with file access to that folder can read the tokens.

Additionally, the renderer has `contextIsolation: true` and `nodeIntegration: false` (good), but the tokens are still accessible via JavaScript in the renderer process.

**Remediation:**
Use `electron-store` with encryption for sensitive data like auth tokens. The main process already uses `electron-store` for preferences — extend this pattern to include encrypted storage for session tokens accessible only through IPC.

---

### Finding W-2: SyncPlay Token Passed as WebSocket Subprotocol

**Severity:** Informational

**Affected File(s):** `src/syncplay/SyncPlayService.ts` line 389

**Description:**
The Windows client passes the auth token as a WebSocket subprotocol:

```typescript
this.ws = new WebSocket(wsUrl, token ? [token] : undefined);
```

This is an alternative to passing tokens in URL parameters or headers. While it's not inherently insecure (tokens in subprotocols are not logged in URLs), the WebSocket standard doesn't encrypt subprotocol data — it's sent in the initial handshake.

**Remediation:**
Consider passing the token as a header after connection via a WebSocket-specific auth message, or ensure the WebSocket connection is over TLS (wss://).

---

### Finding W-3: Electron Security Hardening Applied

**Severity:** Informational

**Affected File(s):** `src/main/index.ts` lines 26-31

**Description:**
The main process configures Electron with good security defaults:

```typescript
webPreferences: {
  preload: path.join(__dirname, 'preload.js'),
  contextIsolation: true,
  nodeIntegration: false,
  sandbox: false
}
```

`contextIsolation: true` and `nodeIntegration: false` prevent renderer JavaScript from accessing Node.js APIs or the main process. The preload script uses `contextBridge.exposeInMainWorld` properly.

**Remediation:**
No action required. Good security configuration.

---

### Finding W-4: Console Logging with ESLint Suppression

**Severity:** Informational

**Affected File(s):** `src/syncplay/SyncPlayService.ts` lines 396, 437, 440, 456

**Description:**
The SyncPlay service uses `console.error` and `console.warn` with eslint disable comments:

```typescript
console.error('SyncPlay WebSocket connection error:', error); // eslint-disable-line no-console
console.error('Error parsing SyncPlay message:', error); // eslint-disable-line no-console
```

These are error-level logs that don't expose auth tokens. The eslint disable is acceptable when logging is necessary for debugging production issues.

**Remediation:**
No action required. Acceptable use of logging for error conditions.

---

## Cross-Cutting Observations

### HTTPS/TLS Certificate Validation

**All clients** use standard platform APIs for HTTP requests:
- Mobile: `axios` (respects system certs on iOS/Android)
- Tizen: `fetch` (respects system certs in Chromium)
- Roku: `roUrlTransfer` (validates certificates)
- Windows: `fetch` in renderer, Node.js `fetch` in main (respects system certs)

**No client was found to bypass certificate validation** for production connections. All HTTP calls are made over HTTPS when servers require it.

### Relay Traffic Encryption

All clients properly negotiate encrypted WebSocket connections (`wss://`) when connecting to HTTPS servers. The relay architecture routes traffic through the hub, and no direct IP connections that bypass the relay were found.

### Token Refresh Mechanisms

All clients implement proper token refresh:
- Mobile: Axios interceptor handles 401 and refreshes automatically
- Tizen: Manual refresh via `HubApi.refresh()`
- Roku: Manual refresh via `HubAuth.refresh()`
- Windows: Manual refresh via `HubService.refresh()`

Refresh tokens are properly rotated on each refresh in all implementations.

---

## Recommendations Summary

### Immediate (High Priority)

1. **W-1, T-1: Encrypt auth tokens in localStorage**
   - Use platform-appropriate secure storage (Keychain on mobile, encrypted store on Electron)
   - Do not store in plain text in localStorage

### Short Term (Medium Priority)

2. **M-1: Store mobile access token in Keychain**
   - Currently only refresh token is in Keychain
   - Access token should also use secure storage

3. **M-2: Remove push token logging from NotificationService**
   - Remove `console.log` statements or guard with `__DEV__`

### Good Hygiene (Low Priority)

4. **T-2: Disable DEBUG logging in production**
   - Change default log level to INFO or WARN

5. **T-3: Reduce URL logging in SyncPlay**
   - Log connection attempt, not full URL

---

## P.1c Summary: client repos audit COMPLETE. 0 Critical, 2 High, 2 Medium, 3 Low, 7 Informational.
