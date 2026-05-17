# Relay Protocol Specification

**Version:** 1.0
**Status:** Implemented (Phase C.6)
**Audience:** Developers implementing the relay tunnel (Step C.6)

---

## Overview

The relay tunnel enables remote clients to reach a `phlex-server` instance that is behind a NAT or firewall without any port forwarding on the server side.

The server opens a **persistent WSS connection** to the hub's relay endpoint. The hub multiplexes inbound HTTP requests from remote clients over this tunnel and proxies responses back.

This is the same pattern as `frp`, `ngrok`, or `cloudflared` but implemented in PHP/Workerman.

**Key distinction from heartbeat:** The heartbeat connection is a periodic HTTPS POST call from the server to the hub (60-second interval). The relay tunnel is a persistent WebSocket connection initiated by the server to the hub, used to carry arbitrary HTTP traffic bidirectionally.

---

## Architecture

```
Client (remote)                    Hub                       Server (behind NAT)
     |                              |                              |
     |  HTTPS request to           |                              |
     |  https://<id>.phlex.media/* |                              |
     | ---------------------------->                              |
     |                              |  HTTP-over-WebSocket frame   |
     |                              | ---------------------------->
     |                              |                              |
     |                              |  HTTP-over-WebSocket response|
     |                              | <----------------------------
     |  HTTPS response             |                              |
     | <------------------------------                             |
```

---

## Connection Lifecycle

### Server initiates

1. Server starts `RelayApplication` if `PHLEX_RELAY_ENABLED=true` and `hub-enrollment.json` exists.
2. `RelayConsumer` opens a WSS connection to `wss://hub.example.com/api/v1/servers/{server_id}/relay`.
3. On connect, server sends a `REGISTER` frame with its enrollment JWT.
4. Hub validates the JWT and associates the WebSocket connection with the server's `relay_session` DB record.

### Normal operation

1. Hub receives an inbound HTTPS request for `https://<id>.phlex.media/api/v1/relay/...`.
2. Hub routes the request to the correct server via the persistent WSS connection.
3. Server receives the `HTTP_REQUEST` frame, dispatches it locally, and returns an `HTTP_RESPONSE` frame.
4. Hub proxies the response back to the client.

### Keep-alive

- Server sends a `PING` frame every `PHLEX_RELAY_PING_INTERVAL` seconds (default 30).
- Hub responds with a `PONG` frame.
- If no `PONG` is received within `PHLEX_RELAY_PING_TIMEOUT` (default 10), the connection is considered dead.
- Server auto-reconnects after `PHLEX_RELAY_RECONNECT_DELAY` seconds (default 5).

---

## Wire Format

All frames share the same binary structure:

```
[1-byte type][4-byte seq (big-endian uint32)][4-byte payload_len (big-endian uint32)][payload_bytes]
```

### Frame types

| Constant              | Value | Direction        | Description |
| --------------------- | ----- | --------------- | ----------- |
| `TYPE_HTTP_REQUEST`  | `1`   | Hub → Server     | HTTP request proxied to the server |
| `TYPE_HTTP_RESPONSE`  | `2`   | Server → Hub     | HTTP response from the server |
| `TYPE_PING`           | `3`   | Either → Either  | Keep-alive probe |
| `TYPE_PONG`           | `4`   | Either → Either  | Keep-alive acknowledgement |

### HTTP Request payload (JSON)

```json
{
  "seq": 42,
  "method": "GET",
  "path": "/api/v1/libraries",
  "headers": {
    "Authorization": "Bearer ...",
    "Accept": "application/json"
  },
  "body": ""
}
```

### HTTP Response payload (JSON)

```json
{
  "seq": 42,
  "status": 200,
  "headers": {
    "Content-Type": "application/json",
    "Content-Length": "27"
  },
  "body": "{\"media_items\":[]}"
}
```

### PING / PONG payload (JSON)

```json
{"seq": 7}
```

---

## REGISTER Frame (initial)

On connect, the server sends a `TYPE_HTTP_REQUEST` frame where `method = "REGISTER"`:

```json
{
  "seq": 1,
  "method": "REGISTER",
  "path": "/relay/register",
  "headers": {
    "Authorization": "Bearer <enrollment_jwt>",
    "X-Server-Id": "<server_uuid>"
  },
  "body": "{\"server_id\":\"<server_uuid>\"}"
}
```

Hub responds with a `TYPE_HTTP_RESPONSE` with `status = 200` on success, `401` if the JWT is invalid.

---

## Server-side Components

### `Phlex\Hub\RelayMessageFramer`

Frames and parses binary relay messages.

```php
$framer = new RelayMessageFramer();
$bytes = $framer->frameRequest($seq, 'GET', '/api/v1/libraries', $headers, '');
$frame = $framer->parse($bytes);  // => RelayFrame|null
```

### `Phlex\Hub\RelayConsumer`

Maintains the WSS connection to the hub. Receives frames, dispatches locally via `Router`, and sends responses.

```php
$consumer = new RelayConsumer($config, $hubClient, $logger, $serverId);
$consumer->start();   // opens WSS connection
$consumer->stop();    // graceful shutdown
$consumer->isConnected();
```

### `Phlex\Hub\RelayApplication`

Workerman Worker wrapper providing the timer context.

### `Phlex\Hub\RelayConfig`

Configuration from `config/relay.php` / environment variables.

---

## Error Handling

| Scenario                      | Behavior |
| ----------------------------- | -------- |
| Connection drop               | `RelayConsumer` schedules a reconnect after `PHLEX_RELAY_RECONNECT_DELAY` |
| Hub returns non-2xx on REGISTER | Connection closed, no reconnect |
| Local router throws            | `RelayConsumer` returns HTTP 500 over the tunnel |
| Incomplete binary frame        | Buffer retained until more data arrives |
| Unknown frame type             | Logged and discarded |

---

## Database

Hub-side `relay_sessions` table tracks:

- `id` — UUID of the relay session
- `server_id` — FK to `servers.id`
- `connected_at` — Unix timestamp
- `last_frame_at` — Unix timestamp of last activity
- `bytes_sent` — total bytes sent to server
- `bytes_received` — total bytes received from server

---

## Related Documents

- `docs/dev/pairing-protocol.md` — Pairing and enrollment protocol
- `config/relay.php` — Configuration reference
- `docs/reference/env-vars.md` — Environment variable reference
