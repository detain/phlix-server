/**
 * LibrariesApi — typed wrapper over the existing {@link ApiClient} for the
 * library CRUD + async-scan endpoints (`/api/v1/libraries/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `LibraryController` (and the
 * 1.1b async-scan additions) and parses the EXACT response envelope that
 * controller returns — unwrapping the single-key wrappers (`{ libraries }`,
 * `{ library }`, `{ scan_status }`, `{ history }`) so callers receive the bare
 * domain object. Non-2xx responses throw {@link ApiError} via the shared client
 * (we never re-implement error handling here).
 *
 * Contract notes (traced from source, not assumed):
 *  - `create()` posts `{ name, type, paths, options? }` and the server replies
 *    `201 { library_id, message }`.
 *  - `update()` NEVER sends `type`: the controller silently ignores it and the
 *    `type` column is not updatable, so we omit it from the typed input.
 *  - `scan()`/`rescan()` are async: they reply `202 { job_id, status:'queued',
 *    message }` and the actual work happens in the 1.1b worker process.
 *  - `scanStatus()` may legitimately return `null` (no job has ever run for the
 *    library) — the envelope is `{ scan_status: ScanJob | null }`.
 *  - Progress is COARSE this release: `ScanJob.items_*` stay `0` and
 *    `current_path` stays `null`; the UI must show lifecycle status only.
 *
 * @since 1.1c
 */
import type { ApiClient } from './client';

/**
 * The library `type` values the DB actually accepts. The `libraries.type`
 * ENUM (migration 001) is exactly these five — `book` is intentionally absent
 * even though `LibraryController::create()` lists it in `$validTypes`, because
 * a `book` insert would 500 at the DB ENUM. Offer ONLY these in the UI.
 *
 * @since 1.1c
 */
export const LIBRARY_TYPES = [
  'movie',
  'series',
  'music',
  'photo',
  'video',
] as const;

/** A single DB-valid library type. @since 1.1c */
export type LibraryType = (typeof LIBRARY_TYPES)[number];

/**
 * A library row as returned by `LibraryManager`/`LibraryRow::toArray()` — the
 * raw `libraries` row with `paths`/`options` already JSON-decoded. The UI uses
 * `id`, `name`, `type`, `paths` (and may show `created_at`); any extra keys are
 * opaque passthrough.
 *
 * @since 1.1c
 */
export interface Library {
  id: string;
  name: string;
  type: string;
  paths: string[];
  options?: Record<string, unknown>;
  created_at?: string;
  display_order?: number;
  [k: string]: unknown;
}

/**
 * A scan-job row as returned by `ScanJobRepository::decodeRow()` (13 fields).
 *
 * COARSE progress this release: `items_*` are always `0` and `current_path` is
 * always `null`; rely on `status` for the lifecycle.
 *
 * @since 1.1c
 */
export interface ScanJob {
  id: string;
  library_id: string;
  type: 'scan' | 'rescan';
  status: 'queued' | 'running' | 'completed' | 'failed';
  items_found: number;
  items_added: number;
  items_updated: number;
  items_removed: number;
  current_path: string | null;
  error: string | null;
  queued_at: string | null;
  started_at: string | null;
  completed_at: string | null;
}

/** Body accepted by {@link LibrariesApi.create}. @since 1.1c */
export interface CreateLibraryInput {
  name: string;
  type: string;
  paths: string[];
  options?: Record<string, unknown>;
}

/**
 * Body accepted by {@link LibrariesApi.update}. NOTE the deliberate absence of
 * `type` — the controller ignores it and the column is not updatable.
 *
 * @since 1.1c
 */
export interface UpdateLibraryInput {
  name?: string;
  paths?: string[];
  options?: Record<string, unknown>;
}

/** Result of {@link LibrariesApi.create}. @since 1.1c */
export interface CreateLibraryResult {
  library_id: string;
  message: string;
}

/** Result of {@link LibrariesApi.scan}/{@link LibrariesApi.rescan}. @since 1.1c */
export interface ScanQueuedResult {
  job_id: string;
  status: string;
  message: string;
}

/**
 * Typed client for the library + scan endpoints.
 *
 * @since 1.1c
 */
export class LibrariesApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/libraries` → unwraps `{ libraries }`. */
  async list(): Promise<Library[]> {
    const { libraries } = await this.client.get<{ libraries: Library[] }>(
      '/api/v1/libraries',
    );
    return libraries;
  }

  /** `GET /api/v1/libraries/{id}` → unwraps `{ library }`. */
  async get(id: string): Promise<Library> {
    const { library } = await this.client.get<{ library: Library }>(
      `/api/v1/libraries/${encodeURIComponent(id)}`,
    );
    return library;
  }

  /** `POST /api/v1/libraries` → `201 { library_id, message }`. */
  create(input: CreateLibraryInput): Promise<CreateLibraryResult> {
    return this.client.post<CreateLibraryResult>('/api/v1/libraries', input);
  }

  /**
   * `PUT /api/v1/libraries/{id}` → `{ message }`. Sends only the editable
   * fields; `type` is intentionally not part of {@link UpdateLibraryInput}.
   */
  update(id: string, input: UpdateLibraryInput): Promise<{ message: string }> {
    return this.client.put<{ message: string }>(
      `/api/v1/libraries/${encodeURIComponent(id)}`,
      input,
    );
  }

  /** `DELETE /api/v1/libraries/{id}` → `{ message }`. */
  remove(id: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/libraries/${encodeURIComponent(id)}`,
    );
  }

  /** `POST /api/v1/libraries/{id}/scan` → `202 { job_id, status, message }`. */
  scan(id: string): Promise<ScanQueuedResult> {
    return this.client.post<ScanQueuedResult>(
      `/api/v1/libraries/${encodeURIComponent(id)}/scan`,
    );
  }

  /** `POST /api/v1/libraries/{id}/rescan` → `202 { job_id, status, message }`. */
  rescan(id: string): Promise<ScanQueuedResult> {
    return this.client.post<ScanQueuedResult>(
      `/api/v1/libraries/${encodeURIComponent(id)}/rescan`,
    );
  }

  /**
   * `GET /api/v1/libraries/{id}/scan-status` → unwraps
   * `{ scan_status: ScanJob | null }`. A `null` means no job has run yet.
   */
  async scanStatus(id: string): Promise<ScanJob | null> {
    const { scan_status } = await this.client.get<{
      scan_status: ScanJob | null;
    }>(`/api/v1/libraries/${encodeURIComponent(id)}/scan-status`);
    return scan_status;
  }

  /**
   * `GET /api/v1/libraries/{id}/scan-history?limit=N` → unwraps `{ history }`
   * (newest first; `limit` defaults to 20 server-side, clamped `[1, 100]`).
   */
  async scanHistory(id: string, limit?: number): Promise<ScanJob[]> {
    const params = limit === undefined ? undefined : { limit: String(limit) };
    const { history } = await this.client.get<{ history: ScanJob[] }>(
      `/api/v1/libraries/${encodeURIComponent(id)}/scan-history`,
      params,
    );
    return history;
  }
}
