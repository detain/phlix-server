import { describe, expect, it } from 'vitest';
import { ApiClient } from './client';
import { BackupApi, type Backup, type ScheduleData } from './backup';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: BackupApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new BackupApi(client), calls };
}

const sampleBackup: Backup = {
  id: 'backup-1',
  label: 'Weekly Backup',
  file_path: '/backups/backup-1.tar.gz',
  size_bytes: 1048576,
  checksum_sha256: 'abc123',
  is_s3: false,
  created_at: '2026-05-27T00:00:00Z',
  expires_at: '2026-06-27T00:00:00Z',
};

const sampleSchedule: ScheduleData = {
  auto_backup_interval_days: 7,
  retention_count: 5,
  next_scheduled_backup: 1769459200,
  next_scheduled_backup_iso: '2026-05-28T00:00:00Z',
};

describe('BackupApi', () => {
  describe('list()', () => {
    it('GETs /api/v1/admin/backup/list and unwraps { data }', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
      ]);

      const result = await api.list();

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/list');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([sampleBackup]);
    });

    it('returns empty array when no backups exist', async () => {
      const { api } = makeApi([
        { status: 200, body: { success: true, data: [], count: 0 } },
      ]);

      const result = await api.list();

      expect(result).toEqual([]);
    });
  });

  describe('create()', () => {
    it('POSTs to /api/v1/admin/backup/create with optional label', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: {
            success: true,
            message: 'Backup created successfully',
            data: { backup_id: 'backup-2', file_path: '/backups/backup-2.tar.gz', size_bytes: 2097152 },
          },
        },
      ]);

      const result = await api.create({ label: 'My Backup' });

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/create');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(calls[0]!.init!.body).toBe(JSON.stringify({ label: 'My Backup' }));
      expect(result).toEqual({
        message: 'Backup created successfully',
        backup_id: 'backup-2',
        file_path: '/backups/backup-2.tar.gz',
        size_bytes: 2097152,
      });
    });

    it('POSTs without label when omitted', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: {
            success: true,
            message: 'Backup created',
            data: { backup_id: 'backup-3', file_path: '/backups/backup-3.tar.gz', size_bytes: 3145728 },
          },
        },
      ]);

      const result = await api.create();

      expect(calls[0]!.init!.body).toBe(JSON.stringify({}));
      expect(result).toEqual({
        message: 'Backup created',
        backup_id: 'backup-3',
        file_path: '/backups/backup-3.tar.gz',
        size_bytes: 3145728,
      });
    });
  });

  describe('delete()', () => {
    it('DELETEs /api/v1/admin/backup/{id}', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Backup deleted successfully' } },
      ]);

      const result = await api.delete('backup-1');

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/backup-1');
      expect(calls[0]!.init!.method).toBe('DELETE');
      expect(result).toEqual({ message: 'Backup deleted successfully' });
    });
  });

  describe('restore()', () => {
    it('POSTs to /api/v1/admin/backup/{id}/restore', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Restore completed' } },
      ]);

      const result = await api.restore('backup-1');

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/backup-1/restore');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result).toEqual({ message: 'Restore completed' });
    });
  });

  describe('uploadToS3()', () => {
    it('POSTs to /api/v1/admin/backup/{id}/upload-s3', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Backup uploaded to S3' } },
      ]);

      const result = await api.uploadToS3('backup-1');

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/backup-1/upload-s3');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result).toEqual({ message: 'Backup uploaded to S3' });
    });
  });

  describe('getSchedule()', () => {
    it('GETs /api/v1/admin/backup/schedule and unwraps { data }', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      const result = await api.getSchedule();

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/schedule');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(sampleSchedule);
    });

    it('returns null next_scheduled_backup when no backup is scheduled', async () => {
      const scheduleNoNext: ScheduleData = {
        auto_backup_interval_days: 7,
        retention_count: 5,
        next_scheduled_backup: null,
        next_scheduled_backup_iso: null,
      };
      const { api } = makeApi([
        { status: 200, body: { success: true, data: scheduleNoNext } },
      ]);

      const result = await api.getSchedule();

      expect(result.next_scheduled_backup).toBeNull();
      expect(result.next_scheduled_backup_iso).toBeNull();
    });
  });

  describe('updateSchedule()', () => {
    it('PUTs to /api/v1/admin/backup/schedule with interval and retention', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: {
            success: true,
            message: 'Schedule updated successfully',
            data: { auto_backup_interval_days: 14, retention_count: 10 },
          },
        },
      ]);

      const result = await api.updateSchedule({
        auto_backup_interval_days: 14,
        retention_count: 10,
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/backup/schedule');
      expect(calls[0]!.init!.method).toBe('PUT');
      expect(calls[0]!.init!.body).toBe(
        JSON.stringify({ auto_backup_interval_days: 14, retention_count: 10 }),
      );
      expect(result).toEqual({ auto_backup_interval_days: 14, retention_count: 10 });
    });

    it('PUTs with only interval when retention is omitted', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: {
            success: true,
            message: 'Schedule updated successfully',
            data: { auto_backup_interval_days: 3, retention_count: 5 },
          },
        },
      ]);

      await api.updateSchedule({ auto_backup_interval_days: 3 });

      expect(calls[0]!.init!.body).toBe(JSON.stringify({ auto_backup_interval_days: 3 }));
    });
  });
});
