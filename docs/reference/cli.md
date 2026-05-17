# CLI Reference

Command-line scripts available in the Phlex Media Server.

## Hub / Pairing

### `php scripts/pair-with-hub.php <hub-url> <server-name>`

Initiates pairing between this server and a Phlex Hub instance.

**Arguments:**

| Argument       | Description                                      |
| -------------- | ------------------------------------------------ |
| `hub-url`     | Base URL of the hub (e.g. `https://hub.example.com`). |
| `server-name` | Human-readable name shown on the hub dashboard.  |

**Example:**

```bash
php scripts/pair-with-hub.php https://hub.example.com "Alice's NAS"
```

**Output:**

```
Pairing initiated.
Claim code: ABCD-1234
Enter this code at https://hub.example.com/claim-server
Waiting for claim... (press Ctrl+C to cancel)
Claimed! Server ID: 550e8400-e29b-41d4-a716-446655440000
Enrollment stored.
Pairing complete. Server is now connected to the hub.
Heartbeat loop has been started in the background.
```

**Behavior:**

1. Generates (or loads existing) Ed25519 keypair from `config/hub-server-key.pem`.
2. Sends a claim request to `POST <hub-url>/api/v1/server-claims/new`.
3. Displays the returned claim code for the operator to enter on the hub's web portal.
4. Polls `GET <hub-url>/api/v1/server-claims/{claimId}` every 2 seconds.
5. On successful claim, stores enrollment JWT to `config/hub-enrollment.json`.
6. Starts the background heartbeat loop.

**Exit codes:**

- `0` — Pairing completed successfully.
- `1` — Error (network failure, invalid arguments, hub rejection).

See `Phlex\Hub\HubClient` and `docs/dev/pairing-protocol.md`.
