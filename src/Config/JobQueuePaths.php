<?php

/**
 * Phlix media server component: Config.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Config;

use InvalidArgumentException;

/**
 * Single source for the file-backed job-queue directories (R14-14).
 *
 * Before this existed, the five file queues (marker detection, media assets,
 * collection sync, metadata write-back, similarity) defaulted to
 * `/tmp/phlix_<name>_jobs` in THREE places per queue — `config/<name>_jobs.php`
 * (`job_queue_dir`), the `MediaServicesProvider` factory fallback, and each
 * store's own `QUEUE_DIR` constructor default. That was broken twice over on
 * the shipped systemd unit: `systemd/phlix-server.service` runs with
 * `PrivateTmp=true`, so (a) every service restart silently discarded the
 * queued backlog, and (b) an out-of-unit producer (a cron'd
 * `scripts/backfill-*.php`, a manual worker) wrote to the REAL `/tmp` and
 * the in-unit worker never saw a single job.
 *
 * `dir()` resolves each queue path with precedence
 * **per-queue env > root env > repo `var/jobs/<name>`**:
 *
 *   - `PHLIX_<NAME>_JOBS_DIR` — e.g. `PHLIX_MARKER_JOBS_DIR`,
 *     `PHLIX_MEDIA_ASSET_JOBS_DIR`, `PHLIX_COLLECTION_JOBS_DIR`,
 *     `PHLIX_METADATA_WRITE_JOBS_DIR`, `PHLIX_SIMILARITY_JOBS_DIR`;
 *   - `PHLIX_JOB_QUEUE_ROOT` — one knob for all five (queues land in
 *     `<root>/<name>`);
 *   - default: `<repo>/var/jobs/<name>` — inside the unit's existing
 *     `ReadWritePaths=/var/www/phlix/var` grant, inside the Docker image's
 *     already-writable `/var/www/html/var`, and OUTSIDE the PrivateTmp
 *     namespace, so backlogs survive restarts and every producer/consumer
 *     (daemon, managed worker, standalone `scripts/run-*-worker.php`,
 *     backfill) shares ONE path.
 *
 * Empty-string env values count as unset (container runtimes set `-e FOO=`
 * more often than anyone admits). Call sites are `config/*_jobs.php`,
 * `MediaServicesProvider` and the five stores' constructors — all funnel
 * here so the three old fallbacks can never drift apart again.
 *
 * @package Phlix\Config
 * @since   1.4.0
 */
final class JobQueuePaths
{
    /**
     * One-knob root for all five file job queues (R14-14).
     *
     * @since 1.4.0
     */
    public const ROOT_ENV = 'PHLIX_JOB_QUEUE_ROOT';

    /**
     * Never instantiate — pure resolver.
     */
    private function __construct()
    {
    }

    /**
     * Resolve one queue directory by its canonical name.
     *
     * @param string $name Queue name: marker, media_asset, collection,
     *                     metadata_write or similarity (snake_case token).
     *
     * @return string Absolute (or explicitly operator-relative) directory.
     *
     * @throws InvalidArgumentException On a name that is not a path-safe token.
     *
     * @since 1.4.0
     */
    public static function dir(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException(sprintf(
                'JobQueuePaths: queue name "%s" must match [a-z0-9_]+ — it feeds both the '
                . 'PHLIX_{}_JOBS_DIR env key and the on-disk directory name.',
                $name
            ));
        }

        $perQueue = getenv('PHLIX_' . strtoupper($name) . '_JOBS_DIR');
        if (is_string($perQueue) && trim($perQueue) !== '') {
            return self::normalize(trim($perQueue));
        }

        $root = getenv(self::ROOT_ENV);
        if (is_string($root) && trim($root) !== '') {
            return self::normalize(trim($root)) . '/' . $name;
        }

        return self::normalize(self::defaultRoot()) . '/' . $name;
    }

    /**
     * The repo-local default root: <repo>/var/jobs (resolved, no `..` hops).
     *
     * @since 1.4.0
     */
    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 2) . '/var/jobs';
    }

    /**
     * Strip trailing slashes so `<root>/<name>` never doubles up (a lone "/"
     * stays "/" — the filesystem root is legal as a queue parent, however
     * inadvisable).
     */
    private static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
