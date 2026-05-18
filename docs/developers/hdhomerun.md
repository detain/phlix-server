# HDHomeRun Tuner Driver

**Since:** 0.12.0

HDHomeRun devices are network-attached TV tuners that communicate via:
- **SSDP** (Simple Service Discovery Protocol) on UDP port 1900 for device discovery
- **HTTP API** on port 80 for channel tuning and stream retrieval

This document covers the SSDP discovery protocol, HDHomeRun HTTP API endpoints, configuration keys, and how to extend with another tuner type.

## Architecture

```
LiveTvManager
    └── TunerDriverInterface
            └── HdHomeRunTunerDriver
                    ├── HdHomeRunDiscovery (SSDP on UDP 1900)
                    └── HdHomeRunApiClient (HTTP on port 80)
                            └── HdHomeRunDevice (value object)
```

## SSDP Discovery Protocol

HDHomeRun devices advertise themselves via SSDP (Simple Service Discovery Protocol). The discovery process:

1. Send `M-SEARCH` broadcast on `239.255.255.250:1900` (UDP)
2. Devices respond with `NOTIFY` messages containing `Location:` header
3. Fetch device description from the `Location:` URL
4. Parse device ID, IP address, tuner count from the response

### SSDP Message Format

```
M-SEARCH * HTTP/1.1
HOST: 239.255.255.250:1900
MAN: "ssdp:discover"
MX: 5
ST: urn:schemas-upnp-org:device:MediaServer:1
USER-AGENT: Phlex/1.0
```

### Response Processing

HDHomeRun devices include `hdhomerun` in their response. The discovery parses:
- `Location:` or `LOCATION:` header → device base URL
- Device ID from the friendly name or serial number
- Tuner count from device capabilities

## HDHomeRun HTTP API

All API calls are plain HTTP GET requests. Responses are JSON unless noted otherwise.

### Endpoints

| Endpoint | Description | Response |
|----------|-------------|----------|
| `/discover.json` | Device info | `{deviceid, model, firmware...}` |
| `/lineup.json` | Channel lineup | `[{"GuideName":"...","GuideNumber":"...",...}]` |
| `/lineup.post` | Trigger scan | Empty body on success |
| `/tuningformatail?channel=X` | Tune to channel | Stream URL data |
| `/watch?channel=N` | Get HLS stream URL | Redirects to HLS manifest |

### Channel Lineup Format

```json
[
  {
    "GuideName": "ABC",
    "GuideNumber": "2",
    "type": "off",
    "transport_stream_id": 1,
    "program_id": null
  }
]
```

### Stream URL Format

HDHomeRun provides HLS streams via URL pattern:
```
http://{device_ip}/watch?channel={channel_number}
```

The response redirects to an HLS variant playlist (`.m3u8`) with segmented `.ts` files.

## Configuration

`config/livetv.php` contains HDHomeRun-specific settings:

```php
return [
    'hdhomerun' => [
        'enabled' => true,
        'ssdp_timeout_secs' => 5,
        'preferred_device_id' => null,  // null = auto-discover first
        'preferred_tuner_index' => 0,
    ],
    'storage_path' => '/var/recordings',
    'max_storage_bytes' => 0,
    'default_quality' => 'tv',
    'allow_direct_stream' => true,
];
```

### Configuration Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `enabled` | bool | `true` | Enable HDHomeRun discovery |
| `ssdp_timeout_secs` | int | `5` | SSDP discovery timeout |
| `preferred_device_id` | string\|null | `null` | Force specific device |
| `preferred_tuner_index` | int\|null | `0` | Preferred tuner on multi-tuner devices |

## TunerDriverInterface

All tuner drivers must implement `TunerDriverInterface`:

```php
interface TunerDriverInterface
{
    public function getName(): string;
    public function discoverDevices(): array<HdHomeRunDevice>;
    public function getChannelLineup(HdHomeRunDevice $device): array;
    public function scanChannels(HdHomeRunDevice $device): array;
    public function getStreamUrl(HdHomeRunDevice $device, int $channelNumber): string;
}
```

## Adding Another Tuner Type

To add support for a new tuner type (e.g., DVB-T, SiliconDust TV):

1. Create the device descriptor class (similar to `HdHomeRunDevice`)
2. Implement `TunerDiscoveryInterface` for device discovery
3. Implement `TunerApiClientInterface` for channel/streaming APIs
4. Create a new driver class implementing `TunerDriverInterface`
5. Create a factory class for the driver
6. Update `LiveTvManager` to accept the new driver type
7. Add configuration in `config/livetv.php`
8. Write unit tests (≥85% coverage on new classes)

### Example: Adding DVB-T Support

```php
// src/LiveTv/Tuners/Dvb/DvbDevice.php
final class DvbDevice
{
    public function __construct(
        public readonly string $devicePath,
        public readonly string $frontend,
        public readonly string $tunerType,
    ) {}
}

// src/LiveTv/Tuners/Dvb/DvbTunerDriver.php
class DvbTunerDriver implements TunerDriverInterface
{
    public function getName(): string { return 'dvb_t'; }
    // ... implement interface methods
}
```

## Error Handling

The HDHomeRun driver handles errors gracefully:

- **Network unavailable**: `discover()` returns `[]`
- **Device unreachable**: Individual API calls return `false` or empty array
- **Invalid response**: XML/JSON parsing errors are caught and logged

All network operations use timeouts to prevent blocking:
- SSDP discovery: configurable `ssdp_timeout_secs` (default 5s)
- HTTP API calls: 10 second timeout

## Logging

HDHomeRun driver logs important events using PSR-3 logger:

- Discovery start/complete (info level)
- Network errors (warning level)
- Stream URL generation (debug level)

Log channel: `LogChannels::LIVETV`
