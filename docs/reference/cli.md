# CLI Reference

Command-line scripts available in the Phlix Media Server.

## Hub / Pairing

### `php scripts/pair-with-hub.php <hub-url> <server-name>`

Initiates pairing between this server and a Phlix Hub instance.

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

See `Phlix\Hub\HubClient` and `docs/dev/pairing-protocol.md`.

## Port forwarding

### `php scripts/port-forward.php <command>`

Manages UPnP-IGD and NAT-PMP port forwarding for direct server access without relay tunnel.

**Commands:**

| Command  | Description |
| -------- | ----------- |
| `status` | Show current port forwarding status, enabled state, method, and hostname candidates. |
| `enable` | Attempt automatic port forwarding via UPnP-IGD or NAT-PMP. Falls back to manual instructions on failure. |
| `disable` | Remove all port mappings and disable automatic port forwarding. |
| `info`   | Display detailed network information: local IP, public IP (via STUN), port accessibility, and UPnP IGD discovery status. |
| `help`   | Show usage information. |

**Example output (status):**

```
Port Forwarding Status
=======================
Enabled:  YES
Method:   upnp
External IP: 203.0.113.42
Port:     32400
Endpoint: 203.0.113.42:32400

Hostname Candidates:
  [lan] http://192.168.1.100:32400
  [lan-mdns] http://phlix.local:32400
  [public] http://203.0.113.42:32400
```

**Example output (info):**

```
Network Information
====================
Local IP:  192.168.1.100
Port:      32400

Testing STUN (public IP detection)...
Public IP: 203.0.113.42
Port 32400 on 203.0.113.42: OPEN

UPnP IGD Discovery...
Gateway:  http://192.168.1.1:1900/gateway.xml
External WAN IP: 203.0.113.42
```

**How it works:**

1. **UPnP-IGD** — Sends SSDP M-SEARCH to `239.255.255.250:1900` to discover
   a UPnP InternetGatewayDevice, then uses SOAP `AddPortMapping` to open the
   port. See `Phlix\Network\UpnpIgdClient`.
2. **NAT-PMP** — Falls back to Apple NAT-PMP (RFC 6886) on routers like
   AirPort Extreme. See `Phlix\Network\NatPmpClient`.
3. **STUN** — Uses RFC 5389 STUN binding to discover the server's public
   IP address and test port accessibility. See `Phlix\Network\StunClient`.

**See also:** `docs/hub/remote-access.md`.
