<?php

declare(strict_types=1);

/*
 * Find (and optionally merge) duplicate top-level series/movies across one or
 * all libraries — the backfill counterpart to Feature 1's scan-time prevention.
 *
 * Why: before the scanner learned to resolve a container by canonical dedup key
 * (phlix-server Feature 1, Steps 1.1-1.2), any title-slug variance (separators,
 * year bleed, a parse failure, a flat->per-directory re-scan, or a concurrent-
 * scan race) silently forked a SECOND top-level row — the "100 episodes + 1
 * episode" symptom. Prevention only stops NEW dupes; this CLI cleans up the
 * historical ones already on disk.
 *
 * How: {@see DuplicateFinder} pages each library's top-level items in fixed
 * batches (resident-memory safe — never loads the whole library), groups rows
 * sharing a {@see CanonicalKey::forItem()} value (per library AND per type, so a
 * movie never groups with a series), designates the richest member (most
 * descendants) as PRIMARY, and lists the rest as duplicates. On --apply,
 * {@see SeriesMerger::merge()} re-parents each duplicate's children onto the
 * primary then deletes the emptied shells, inside ONE explicit transaction. The
 * merge is structural (re-parent + delete) — re-parented episodes keep their ids
 * so their per-user playback/favorite state survives.
 *
 * Usage:
 *   php scripts/dedup-series.php                 # dry-run, ALL libraries (default)
 *   php scripts/dedup-series.php --library=<id>  # scope to one library
 *   php scripts/dedup-series.php --apply         # write the merges
 *   php scripts/dedup-series.php --library=<id> --apply
 *
 * Default is DRY-RUN: it lists every duplicate group (primary + duplicates with
 * child counts) and mutates nothing. Re-running with --apply merges them; a
 * second --apply run then finds zero groups (idempotent). Exits non-zero only on
 * a genuine error (e.g. a merge throwing), never merely because dupes were found.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/bootstrap_env.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;

$apply = in_array('--apply', $argv, true);
$libraryFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--library=')) {
        $libraryFilter = substr($arg, strlen('--library='));
    }
}

ConnectionPool::init(__DIR__ . '/../config/database.php');
$db = ConnectionPool::getConnection('mysql');
$repo = new ItemRepository($db);
$finder = new DuplicateFinder($repo);
// SeriesMerger needs the transaction-aware connection; the default pool
// connection is a PhlixMySQLConnection (only used on --apply).
$merger = ($apply && $db instanceof PhlixMySQLConnection) ? new SeriesMerger($repo, $db) : null;

echo $apply
    ? "MODE: APPLY (merging duplicate groups)\n"
    : "MODE: DRY-RUN (no changes; pass --apply to merge)\n";
if ($libraryFilter !== null) {
    echo "Library filter: {$libraryFilter}\n";
}
echo str_repeat('-', 60) . "\n";

if ($apply && $merger === null) {
    fwrite(
        STDERR,
        "ERROR: --apply requires a transaction-capable MySQL connection "
        . "(PhlixMySQLConnection); got " . get_class($db) . ".\n"
    );
    exit(1);
}

/**
 * Resolve the set of library ids to scan: the filter when given, else every
 * library on the box (queried directly, like backfill-series-hierarchy.php).
 *
 * @return list<string>
 */
$resolveLibraryIds = static function () use ($db, $libraryFilter): array {
    if ($libraryFilter !== null) {
        return [$libraryFilter];
    }
    $rows = $db->query('SELECT id FROM libraries ORDER BY id ASC');
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
            $ids[] = $row['id'];
        }
    }
    return $ids;
};

/**
 * Human label for a hydrated group member ("name [N descendant(s)]").
 *
 * @param array<string, mixed> $member
 */
$label = static function (array $member): string {
    $name = is_string($member['name'] ?? null) ? $member['name'] : '(unnamed)';
    $count = is_int($member['descendant_count'] ?? null) ? $member['descendant_count'] : 0;
    $id = is_string($member['id'] ?? null) ? $member['id'] : '?';
    return sprintf('%s [%d descendant(s)] (%s)', $name, $count, $id);
};

$libraryIds = $resolveLibraryIds();
echo 'Scanning ' . count($libraryIds) . " library/libraries.\n\n";

$totalGroups = 0;
$totalDuplicateRows = 0;
$totalMoved = 0;
$totalDeleted = 0;
$hadError = false;

foreach ($libraryIds as $libraryId) {
    $groups = $finder->findForLibrary($libraryId);
    if ($groups === []) {
        continue;
    }

    echo "Library {$libraryId}: " . count($groups) . " duplicate group(s)\n";

    foreach ($groups as $group) {
        $totalGroups++;
        $totalDuplicateRows += count($group['duplicates']);

        echo "  [{$group['type']}] key={$group['canonical_key']}\n";
        echo '    PRIMARY:   ' . $label($group['primary']) . "\n";
        foreach ($group['duplicates'] as $dup) {
            echo '    duplicate: ' . $label($dup) . "\n";
        }

        if ($apply && $merger !== null) {
            $duplicateIds = [];
            foreach ($group['duplicates'] as $dup) {
                if (is_string($dup['id'] ?? null)) {
                    $duplicateIds[] = $dup['id'];
                }
            }
            $primaryId = is_string($group['primary']['id'] ?? null) ? $group['primary']['id'] : null;
            if ($primaryId === null || $duplicateIds === []) {
                continue;
            }
            try {
                $result = $merger->merge($primaryId, $duplicateIds);
                $totalMoved += $result['moved'];
                $totalDeleted += $result['deleted'];
                echo "    -> merged: {$result['moved']} moved, {$result['deleted']} deleted\n";
            } catch (Throwable $e) {
                $hadError = true;
                fwrite(STDERR, "    !! merge failed for primary {$primaryId}: " . $e->getMessage() . "\n");
            }
        }
    }
    echo "\n";
}

echo str_repeat('-', 60) . "\n";
echo "Duplicate groups found:   {$totalGroups}\n";
echo "Duplicate rows in groups: {$totalDuplicateRows}\n";
if ($apply) {
    echo "Children moved:           {$totalMoved}\n";
    echo "Shell rows deleted:       {$totalDeleted}\n";
    echo $hadError
        ? "\nDone WITH ERRORS — see messages above. Re-run to retry the failures.\n"
        : "\nDone. Re-run (dry-run) to confirm zero remaining groups.\n";
} else {
    echo $totalGroups > 0
        ? "\nDry-run only. Re-run with --apply to merge these groups.\n"
        : "\nNo duplicate groups found. Nothing to merge.\n";
}

exit($hadError ? 1 : 0);
