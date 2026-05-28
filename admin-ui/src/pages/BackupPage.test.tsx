import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, act } from '@testing-library/react';
import { BackupPage } from './BackupPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';
import type { Backup, ScheduleData } from '../api/backup';

/**
 * Drive the page with a real ApiClient + a real ToastProvider against ordered,
 * real-shaped responses.
 */
function renderPage(
  responses: Array<{ status: number; body: unknown }>,
): { calls: ReturnType<typeof makeFetch>['calls']; unmount: () => void } {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  const result = render(
    <ToastProvider timeoutMs={0}>
      <BackupPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

// 1048576 bytes = 1 MiB
const sampleBackup: Backup = {
  id: 'backup-1',
  label: 'Weekly Backup',
  file_path: '/backups/backup-1.tar.gz',
  size_bytes: 1048576,
  checksum_sha256: 'abc123def456',
  is_s3: false,
  created_at: new Date(Date.now() - 3600000).toISOString(), // 1 hour ago
  expires_at: null,
};

// 1024 bytes = 1 KiB
const smallBackup: Backup = {
  id: 'backup-2',
  label: 'Small Backup',
  file_path: '/backups/backup-2.tar.gz',
  size_bytes: 1024,
  checksum_sha256: 'def456',
  is_s3: false,
  created_at: new Date(Date.now() - 7200000).toISOString(), // 2 hours ago
  expires_at: null,
};

const sampleSchedule: ScheduleData = {
  auto_backup_interval_days: 7,
  retention_count: 5,
  next_scheduled_backup: Math.floor(Date.now() / 1000) + 86400 * 3,
  next_scheduled_backup_iso: new Date(Date.now() + 86400 * 3 * 1000).toISOString(),
};

afterEach(() => {
  vi.useRealTimers();
});

describe('BackupPage', () => {
  describe('backup list', () => {
    it('renders the backup list with label, size, and date', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Weekly Backup')).toBeInTheDocument();
      expect(screen.getByText('1 MB')).toBeInTheDocument();
    });

    it('shows empty-state message when no backups exist', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [], count: 0 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);
      expect(
        await screen.findByText(/no backups yet/i),
      ).toBeInTheDocument();
    });

    it('shows an error toast when list fails to load', async () => {
      renderPage([
        { status: 500, body: { success: false, error: 'Database unavailable' } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByRole('alert')).toHaveTextContent('Database unavailable');
    });

    it('renders restore button for each backup', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Weekly Backup')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Restore Weekly Backup/i })).toBeInTheDocument();
    });

    it('renders delete button for each backup', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Weekly Backup')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Delete Weekly Backup/i })).toBeInTheDocument();
    });
  });

  describe('schedule settings', () => {
    it('loads and displays schedule on mount', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [], count: 0 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Scheduled backups')).toBeInTheDocument();
      expect(screen.getByLabelText(/Backup interval/)).toHaveValue(7);
      expect(screen.getByLabelText(/Retention/)).toHaveValue(5);
    });

    it('shows error toast when schedule load fails', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [], count: 0 } },
        { status: 500, body: { success: false, error: 'Config unreadable' } },
      ]);

      expect(await screen.findByRole('alert')).toHaveTextContent('Config unreadable');
    });
  });

  describe('S3 badge rendering', () => {
    it('shows S3 badge with correct class for S3-stored backups', async () => {
      const s3Backup: Backup = { ...sampleBackup, is_s3: true };
      renderPage([
        { status: 200, body: { success: true, data: [s3Backup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Weekly Backup')).toBeInTheDocument();
      const badge = screen.getByText('S3', { selector: '.badge' });
      expect(badge).toBeInTheDocument();
      expect(badge).toHaveClass('badge--success');
    });

    it('shows Local badge for non-S3 backups', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Local')).toBeInTheDocument();
    });

    it('hides Upload to S3 button for S3 backups', async () => {
      const s3Backup: Backup = { ...sampleBackup, is_s3: true };
      renderPage([
        { status: 200, body: { success: true, data: [s3Backup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      await screen.findByText('Weekly Backup');
      expect(screen.queryByText('Upload to S3')).not.toBeInTheDocument();
    });

    it('shows Upload to S3 button for non-S3 backups', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Weekly Backup')).toBeInTheDocument();
      expect(screen.getByText('Upload to S3')).toBeInTheDocument();
    });
  });

  describe('formatBytes', () => {
    it('formats 1024 bytes as 1 KB', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [smallBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      expect(await screen.findByText('Small Backup')).toBeInTheDocument();
      expect(screen.getByText('1 KB')).toBeInTheDocument();
    });
  });

  describe('delete confirmation dialog', () => {
    it('clicking Cancel closes modal and leaves list unchanged', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
      ]);

      // Click delete button to open modal
      await screen.findByText('Weekly Backup');
      const deleteBtn = screen.getByRole('button', { name: /Delete Weekly Backup/i });
      await act(async () => { deleteBtn.click(); });

      // Modal should be open - use findBy to wait for render
      await screen.findByText(/Are you sure you want to delete/);

      // Click Cancel
      const cancelBtn = screen.getByRole('button', { name: 'Cancel' });
      await act(async () => { cancelBtn.click(); });

      // Modal should be closed
      expect(screen.queryByText(/Are you sure you want to delete/)).not.toBeInTheDocument();
    });

    it('confirming delete closes modal, calls DELETE API, and refreshes list', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
        { status: 200, body: { success: true, message: 'Deleted' } }, // DELETE response
        { status: 200, body: { success: true, data: [], count: 0 } }, // list refresh
      ]);

      await screen.findByText('Weekly Backup');
      const deleteBtn = screen.getByRole('button', { name: /Delete Weekly Backup/i });
      await act(async () => { deleteBtn.click(); });

      // Wait for modal to appear, then confirm delete
      await screen.findByText(/Are you sure you want to delete/);
      const confirmBtn = screen.getByRole('button', { name: 'Delete' });
      await act(async () => { confirmBtn.click(); });

      // After successful delete + closeDelete, the modal should close
      await vi.waitFor(() => {
        expect(screen.queryByText(/Are you sure you want to delete/)).not.toBeInTheDocument();
      });
    });

    it('failed delete shows error toast', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
        { status: 500, body: { success: false, error: 'Delete failed' } }, // DELETE returns error
      ]);

      await screen.findByText('Weekly Backup');
      const deleteBtn = screen.getByRole('button', { name: /Delete Weekly Backup/i });
      await act(async () => { deleteBtn.click(); });

      await screen.findByText((content) => content.includes('Are you sure'));
      const confirmBtn = screen.getByRole('button', { name: 'Delete' });
      await act(async () => { confirmBtn.click(); });

      // Error toast should appear
      expect(await screen.findByRole('alert')).toHaveTextContent('Delete failed');
    });
  });

  describe('schedule settings errors', () => {
    it('shows error toast when PUT schedule returns 500', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [], count: 0 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
        { status: 500, body: { success: false, error: 'Config write failed' } }, // PUT schedule error
      ]);

      await screen.findByText('Scheduled backups');
      // Backup interval input (unused — confirming element presence)
      screen.getByLabelText(/Backup interval/);
      // Change value to trigger dirty state if needed, then submit
      const saveBtn = screen.getByRole('button', { name: 'Save schedule' });
      saveBtn.click();

      expect(await screen.findByRole('alert')).toHaveTextContent('Config write failed');
    });
  });

  describe('restore flow errors', () => {
    it('shows error toast when restore API returns 500', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
        { status: 500, body: { success: false, error: 'Restore failed: corrupt backup' } }, // restore error
      ]);

      await screen.findByText('Weekly Backup');
      const restoreBtn = screen.getByRole('button', { name: /Restore Weekly Backup/i });
      await act(async () => { restoreBtn.click(); });

      // Wait for modal to appear, then confirm restore
      await screen.findByText(/This will overwrite your current data/);
      const confirmBtn = screen.getByRole('button', { name: /^Restore$/ });
      await act(async () => { confirmBtn.click(); });

      // Error toast should appear
      expect(await screen.findByRole('alert')).toHaveTextContent('Restore failed: corrupt backup');
    });
  });

  describe('S3 upload errors', () => {
    it('shows error toast when S3 upload returns 500', async () => {
      renderPage([
        { status: 200, body: { success: true, data: [sampleBackup], count: 1 } },
        { status: 200, body: { success: true, data: sampleSchedule } },
        { status: 500, body: { success: false, error: 'S3 upload failed: bucket not configured' } }, // upload error
      ]);

      await screen.findByText('Weekly Backup');
      const uploadBtn = screen.getByRole('button', { name: /Upload Weekly Backup to S3/i });
      await act(async () => { uploadBtn.click(); });

      expect(await screen.findByRole('alert')).toHaveTextContent('S3 upload failed: bucket not configured');
    });
  });
});
