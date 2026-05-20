# ChromaPrint Integration

## Overview

ChromaPrint (via libchromaprint/acoustid) provides audio fingerprinting capabilities for the Phlix media server. This enables episode grouping and skip-intro/outro detection in Phase F.

## Implementation Architecture

### FFI vs Shelled Mode

The implementation supports two backends:

1. **FFI (preferred)**: Direct calls to `libchromaprint.so` via PHP's FFI extension. Zero binary dependency, faster execution.
2. **Shelled (fallback)**: Wraps the `fpcalc` command-line tool via `proc_open()`. Used when FFI is disabled (common in shared hosting).

The factory (`ChromaPrintFactory`) automatically selects FFI when available, falling back to shelled mode.

### Key Classes

| Class | Purpose |
|-------|---------|
| `ChromaPrintInterface` | Interface defining `fingerprint()` and `isAvailable()` |
| `ChromaPrint` | Main wrapper class for clients |
| `ChromaPrintFfi` | FFI implementation |
| `ChromaPrintShelled` | Shelled fpcalc implementation |
| `ChromaPrintFactory` | Factory that auto-selects FFI/shelled |
| `ChromaPrintNotAvailableException` | Thrown when no backend is available |
| `ChromaPrintFingerprintFailedException` | Thrown when fingerprinting fails |
| `FingerprintRepository` | Persists fingerprints to `media_items.metadata_json` |

## Configuration

Edit `config/chromaprint.php`:

```php
return [
    'enabled' => true,
    'fpcalc_path' => '/usr/local/bin/fpcalc',
    'use_ffi_first' => true,
    'fingerprint_audio_seconds' => 120,  // 0 = full file
    'skip_if_duration_lt' => 300,        // don't fingerprint items < 5 min
];
```

### Config Keys

- `enabled`: Enable/disable fingerprinting (future F.2 use)
- `fpcalc_path`: Path to fpcalc binary for shelled mode
- `use_ffi_first`: Whether to try FFI before shelled (always true, reserved)
- `fingerprint_audio_seconds`: Seconds of audio to fingerprint (default: 120 = 2 min)
- `skip_if_duration_lt`: Skip fingerprinting for media < N seconds

## Fingerprint Storage

Fingerprints are stored in `media_items.metadata_json` under the `fingerprint` key:

```json
{
  "fingerprint": "ABC123DEF456...",
  "existing_key": "existing_value"
}
```

No schema changes required at this stage.

## Requirements

- **FFI mode**: PHP 7.4+ with `ffi.enable=1` in php.ini, `libchromaprint.so` installed
- **Shelled mode**: `fpcalc` binary installed and in `$PATH` or at configured `fpcalc_path`

## Usage

```php
use Phlix\Media\Markers\Fingerprinting\ChromaPrint;
use Phlix\Media\Markers\Fingerprinting\FingerprintRepository;
use Phlix\Media\Library\ItemRepository;

// Generate a fingerprint
$chromaprint = new ChromaPrint('/usr/local/bin/fpcalc');
if ($chromaprint->isAvailable()) {
    $fingerprint = $chromaprint->fingerprint('/path/to/episode.mkv');
}

// Store and retrieve
$repo = new FingerprintRepository($itemRepository);
$repo->storeFingerprint('media-item-id', $fingerprint);
$fp = $repo->getFingerprint('media-item-id');

// Get all fingerprinted episodes for a show
$fingerprintedIds = $repo->getFingerprintedIdsForShow('show-id');
```

## FFI Details

The FFI implementation uses:

```c
char* chromaprint_generate_fingerprint(const char* path);
void chromaprint_free_fingerprint(char* fingerprint);
```

Library detection paths checked:
- `/usr/lib/libchromaprint.so`
- `/usr/lib64/libchromaprint.so`
- `/usr/local/lib/libchromaprint.so`
- `/usr/local/lib64/libchromaprint.so`

## Shelled fpcalc Details

The shelled implementation:
- Uses `proc_open()` with 60-second timeout
- Parses `FINGERPRINT=<value>` from stdout
- Checks binary existence and executability
- Validates functional output via `fpcalc -help`

## How Fingerprints Feed Into Intro/Outro Detection

In Phase F.2, fingerprints will be used to:
1. Cluster episodes of the same show by similarity
2. Group episodes shot with same equipment/studio
3. Identify recurring intro/outro segments across episodes

The 120-second default captures enough audio for clustering without processing entire files.

## Future Phases

- **F.2**: Background job to fingerprint all episodes, cluster by similarity
- **F.3**: Detect intro/outro segments using fingerprint clusters
