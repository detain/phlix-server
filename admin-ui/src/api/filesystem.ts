/**
 * FilesystemApi — typed wrapper over the 0.6 filesystem-browse endpoint
 * (`GET /api/v1/admin/fs/browse`) used by the {@link PathPicker} to choose
 * library directories.
 *
 * The endpoint lists immediate SUBDIRECTORIES only (never files) and is jailed
 * server-side to the configured roots. An empty/omitted `path` lists the roots.
 *
 * Envelope note (traced from `FsBrowseController`): the success body is
 * `{ success: true, data: { path, parent, entries } }` — this wrapper UNWRAPS
 * `.data` so callers get a bare {@link FsBrowseResult}. Errors
 * (`404`/`400`/`403`/`500`, body `{ success:false, error }`) are non-2xx and so
 * are thrown as {@link ApiError} by the shared client; we do not re-map them.
 *
 * @since 1.1c
 */
import type { ApiClient } from './client';

/** A single directory entry returned by the browse endpoint. @since 1.1c */
export interface FsEntry {
  name: string;
  path: string;
}

/**
 * The result of browsing a directory: the resolved absolute `path` (or `null`
 * at the roots listing), the `parent` to go "Up" to (or `null` at a root), and
 * the immediate subdirectory `entries`.
 *
 * @since 1.1c
 */
export interface FsBrowseResult {
  path: string | null;
  parent: string | null;
  entries: FsEntry[];
}

/** The raw `{ success, data }` envelope the controller returns. */
interface FsBrowseEnvelope {
  success: boolean;
  data: FsBrowseResult;
}

/**
 * Typed client for the admin filesystem-browse endpoint.
 *
 * @since 1.1c
 */
export class FilesystemApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/fs/browse?path=` → unwraps `.data`. Pass no `path` (or
   * an empty string) to list the roots.
   */
  async browse(path?: string): Promise<FsBrowseResult> {
    const params =
      path !== undefined && path !== '' ? { path } : undefined;
    const envelope = await this.client.get<FsBrowseEnvelope>(
      '/api/v1/admin/fs/browse',
      params,
    );
    return envelope.data;
  }
}
