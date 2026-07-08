<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin JSON API for previewing and applying duplicate-item merges (Step 1.6).
 *
 * Feature 1 (auto-merge duplicate series/movies) exposes two operations:
 *
 *  - `GET  /api/v1/admin/libraries/{id}/duplicates` — preview: returns the
 *    {@see DuplicateFinder} groups for one library (each group = a shared
 *    canonical key with a designated primary + the duplicate rows). Read-only.
 *  - `POST /api/v1/admin/media/merge` — apply: body `{primary_id, duplicate_ids[]}`
 *    re-parents the duplicates' children onto the primary and deletes the empty
 *    shells via {@see SeriesMerger::merge()}, returning `{moved, deleted}`.
 *
 * All routes are gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware BEFORE this controller
 * runs, so it assumes an already-authenticated admin. It nonetheless validates
 * its own input and returns the proper HTTP status (400/404) rather than
 * relying solely on {@see SeriesMerger}'s internal defenses.
 *
 * Envelope: success returns a top-level named key (`{groups: …}` /
 * `{moved, deleted}`), errors return `{error: …}` with the matching HTTP
 * status — the same shape the sibling admin controllers use (no `{success}`
 * wrapper).
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 1.6
 */
final class AdminMergeController
{
    /**
     * @param ItemRepository    $items  Read-access for primary/duplicate lookup + validation.
     * @param DuplicateFinder   $finder Groups the merge candidates for the preview.
     * @param SeriesMerger|null $merger Applies a merge (re-parent children + delete shells).
     *        Null when no transaction-capable base
     *        {@see \Workerman\MySQL\Connection} is bound (the merger is wired
     *        from whichever connection Phlix ships — {@see \Phlix\Common\Database\PhlixMySQLConnection}
     *        under `DB_POOL_ENABLED=0` or {@see \Phlix\Common\Database\PooledMySQLConnection}
     *        under `DB_POOL_ENABLED=1`, both of which expose the transaction API);
     *        the apply endpoint then returns 503 (the read-only preview is unaffected).
     */
    public function __construct(
        private readonly ItemRepository $items,
        private readonly DuplicateFinder $finder,
        private readonly ?SeriesMerger $merger,
    ) {
    }

    /**
     * Preview the duplicate groups in one library.
     *
     * `GET /api/v1/admin/libraries/{id}/duplicates` pages the library's
     * top-level items, buckets them by canonical key, and returns only the
     * groups with two or more members (singletons excluded). An empty library
     * — or one with no duplicates — yields `{groups: []}` (200, not 404): the
     * absence of duplicates is a valid, well-formed result.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — the library UUID).
     *
     * @return Response 200 { groups: array } | 400 { error }
     */
    public function duplicates(Request $request, array $params): Response
    {
        $libraryId = is_string($params['id'] ?? null) ? $params['id'] : '';
        if ($libraryId === '') {
            return (new Response())->status(400)->json(['error' => 'Library id is required']);
        }

        $groups = $this->finder->findForLibrary($libraryId);

        return (new Response())->json(['groups' => $groups]);
    }

    /**
     * Apply a merge: collapse the duplicates into the primary.
     *
     * `POST /api/v1/admin/media/merge` body `{primary_id: string,
     * duplicate_ids: string[]}`. Validates the request and returns the
     * appropriate HTTP status:
     *
     *  - `primary_id` empty / not a string → 400.
     *  - `duplicate_ids` not a non-empty list of strings → 400.
     *  - `primary_id` listed in `duplicate_ids` (self-merge) → 400.
     *  - primary row not found → 404.
     *  - any duplicate row not found, or in a different library, or of a
     *    different type than the primary → 400 (cross-library / cross-type).
     *
     * Only when every duplicate is validated against the primary's library +
     * type does it call {@see SeriesMerger::merge()} and return the structural
     * `{moved, deleted}` counts. When no transaction-aware merger is wired
     * (no txn-capable base {@see \Workerman\MySQL\Connection} bound), the
     * endpoint returns 503 before validating, leaving all data untouched.
     *
     * @param Request $request The HTTP request (body {primary_id, duplicate_ids}).
     *
     * @return Response 200 { moved: int, deleted: int } | 400 | 404 | 503 { error }
     */
    public function merge(Request $request): Response
    {
        if ($this->merger === null) {
            return (new Response())->status(503)->json([
                'error' => 'Merge is unavailable: the database connection is not transaction-aware',
            ]);
        }

        $primaryIdRaw = $request->input('primary_id');
        $primaryId = is_string($primaryIdRaw) ? trim($primaryIdRaw) : '';
        if ($primaryId === '') {
            return (new Response())->status(400)->json(['error' => 'primary_id is required']);
        }

        $duplicateIds = $this->normalizeDuplicateIds($request->input('duplicate_ids'));
        if ($duplicateIds === null) {
            return (new Response())->status(400)->json([
                'error' => 'duplicate_ids must be a non-empty array of item ids',
            ]);
        }

        // Reject a self-merge: the primary can never be one of its own duplicates.
        if (in_array($primaryId, $duplicateIds, true)) {
            return (new Response())->status(400)->json([
                'error' => 'primary_id must not appear in duplicate_ids (self-merge)',
            ]);
        }

        $primary = $this->items->findById($primaryId);
        if ($primary === null) {
            return (new Response())->status(404)->json(['error' => 'Primary item not found']);
        }

        $primaryLibrary = is_string($primary['library_id'] ?? null) ? $primary['library_id'] : '';
        $primaryType = is_string($primary['type'] ?? null) ? $primary['type'] : '';

        // Validate every duplicate is in the SAME library AND of the SAME type
        // as the primary before mutating anything. SeriesMerger also re-checks
        // this internally, but the controller must reject bad input with a
        // proper 400 rather than silently dropping rows.
        foreach ($duplicateIds as $duplicateId) {
            $duplicate = $this->items->findById($duplicateId);
            if ($duplicate === null) {
                return (new Response())->status(404)->json([
                    'error' => 'Duplicate item not found: ' . $duplicateId,
                ]);
            }

            $duplicateLibrary = is_string($duplicate['library_id'] ?? null) ? $duplicate['library_id'] : '';
            if ($duplicateLibrary !== $primaryLibrary) {
                return (new Response())->status(400)->json([
                    'error' => 'All items must be in the same library as the primary',
                ]);
            }

            $duplicateType = is_string($duplicate['type'] ?? null) ? $duplicate['type'] : '';
            if ($duplicateType !== $primaryType) {
                return (new Response())->status(400)->json([
                    'error' => 'All items must be of the same type as the primary',
                ]);
            }
        }

        $result = $this->merger->merge($primaryId, $duplicateIds);

        return (new Response())->json([
            'moved' => $result['moved'],
            'deleted' => $result['deleted'],
        ]);
    }

    /**
     * Normalise the request's `duplicate_ids` into a de-duplicated list of
     * non-empty string ids, or `null` when the input is not a non-empty array
     * of strings.
     *
     * @param mixed $raw The raw `duplicate_ids` body value.
     *
     * @return list<string>|null A non-empty list of ids, or null on bad input.
     */
    private function normalizeDuplicateIds(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                return null;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (!in_array($trimmed, $ids, true)) {
                $ids[] = $trimmed;
            }
        }

        // $raw is non-empty and every entry was a non-empty string (else we
        // returned null above), so $ids is guaranteed non-empty here.
        return $ids;
    }
}
