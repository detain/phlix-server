/**
 * BackupPage — the admin "Backup" feature page (1.5).
 *
 * Provides two sections:
 * 1. Backup list with create, restore, upload-to-S3, and delete actions
 * 2. Schedule settings with interval and retention configuration
 *
 * Security: every server/API string (names, errors, messages) is rendered as
 * a React text child — no `dangerouslySetInnerHTML`.
 *
 * @since 1.5
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { BackupApi, type Backup, type ScheduleData } from '../api/backup';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

/** Human-readable file size from bytes. */
function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

/** Relative time label from an ISO string. */
function relativeTime(isoString: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  if (diffSec < 60) return 'just now';
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHour = Math.floor(diffMin / 60);
  if (diffHour < 24) return `${diffHour}h ago`;
  const diffDay = Math.floor(diffHour / 24);
  return `${diffDay}d ago`;
}

/** Next-backup relative label from a unix timestamp. */
function nextBackupRelative(timestamp: number | null): string {
  if (timestamp === null) return 'Not scheduled';
  const now = Math.floor(Date.now() / 1000);
  const diffSec = timestamp - now;
  if (diffSec < 0) return 'Overdue';
  const diffDay = Math.floor(diffSec / 86400);
  if (diffDay === 0) return 'Today';
  if (diffDay === 1) return 'Tomorrow';
  return `in ${diffDay} days`;
}

export interface BackupPageProps {
  client: ApiClient;
}

export function BackupPage({ client }: BackupPageProps): JSX.Element {
  const apiRef = useRef(new BackupApi(client));
  const api = apiRef.current;

  // Destructure the stable `push` callback from useToast.
  // The provider's `push` itself is a stable `useCallback` reference.
  const { push: pushToast } = useToast();

  // Section 1: Backup list state.
  const [backups, setBackups] = useState<Backup[]>([]);
  const [loading, setLoading] = useState(true);
  const [createOpen, setCreateOpen] = useState(false);
  const [createLabel, setCreateLabel] = useState('');
  const [creating, setCreating] = useState(false);
  const [restoring, setRestoring] = useState<Backup | null>(null);
  const [restoreConfirming, setRestoreConfirming] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<Backup | null>(null);
  const [deleteConfirming, setDeleteConfirming] = useState(false);
  const [s3Uploading, setS3Uploading] = useState<string | null>(null);

  // Section 2: Schedule state.
  const [schedule, setSchedule] = useState<ScheduleData | null>(null);
  const [scheduleLoading, setScheduleLoading] = useState(true);
  const [intervalDays, setIntervalDays] = useState('');
  const [retentionCount, setRetentionCount] = useState('');
  const [savingSchedule, setSavingSchedule] = useState(false);

  const loadBackups = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const rows = await api.list();
      setBackups(rows);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Failed to load backups.';
      pushToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }, [api, pushToast]);

  const loadSchedule = useCallback(async (): Promise<void> => {
    setScheduleLoading(true);
    try {
      const data = await api.getSchedule();
      setSchedule(data);
      setIntervalDays(String(data.auto_backup_interval_days));
      setRetentionCount(String(data.retention_count));
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Failed to load schedule.';
      pushToast(message, 'error');
    } finally {
      setScheduleLoading(false);
    }
  }, [api, pushToast]);

  useEffect(() => {
    void loadBackups();
    void loadSchedule();
  }, [loadBackups, loadSchedule]);

  // Section 1: Create backup.
  const openCreate = (): void => {
    setCreateLabel('');
    setCreateOpen(true);
  };

  const closeCreate = (): void => {
    setCreateOpen(false);
    setCreateLabel('');
  };

  const submitCreate = async (): Promise<void> => {
    setCreating(true);
    try {
      const result = await api.create(
        createLabel.trim() ? { label: createLabel.trim() } : {},
      );
      pushToast(result.message || 'Backup created.', 'success');
      closeCreate();
      await loadBackups();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Failed to create backup.';
      pushToast(message, 'error');
    } finally {
      setCreating(false);
    }
  };

  // Section 1: Restore backup.
  const openRestore = (backup: Backup): void => {
    setRestoring(backup);
  };

  const closeRestore = (): void => {
    setRestoring(null);
    setRestoreConfirming(false);
  };

  const openDelete = (backup: Backup): void => {
    setDeleteTarget(backup);
  };

  const closeDelete = (): void => {
    setDeleteTarget(null);
    setDeleteConfirming(false);
  };

  const confirmRestore = async (): Promise<void> => {
    if (!restoring) return;
    setRestoreConfirming(true);
    try {
      const result = await api.restore(restoring.id);
      pushToast(result.message || 'Restore completed.', 'success');
      closeRestore();
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Restore failed.';
      pushToast(message, 'error');
      closeRestore();
    }
  };

  // Section 1: Delete backup.
  const deleteBackup = useCallback(
    async (backup: Backup): Promise<void> => {
      try {
        await api.delete(backup.id);
        pushToast('Backup deleted.', 'success');
        await loadBackups();
      } catch (err) {
        const message = err instanceof ApiError ? err.message : 'Failed to delete backup.';
        pushToast(message, 'error');
      }
    },
    [api, loadBackups, pushToast],
  );

  // Section 1: Upload to S3.
  const uploadToS3 = useCallback(
    async (backup: Backup): Promise<void> => {
      setS3Uploading(backup.id);
      try {
        const result = await api.uploadToS3(backup.id);
        pushToast(result.message || 'Uploaded to S3.', 'success');
        await loadBackups();
      } catch (err) {
        const message = err instanceof ApiError ? err.message : 'S3 upload failed.';
        pushToast(message, 'error');
      } finally {
        setS3Uploading(null);
      }
    },
    [api, loadBackups, pushToast],
  );

  // Section 2: Save schedule.
  const saveSchedule = async (): Promise<void> => {
    const interval = parseInt(intervalDays, 10);
    const retention = parseInt(retentionCount, 10);

    if (isNaN(interval) || interval < 0) {
      pushToast('Backup interval must be a non-negative number.', 'error');
      return;
    }
    if (isNaN(retention) || retention < 1) {
      pushToast('Retention count must be at least 1.', 'error');
      return;
    }

    setSavingSchedule(true);
    try {
      const result = await api.updateSchedule({
        auto_backup_interval_days: interval,
        retention_count: retention,
      });
      pushToast('Schedule saved.', 'success');
      setSchedule((prev) =>
        prev
          ? {
              ...prev,
              auto_backup_interval_days: result.auto_backup_interval_days,
              retention_count: result.retention_count,
            }
          : prev,
      );
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Failed to save schedule.';
      pushToast(message, 'error');
    } finally {
      setSavingSchedule(false);
    }
  };

  return (
    <div className="page page--backup">
      {/* Section 1: Backup list */}
      <section className="page__section" aria-labelledby="backups-heading">
        <header className="page__header">
          <h1 id="backups-heading">Backups</h1>
          <button type="button" onClick={openCreate}>
            Create backup
          </button>
        </header>

        {loading ? (
          <p role="status" aria-live="polite">
            Loading…
          </p>
        ) : backups.length === 0 ? (
          <p className="page__hint">No backups yet. Create one to get started.</p>
        ) : (
          <table className="data-table" aria-label="Backups">
            <thead>
              <tr>
                <th scope="col">Label</th>
                <th scope="col">Size</th>
                <th scope="col">Created</th>
                <th scope="col">S3</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {backups.map((backup) => (
                <tr key={backup.id}>
                  <td>{backup.label || <span className="text-muted">Unnamed</span>}</td>
                  <td>{formatBytes(backup.size_bytes)}</td>
                  <td>
                    <span title={backup.created_at}>{relativeTime(backup.created_at)}</span>
                  </td>
                  <td>
                    {backup.is_s3 ? (
                      <span className="badge badge--success">S3</span>
                    ) : (
                      <span className="badge badge--muted">Local</span>
                    )}
                  </td>
                  <td>
                    <div className="action-buttons">
                      <button
                        type="button"
                        className="btn btn--danger btn--sm"
                        onClick={() => openRestore(backup)}
                        aria-label={`Restore ${backup.label || backup.id}`}
                      >
                        Restore
                      </button>
                      {!backup.is_s3 && (
                        <button
                          type="button"
                          className="btn btn--secondary btn--sm"
                          onClick={() => void uploadToS3(backup)}
                          disabled={s3Uploading === backup.id}
                          aria-label={`Upload ${backup.label || backup.id} to S3`}
                        >
                          {s3Uploading === backup.id ? 'Uploading…' : 'Upload to S3'}
                        </button>
                      )}
                      <button
                        type="button"
                        className="btn btn--danger btn--sm"
                        onClick={() => openDelete(backup)}
                        aria-label={`Delete ${backup.label || backup.id}`}
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      {/* Section 2: Schedule settings */}
      <section className="page__section" aria-labelledby="schedule-heading">
        <header className="page__header">
          <h2 id="schedule-heading">Scheduled backups</h2>
        </header>

        {scheduleLoading ? (
          <p role="status" aria-live="polite">
            Loading…
          </p>
        ) : schedule ? (
          <div className="schedule-card">
            <div className="schedule-card__info">
              <p>
                <strong>Next scheduled backup:</strong>{' '}
                {schedule.next_scheduled_backup !== null ? (
                  <>
                    <span title={schedule.next_scheduled_backup_iso ?? ''}>
                      {nextBackupRelative(schedule.next_scheduled_backup)}
                    </span>
                    {schedule.next_scheduled_backup_iso && (
                      <span className="text-muted"> ({schedule.next_scheduled_backup_iso})</span>
                    )}
                  </>
                ) : (
                  <span className="text-muted">Not scheduled</span>
                )}
              </p>
            </div>

            <Form onSubmit={saveSchedule} busy={savingSchedule}>
              <div className="form__row">
                <Field
                  id="backup-interval"
                  label="Backup interval (days)"
                  type="number"
                  value={intervalDays}
                  onChange={setIntervalDays}
                  min={0}
                  required
                />
                <Field
                  id="backup-retention"
                  label="Retention count"
                  type="number"
                  value={retentionCount}
                  onChange={setRetentionCount}
                  min={1}
                  required
                />
              </div>
              <div className="form__actions">
                <button type="submit" disabled={savingSchedule}>
                  {savingSchedule ? 'Saving…' : 'Save schedule'}
                </button>
              </div>
            </Form>
          </div>
        ) : null}
      </section>

      {/* Create backup modal */}
      <Modal open={createOpen} title="Create backup" onClose={closeCreate}>
        <Form onSubmit={submitCreate} busy={creating}>
          <Field
            id="backup-label"
            label="Label (optional)"
            value={createLabel}
            onChange={setCreateLabel}
            placeholder="e.g., Weekly backup"
          />
          <div className="form__actions">
            <button type="submit" disabled={creating}>
              {creating ? 'Creating…' : 'Create'}
            </button>
            <button type="button" onClick={closeCreate}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>

      {/* Restore confirmation modal */}
      <Modal
        open={restoring !== null}
        title="Restore backup"
        onClose={closeRestore}
      >
        <p>
          This will overwrite your current data.{' '}
          <strong>Continue?</strong>
        </p>
        <div className="form__actions">
          <button
            type="button"
            className="btn btn--danger"
            onClick={() => void confirmRestore()}
            disabled={restoreConfirming}
          >
            {restoreConfirming ? 'Restoring…' : 'Restore'}
          </button>
          <button type="button" onClick={closeRestore}>
            Cancel
          </button>
        </div>
      </Modal>

      {/* Delete confirmation modal */}
      <Modal
        open={deleteTarget !== null}
        title="Delete backup"
        onClose={closeDelete}
      >
        <p>
          Are you sure you want to delete backup{' '}
          <strong>{deleteTarget?.label || deleteTarget?.id}</strong>? This cannot be undone.
        </p>
        <div className="form__actions">
          <button
            type="button"
            className="btn btn--danger"
            onClick={() => {
              if (!deleteTarget) return;
              setDeleteConfirming(true);
              void deleteBackup(deleteTarget).then(() => {
                closeDelete();
              });
            }}
            disabled={deleteConfirming}
          >
            {deleteConfirming ? 'Deleting…' : 'Delete'}
          </button>
          <button type="button" onClick={closeDelete}>
            Cancel
          </button>
        </div>
      </Modal>
    </div>
  );
}
