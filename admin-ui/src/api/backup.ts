/**
 * BackupApi — typed wrapper over the existing {@link ApiClient} for the
 * backup management endpoints (`/api/v1/admin/backup/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `BackupController` and
 * parses the EXACT response envelope that controller returns. Non-2xx
 * responses throw {@link ApiError} via the shared client.
 *
 * Contract (traced from source):
 *  - `create()` posts `{ label?: string }` and replies
 *    `200 { success: true, message: string, data: { backup_id, file_path, size_bytes } }`
 *  - `list()` replies `200 { success: true, data: Backup[], count: int }`
 *  - `delete()` replies `200 { success: true, message: string }` or
 *    `404 { success: false, error: string }`
 *  - `restore()` replies `200 { success: true, message: string }` or
 *    `500 { success: false, message: string, error: string }`
 *  - `uploadS3()` replies `200 { success: true, message: string }` or
 *    `500 { success: false, error: string }`
 *  - `getSchedule()` replies `200 { success: true, data: { auto_backup_interval_days, retention_count, next_scheduled_backup, next_scheduled_backup_iso } }`
 *  - `updateSchedule()` posts `{ auto_backup_interval_days?: int, retention_count?: int }`
 *    and replies `200 { success: true, message: string, data: { auto_backup_interval_days, retention_count } }`
 *    or `400 { success: false, error: string, message: string }`
 *
 * @since 1.5
 */
import type { ApiClient } from './client';

/**
 * A backup row as returned by `BackupManager::listBackups()`.
 *
 * @since 1.5
 */
export interface Backup {
  id: string;
  label: string;
  file_path: string;
  size_bytes: number;
  checksum_sha256: string;
  is_s3: boolean;
  created_at: string;
  expires_at: string | null;
}

/**
 * Body accepted by {@link BackupApi.create}.
 *
 * @since 1.5
 */
export interface CreateBackupInput {
  label?: string;
}

/**
 * Result of {@link BackupApi.create}.
 *
 * @since 1.5
 */
export interface CreateBackupResult {
  message: string;
  backup_id: string;
  file_path: string;
  size_bytes: number;
}

/**
 * Body accepted by {@link BackupApi.updateSchedule}.
 *
 * @since 1.5
 */
export interface UpdateScheduleInput {
  auto_backup_interval_days?: number;
  retention_count?: number;
}

/**
 * Schedule data returned by {@link BackupApi.getSchedule}.
 *
 * @since 1.5
 */
export interface ScheduleData {
  auto_backup_interval_days: number;
  retention_count: number;
  next_scheduled_backup: number | null;
  next_scheduled_backup_iso: string | null;
}

/**
 * Result of {@link BackupApi.updateSchedule}.
 *
 * @since 1.5
 */
export interface UpdateScheduleResult {
  auto_backup_interval_days: number;
  retention_count: number;
}

/**
 * Typed client for the backup endpoints.
 *
 * @since 1.5
 */
export class BackupApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/backup/list` → unwraps `{ data }`.
   */
  async list(): Promise<Backup[]> {
    const response = await this.client.get<{
      success: boolean;
      data: Backup[];
      count: number;
    }>('/api/v1/admin/backup/list');
    return response.data;
  }

  /**
   * `POST /api/v1/admin/backup/create` → `{ success: true, message, data }`.
   */
  async create(input: CreateBackupInput = {}): Promise<CreateBackupResult> {
    const r = await this.client.post<{ success: boolean; message: string; data: CreateBackupResult }>(
      '/api/v1/admin/backup/create',
      input,
    );
    return {
      message: r.message,
      backup_id: r.data.backup_id,
      file_path: r.data.file_path,
      size_bytes: r.data.size_bytes,
    };
  }

  /**
   * `DELETE /api/v1/admin/backup/{id}` → `{ message }`.
   */
  delete(id: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/admin/backup/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/backup/{id}/restore` → `{ message }`.
   */
  restore(id: string): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/admin/backup/${encodeURIComponent(id)}/restore`,
    );
  }

  /**
   * `POST /api/v1/admin/backup/{id}/upload-s3` → `{ message }`.
   */
  uploadToS3(id: string): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/admin/backup/${encodeURIComponent(id)}/upload-s3`,
    );
  }

  /**
   * `GET /api/v1/admin/backup/schedule` → unwraps `{ data }`.
   */
  async getSchedule(): Promise<ScheduleData> {
    const response = await this.client.get<{
      success: boolean;
      data: ScheduleData;
    }>('/api/v1/admin/backup/schedule');
    return response.data;
  }

  /**
   * `PUT /api/v1/admin/backup/schedule` → unwraps `{ data }`.
   */
  async updateSchedule(input: UpdateScheduleInput): Promise<UpdateScheduleResult> {
    const response = await this.client.put<{
      success: boolean;
      message: string;
      data: UpdateScheduleResult;
    }>('/api/v1/admin/backup/schedule', input);
    return response.data;
  }
}
