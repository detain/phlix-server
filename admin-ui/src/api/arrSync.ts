/**
 * ArrSyncApi — typed wrapper over the TRaSH-Guides Arr sync admin endpoints.
 *
 * @since 1.4b
 */
import type { ApiClient } from './client';

/**
 * Response shape from `GET /api/v1/admin/sync/status`.
 *
 * @since 1.4b
 */
export interface ArrSyncStatus {
  enabled: boolean;
  last_sync_at: string | null;
  last_sync_timestamp: number | null;
}

/**
 * Response shape from `POST /api/v1/admin/sync/trash-guides`.
 *
 * @since 1.4b
 */
export interface ArrSyncTriggerResult {
  success: boolean;
  message: string;
  data?: Record<string, unknown>;
}

/**
 * Response shape from `PUT /api/v1/admin/sync/enable`.
 *
 * @since 1.4b
 */
export interface ArrSyncEnableResult {
  message: string;
}

/**
 * Typed client for the Arr sync (TRaSH-Guides) admin endpoints.
 *
 * @since 1.4b
 */
export class ArrSyncApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/sync/status` → `{ enabled, last_sync_at, last_sync_timestamp }`.
   */
  async getStatus(): Promise<ArrSyncStatus> {
    return this.client.get<ArrSyncStatus>('/api/v1/admin/sync/status');
  }

  /**
   * `POST /api/v1/admin/sync/trash-guides` → `{ success, message, data }` | 500 `{ success: false, error }`.
   */
  async triggerSync(): Promise<ArrSyncTriggerResult> {
    return this.client.post<ArrSyncTriggerResult>('/api/v1/admin/sync/trash-guides');
  }

  /**
   * `PUT /api/v1/admin/sync/enable` — Body: `{ enabled: bool }` → `{ message }`.
   */
  async setEnabled(enabled: boolean): Promise<ArrSyncEnableResult> {
    return this.client.put<ArrSyncEnableResult>('/api/v1/admin/sync/enable', { enabled });
  }
}
