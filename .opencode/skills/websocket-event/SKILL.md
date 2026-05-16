---
name: websocket-event
description: Registers a new WebSocket event handler via MessageHandler->on('event_name', fn($conn, $payload) => ...) and adds the corresponding constant to src/Server/WebSocket/Events.php (WebSocketEvents::*). Uses $conn->sendMessage('type', [...]) for replies and $handler->broadcast() / sendToUser() / sendToSession() for fan-out. Use when user says 'add websocket event', 'real-time message', 'syncplay event', 'broadcast event', or touches src/Server/WebSocket/MessageHandler.php. Do NOT use for HTTP routes (use http-controller), DLNA SSDP events, or REST API endpoints.
---

# WebSocket Event Handler

## Critical

- Every event name MUST be a constant in `src/Server/WebSocket/Events.php` (class `WebSocketEvents`). Never hardcode raw event strings in handlers or clients.
- Handler signature is ALWAYS `function (Connection $conn, array $payload): void`. Do not return values — use `$conn->sendMessage()` or broadcast helpers.
- Validate `$payload` keys with `isset()` BEFORE accessing them. Missing keys must reply with `$conn->sendMessage('error', ['code' => 'invalid_payload', 'message' => '...'])` and `return` early.
- Authenticated events MUST check `$conn->getUserId()` is not null before any state mutation or fan-out. Unauthenticated events reply `error` with code `unauthorized` and return.
- Register handlers inside `MessageHandler::registerDefaultHandlers()` — never from outside the class — to keep the event surface discoverable.
- Do NOT broadcast PII or session tokens. Payloads sent via `broadcast()` reach every connected client.

## Instructions

1. **Add the event constant** to `src/Server/WebSocket/Events.php`:
   ```php
   public const EVENT_NAME = 'event_name';
   ```
   Use SCREAMING_SNAKE_CASE for the constant, snake_case for the wire value. Group related constants with a `//` section comment (e.g. `// SyncPlay events`).
   Verify the file parses: `php -l src/Server/WebSocket/Events.php`.

2. **Register the handler** in `src/Server/WebSocket/MessageHandler.php` inside `registerDefaultHandlers()`. Use the constant from Step 1:
   ```php
   $this->on(WebSocketEvents::EVENT_NAME, function (Connection $conn, array $payload): void {
       $userId = $conn->getUserId();
       if ($userId === null) {
           $conn->sendMessage('error', ['code' => 'unauthorized', 'message' => 'Login required']);
           return;
       }
       if (!isset($payload['target_id'], $payload['value'])) {
           $conn->sendMessage('error', ['code' => 'invalid_payload', 'message' => 'target_id and value required']);
           return;
       }
       // ... handler body
   });
   ```
   This step uses the constant from Step 1.

3. **Pick the correct fan-out method** for the response:
   - `$conn->sendMessage($type, $data)` — reply to the originating connection only
   - `$this->sendToUser($userId, $type, $data)` — every connection for one user (multi-device)
   - `$this->sendToSession($sessionId, $type, $data)` — every member of a SyncPlay session
   - `$this->broadcast($type, $data)` — every connected client (use sparingly; admin events only)

   For SyncPlay/group events ALWAYS prefer `sendToSession()`. For user notifications ALWAYS prefer `sendToUser()`.

4. **Wrap state mutations** (DB writes, session updates) in `try/catch`. On `\Throwable`, log via `$this->logger->error(...)` and reply with `$conn->sendMessage('error', ['code' => 'internal', 'message' => 'Operation failed'])`. Do NOT leak exception messages to the client.

5. **Add a unit test** at `tests/unit/Server/WebSocket/MessageHandlerTest.php` (or `tests/unit/Session/SyncPlay/...` for syncplay events). Mock `Connection` and assert `sendMessage` is called with the expected type+payload. Pattern:
   ```php
   public function testEventNameRepliesWithStatus(): void
   {
       $conn = $this->createMock(Connection::class);
       $conn->method('getUserId')->willReturn(42);
       $conn->expects($this->once())
            ->method('sendMessage')
            ->with('status_updated', $this->arrayHasKey('target_id'));
       $handler = new MessageHandler($this->deps);
       $handler->dispatch($conn, WebSocketEvents::EVENT_NAME, ['target_id' => 1, 'value' => 'x']);
   }
   ```

6. **Update the client** in `public/assets/js/` (search for existing `socket.on(` calls — usually `public/assets/js/websocket.js` or `public/assets/js/syncplay.js`). Use the same wire string as the constant value:
   ```js
   socket.on('event_name', (payload) => { /* ... */ });
   socket.send({ type: 'event_name', target_id: 1, value: 'x' });
   ```

7. **Verify** before claiming done:
   - `php -l src/Server/WebSocket/Events.php src/Server/WebSocket/MessageHandler.php`
   - `./vendor/bin/phpunit tests/unit/Server/WebSocket/` (or the specific test file)
   - Grep for the new constant: `grep -r 'WebSocketEvents::EVENT_NAME' src/ tests/` — must appear in both registration and tests.

## Examples

**User says:** "Add a syncplay event so a host can pause playback for everyone in the session."

**Actions:**
1. Add `public const SYNCPLAY_PAUSE = 'syncplay_pause';` to `src/Server/WebSocket/Events.php` under the `// SyncPlay events` section.
2. In `MessageHandler::registerDefaultHandlers()`:
   ```php
   $this->on(WebSocketEvents::SYNCPLAY_PAUSE, function (Connection $conn, array $payload): void {
       $userId = $conn->getUserId();
       if ($userId === null) {
           $conn->sendMessage('error', ['code' => 'unauthorized', 'message' => 'Login required']);
           return;
       }
       if (!isset($payload['session_id'], $payload['position_ms'])) {
           $conn->sendMessage('error', ['code' => 'invalid_payload', 'message' => 'session_id and position_ms required']);
           return;
       }
       try {
           $session = $this->syncPlayManager->pause((int) $payload['session_id'], $userId, (int) $payload['position_ms']);
       } catch (\Throwable $e) {
           $this->logger->error('syncplay_pause failed', ['err' => $e->getMessage()]);
           $conn->sendMessage('error', ['code' => 'internal', 'message' => 'Pause failed']);
           return;
       }
       $this->sendToSession($session->id, 'syncplay_paused', [
           'position_ms' => $session->positionMs,
           'paused_by'   => $userId,
       ]);
   });
   ```
3. Add `MessageHandlerTest::testSyncplayPauseBroadcastsToSession`.
4. Add JS handler in `public/assets/js/syncplay.js` listening for `syncplay_paused`.

**Result:** Host emits `syncplay_pause`; every session member receives `syncplay_paused` with the new position; non-members and unauthenticated callers receive `error`.

## Common Issues

- **"Undefined constant WebSocketEvents::FOO"** — you forgot Step 1. Add the constant to `src/Server/WebSocket/Events.php` and re-run `php -l`.
- **"Handler fires but client never receives reply"** — you used `return [...]` instead of `$conn->sendMessage(...)`. Handlers return void; replies are explicit sends.
- **"Event reaches wrong users"** — you called `broadcast()` when you meant `sendToSession()` or `sendToUser()`. `broadcast()` hits every socket; use it only for global admin events.
- **"Cannot call method on null in getUserId()"** — `$conn->getUserId()` returned null because the socket isn't authenticated. Add the unauthorized guard from Step 2 before any user-scoped logic.
- **"Test fails with 'Connection::sendMessage was not expected'"** — your handler hit the error path. Inspect the payload your test passes; missing keys trigger the `invalid_payload` branch.
- **"Client gets event_name but no payload"** — you passed a scalar to `sendMessage`. Second argument must be an associative array; wrap single values like `['value' => $x]`.
- **"Race condition: two clients pause at the same ms"** — wrap the state mutation in `$this->syncPlayManager->withLock($sessionId, fn() => ...)`. Do not implement ad-hoc locking inside the handler.