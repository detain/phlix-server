# Intro/Outro Detection

## Overview

The marker detection system automatically identifies intro and outro sequences for TV episodes by clustering audio fingerprints. Episodes that share similar fingerprints in the first N minutes are flagged as having a common intro; similarly for the last M minutes (outro).

## How It Works

### Fingerprint Clustering Algorithm

The system uses **Jaccard similarity** to compare audio fingerprints:

1. For each show, we collect all fingerprinted episodes
2. We compare fingerprints pairwise using Jaccard similarity
3. Episodes with similarity >= 85% are grouped together
4. The largest group is selected as the canonical intro/outro

**Jaccard Similarity Formula:**
```
J(A, B) = |A ∩ B| / |A ∪ B|
```

Where A and B are the character sets of two fingerprint strings.

### Confidence Scoring

Confidence is calculated based on:
- **Group size** (50% weight): More episodes matching = higher confidence
- **Average similarity** (50% weight): Higher similarity within group = higher confidence

Final score: `min(100, (size_score) + (similarity_score))`

## Configuration

Edit `config/marker_detection.php`:

```php
return [
    'intro_start_seconds' => 0,
    'intro_max_duration' => 180,        // Max intro length in seconds
    'outro_max_duration' => 180,         // Max outro length in seconds
    'similarity_threshold' => 0.85,     // Jaccard threshold (0.0–1.0)
    'min_episodes_for_detection' => 3,   // Minimum episodes needed
    'job_queue_dir' => '/tmp/phlex_marker_jobs',
    'worker_interval' => 30,             // Worker poll interval
];
```

## Running the Background Worker

Start the detection worker as a separate process:

```bash
php scripts/run-marker-detection-worker.php
```

The worker runs continuously and processes shows from the queue at the configured interval.

### Queue Management

The worker uses a file-based queue in `/tmp/phlex_marker_jobs/`:

- Each show being processed has a `.lock` file
- Use `MarkerCandidateStore` to manage the queue programmatically
- The worker auto-removes lock files when processing completes

## Data Flow

```
1. Library scan enqueues shows needing detection
2. BackgroundDetectorWorker polls queue
3. For each show:
   a. IntroDetectionJob fetches all episodes
   b. FingerprintClusterer groups similar fingerprints
   c. MarkerCandidateRepository stores candidates in metadata_json
   d. Lock file is removed (job complete)
4. F.3 API consumes marker candidates from metadata_json
```

## Output Format

Detection results are stored in `media_items.metadata_json`:

```json
{
  "intro_candidate": {
    "start_seconds": 0,
    "end_seconds": 90,
    "fingerprint": "representative_fingerprint",
    "confidence": 85
  },
  "outro_candidate": {
    "start_seconds": 2310,
    "end_seconds": 2400,
    "fingerprint": "representative_fingerprint",
    "confidence": 80
  }
}
```

## Classes

- `FingerprintClusterer` - Jaccard-based clustering algorithm
- `IntroDetectionJob` - Orchestrates detection for a show
- `MarkerCandidateStore` - File-based job queue
- `BackgroundDetectorWorker` - Queue consumer loop
- `MarkerCandidateRepository` - Persists candidates to database

## Testing

Run the test suite:

```bash
./vendor/bin/phpunit --testsuite Unit
```

Run with coverage:

```bash
./vendor/bin/phpunit --coverage-text
```

Coverage targets:
- `IntroDetectionJob` >= 85%
- `FingerprintClusterer` >= 85%
- `MarkerCandidateStore` >= 85%
