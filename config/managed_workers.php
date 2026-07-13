<?php

/**
 * Managed-worker key → its DI-resolvable worker class.
 *
 * Single source of truth for the mapping start.php uses to spawn each ENABLED
 * config/process.php entry as a supervised sibling Worker. Each class MUST
 * expose `start(int $pollSeconds): void` (arms a Workerman\Timer that polls
 * `runOnce()`), and each key MUST correspond to an entry in config/process.php.
 *
 * Keeping this map in config (rather than inline in start.php) makes it testable
 * and prevents the "config/process.php entry is enabled but nothing spawns it"
 * gap that previously left the media-asset + similarity queues accumulating
 * undrained on disk (SV-1.3 / SV-2.9 disk leak).
 *
 * @return array<string, class-string>
 *
 * @since 0.38.0
 */

declare(strict_types=1);

return [
    'library-scan'       => \Phlix\Media\Library\LibraryScanWorker::class,
    'plugin-auto-update' => \Phlix\Plugins\Catalog\PluginAutoUpdateWorker::class,
    'marker-detection'   => \Phlix\Media\Markers\Detection\BackgroundDetectorWorker::class,
    // SV-1.3: chapter-thumbnail + trickplay generation worker. Its config
    // (media-asset) has been enabled in config/process.php but was missing from
    // this spawn map, so the queue drained only when an operator ran the
    // standalone scripts/run-media-asset-worker.php by hand — otherwise it leaked.
    'media-asset'        => \Phlix\Media\MediaAsset\MediaAssetWorker::class,
    // SV-2.9: similarity computation worker — drains the scanner's per-item
    // similarity enqueue so it does not accumulate undrained on disk.
    'similarity'         => \Phlix\Media\SimilarityWorker::class,
];
