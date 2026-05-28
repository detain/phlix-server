/**
 * LibrariesPage — the admin "Libraries" feature page.
 *
 * Lists libraries (name, type, path count, current scan status) in the shared
 * {@link DataTable}; adds/edits them through a {@link Modal} + {@link Form}
 * with a {@link PathPicker} for choosing directories; deletes with a confirm
 * modal; and triggers async scans/rescans, then shows COARSE live status by
 * polling the 1.1b `scan-status` endpoint.
 *
 * Polling design (resident-safe):
 *  - On a successful scan/rescan trigger — and on initial load for a library
 *    that already has a job — a single `setInterval` per library polls
 *    `scanStatus`. The interval STOPS as soon as the job reaches a terminal
 *    state (`completed`/`failed`) or `scan_status` is `null`.
 *  - All intervals are tracked in a ref and cleared on unmount (no leaked
 *    timers, no global mutable state). The interval period is injectable
 *    (`pollIntervalMs`, default 2000ms) so tests can drive it with fake timers.
 *
 * Honesty about progress: the 1.1b worker leaves `items_*` at `0` and
 * `current_path` at `null`, so this page shows the lifecycle status only — it
 * does NOT render a fabricated per-file progress bar. On `failed` it shows the
 * server `error` string as React text.
 *
 * Security: every server/API string (names, paths, errors) is rendered as a
 * React text child — no `dangerouslySetInnerHTML`.
 *
 * @since 1.1c
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  LibrariesApi,
  LIBRARY_TYPES,
  type Library,
  type LibraryType,
  type ScanJob,
} from '../api/libraries';
import { FilesystemApi } from '../api/filesystem';
import { DataTable, type Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { PathPicker } from '../components/PathPicker';
import { useToast } from '../components/Toast';

/** Default polling period for live scan status (ms). @since 1.1c */
export const DEFAULT_POLL_INTERVAL_MS = 2000;

export interface LibrariesPageProps {
  client: ApiClient;
  /** Polling period for scan-status; small values for tests. */
  pollIntervalMs?: number;
}

/** Whether a scan-job status is terminal (polling should stop). */
function isTerminal(status: ScanJob['status']): boolean {
  return status === 'completed' || status === 'failed';
}

/** Human-readable summary of a library's current scan status. */
function statusLabel(job: ScanJob | null | undefined): string {
  if (!job) {
    return 'Idle';
  }
  switch (job.status) {
    case 'queued':
      return 'Queued';
    case 'running':
      return 'Running…';
    case 'completed':
      return 'Completed';
    case 'failed':
      return 'Failed';
    default:
      return job.status;
  }
}

export function LibrariesPage({
  client,
  pollIntervalMs = DEFAULT_POLL_INTERVAL_MS,
}: LibrariesPageProps): JSX.Element {
  const apiRef = useRef(new LibrariesApi(client));
  const fsRef = useRef(new FilesystemApi(client));
  const api = apiRef.current;
  const fs = fsRef.current;
  // Destructure the stable `push` callback. The whole `useToast()` context value
  // is a fresh object reference on every toast queue change (the provider
  // `useMemo`s `[toasts, push, dismiss]`), so depending on it inside
  // `loadLibraries` would re-fire the load effect on every toast — re-issuing
  // `api.list()` and consuming the next ordered fetch response out of band.
  // The provider's `push` itself is a stable `useCallback` reference.
  const { push: pushToast } = useToast();

  const [libraries, setLibraries] = useState<Library[]>([]);
  const [loading, setLoading] = useState(true);
  const [statuses, setStatuses] = useState<Record<string, ScanJob | null>>({});

  // Add/edit form state.
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Library | null>(null);
  const [name, setName] = useState('');
  const [type, setType] = useState<LibraryType>(LIBRARY_TYPES[0]);
  const [paths, setPaths] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  // Delete confirm state.
  const [deleting, setDeleting] = useState<Library | null>(null);

  // History view state.
  const [historyFor, setHistoryFor] = useState<Library | null>(null);
  const [history, setHistory] = useState<ScanJob[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Live-polling timers, keyed by library id. A ref so the unmount cleanup
  // always sees the latest set without re-subscribing effects.
  const timersRef = useRef<Record<string, ReturnType<typeof setInterval>>>({});

  const stopPolling = useCallback((libraryId: string): void => {
    const timer = timersRef.current[libraryId];
    if (timer !== undefined) {
      clearInterval(timer);
      delete timersRef.current[libraryId];
    }
  }, []);

  const pollOnce = useCallback(
    async (libraryId: string): Promise<void> => {
      try {
        const job = await api.scanStatus(libraryId);
        setStatuses((prev) => ({ ...prev, [libraryId]: job }));
        if (job === null || isTerminal(job.status)) {
          stopPolling(libraryId);
        }
      } catch {
        // Transient poll failure: stop this library's polling so we do not
        // hammer a failing endpoint; status simply stays as last known.
        stopPolling(libraryId);
      }
    },
    [api, stopPolling],
  );

  const startPolling = useCallback(
    (libraryId: string): void => {
      // Avoid stacking intervals for the same library.
      if (timersRef.current[libraryId] !== undefined) {
        return;
      }
      const timer = setInterval(() => {
        void pollOnce(libraryId);
      }, pollIntervalMs);
      timersRef.current[libraryId] = timer;
    },
    [pollOnce, pollIntervalMs],
  );

  const loadLibraries = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const rows = await api.list();
      setLibraries(rows);
      // Prime status + resume polling for any library with an in-flight job.
      await Promise.all(
        rows.map(async (lib) => {
          try {
            const job = await api.scanStatus(lib.id);
            setStatuses((prev) => ({ ...prev, [lib.id]: job }));
            if (job !== null && !isTerminal(job.status)) {
              startPolling(lib.id);
            }
          } catch {
            // Ignore a per-library status failure on initial load.
          }
        }),
      );
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load libraries.';
      pushToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }, [api, startPolling, pushToast]);

  useEffect(() => {
    void loadLibraries();
  }, [loadLibraries]);

  // Clear ALL intervals on unmount — no leaked timers.
  useEffect(() => {
    const timers = timersRef.current;
    return () => {
      for (const id of Object.keys(timers)) {
        clearInterval(timers[id]);
      }
    };
  }, []);

  const openAdd = (): void => {
    setEditing(null);
    setName('');
    setType(LIBRARY_TYPES[0]);
    setPaths([]);
    setFormOpen(true);
  };

  const openEdit = (lib: Library): void => {
    setEditing(lib);
    setName(lib.name);
    // `type` is shown read-only on edit (not updatable); keep a valid value.
    const known = LIBRARY_TYPES.find((t) => t === lib.type);
    setType(known ?? LIBRARY_TYPES[0]);
    setPaths([...lib.paths]);
    setFormOpen(true);
  };

  const closeForm = (): void => {
    setFormOpen(false);
    setEditing(null);
  };

  const submitForm = async (): Promise<void> => {
    if (paths.length === 0) {
      pushToast('Select at least one path.', 'error');
      return;
    }
    setSubmitting(true);
    try {
      if (editing) {
        // Edit: send only editable fields — NEVER `type`.
        await api.update(editing.id, { name, paths });
        pushToast('Library updated.', 'success');
      } else {
        const result = await api.create({ name, type, paths });
        pushToast(result.message || 'Library created.', 'success');
      }
      closeForm();
      await loadLibraries();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to save library.';
      pushToast(message, 'error');
    } finally {
      setSubmitting(false);
    }
  };

  const confirmDelete = async (): Promise<void> => {
    if (!deleting) {
      return;
    }
    try {
      await api.remove(deleting.id);
      pushToast('Library deleted.', 'success');
      setDeleting(null);
      await loadLibraries();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to delete library.';
      pushToast(message, 'error');
      setDeleting(null);
    }
  };

  const triggerScan = async (
    lib: Library,
    kind: 'scan' | 'rescan',
  ): Promise<void> => {
    try {
      const result =
        kind === 'scan' ? await api.scan(lib.id) : await api.rescan(lib.id);
      pushToast(
        result.message || `Scan queued (job ${result.job_id}).`,
        'success',
      );
      // Reflect the queued state immediately, then start polling.
      setStatuses((prev) => ({
        ...prev,
        [lib.id]: prev[lib.id]
          ? { ...(prev[lib.id] as ScanJob), status: 'queued' }
          : null,
      }));
      startPolling(lib.id);
      // Kick an immediate poll so the UI updates without waiting a full tick.
      void pollOnce(lib.id);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to queue scan.';
      pushToast(message, 'error');
    }
  };

  const openHistory = async (lib: Library): Promise<void> => {
    setHistoryFor(lib);
    setHistory([]);
    setHistoryLoading(true);
    try {
      const rows = await api.scanHistory(lib.id);
      setHistory(rows);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load history.';
      pushToast(message, 'error');
    } finally {
      setHistoryLoading(false);
    }
  };

  const columns: Array<Column<Library>> = [
    { id: 'name', header: 'Name', key: 'name' },
    { id: 'type', header: 'Type', key: 'type' },
    {
      id: 'paths',
      header: 'Paths',
      render: (lib) => `${lib.paths.length} paths`,
    },
    {
      id: 'status',
      header: 'Status',
      render: (lib) => {
        const job = statuses[lib.id];
        return (
          <span
            className={`status-badge status-badge--${job ? job.status : 'idle'}`}
            data-testid={`status-${lib.id}`}
          >
            {statusLabel(job)}
            {job && job.status === 'failed' && job.error ? (
              <span className="status-badge__error"> — {job.error}</span>
            ) : null}
          </span>
        );
      },
    },
    {
      id: 'actions',
      header: 'Actions',
      render: (lib) => (
        <div className="library-actions">
          <button
            type="button"
            onClick={() => openEdit(lib)}
            aria-label={`Edit ${lib.name}`}
          >
            Edit
          </button>
          <button
            type="button"
            onClick={() => setDeleting(lib)}
            aria-label={`Delete ${lib.name}`}
          >
            Delete
          </button>
          <button
            type="button"
            onClick={() => void triggerScan(lib, 'scan')}
            aria-label={`Scan ${lib.name}`}
          >
            Scan
          </button>
          <button
            type="button"
            onClick={() => void triggerScan(lib, 'rescan')}
            aria-label={`Rescan ${lib.name}`}
          >
            Rescan
          </button>
          <button
            type="button"
            onClick={() => void openHistory(lib)}
            aria-label={`History for ${lib.name}`}
          >
            History
          </button>
        </div>
      ),
    },
  ];

  const historyColumns: Array<Column<ScanJob>> = [
    { id: 'type', header: 'Type', key: 'type' },
    { id: 'status', header: 'Status', key: 'status' },
    {
      id: 'queued_at',
      header: 'Queued',
      render: (job) => job.queued_at ?? '',
    },
    {
      id: 'completed_at',
      header: 'Completed',
      render: (job) => job.completed_at ?? '',
    },
    { id: 'error', header: 'Error', render: (job) => job.error ?? '' },
  ];

  return (
    <section className="page page--libraries" aria-labelledby="libraries-heading">
      <header className="page__header">
        <h1 id="libraries-heading">Libraries</h1>
        <button type="button" onClick={openAdd}>
          Add library
        </button>
      </header>

      <p className="page__hint">
        Scan progress is coarse in this release — only the lifecycle
        (queued/running/completed/failed) is reported, not per-file detail.
      </p>

      {loading ? (
        <p role="status" aria-live="polite">
          Loading…
        </p>
      ) : (
        <DataTable
          columns={columns}
          rows={libraries}
          rowKey={(lib) => lib.id}
          emptyMessage="No libraries yet. Add one to get started."
          caption="Libraries"
        />
      )}

      <Modal
        open={formOpen}
        title={editing ? 'Edit library' : 'Add library'}
        onClose={closeForm}
      >
        <Form onSubmit={submitForm} busy={submitting}>
          <Field
            id="library-name"
            label="Name"
            value={name}
            onChange={setName}
            required
          />
          {editing ? (
            <div className="form__field">
              <label className="form__label" htmlFor="library-type">
                Type
              </label>
              <input
                id="library-type"
                className="form__input"
                value={type}
                readOnly
                aria-readonly="true"
              />
              <span className="form__hint">Type cannot be changed.</span>
            </div>
          ) : (
            <div className="form__field">
              <label className="form__label" htmlFor="library-type">
                Type<span aria-hidden="true"> *</span>
              </label>
              <select
                id="library-type"
                className="form__input"
                value={type}
                onChange={(e) => setType(e.target.value as LibraryType)}
              >
                {LIBRARY_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {t}
                  </option>
                ))}
              </select>
            </div>
          )}
          <PathPicker fs={fs} selected={paths} onChange={setPaths} />
          <div className="form__actions">
            <button type="submit">{editing ? 'Save' : 'Create'}</button>
            <button type="button" onClick={closeForm}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>

      <Modal
        open={deleting !== null}
        title="Delete library"
        onClose={() => setDeleting(null)}
      >
        <p>
          Delete library <strong>{deleting?.name}</strong>? This cannot be
          undone.
        </p>
        <div className="form__actions">
          <button type="button" onClick={() => void confirmDelete()}>
            Delete
          </button>
          <button type="button" onClick={() => setDeleting(null)}>
            Cancel
          </button>
        </div>
      </Modal>

      <Modal
        open={historyFor !== null}
        title={historyFor ? `Scan history — ${historyFor.name}` : 'Scan history'}
        onClose={() => setHistoryFor(null)}
      >
        {historyLoading ? (
          <p role="status" aria-live="polite">
            Loading…
          </p>
        ) : (
          <DataTable
            columns={historyColumns}
            rows={history}
            rowKey={(job) => job.id}
            emptyMessage="No scans yet."
            caption="Scan history (newest first)"
          />
        )}
      </Modal>
    </section>
  );
}
