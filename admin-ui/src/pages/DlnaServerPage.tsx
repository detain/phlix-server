/**
 * DlnaServerPage — admin DLNA CDS server status and toggle page (2.2).
 *
 * Shows:
 * - Current running state (🟢 Running / 🔴 Stopped)
 * - Server details (friendly name, UDN, port, base URL) when running
 * - Start/Stop toggle button with loading state
 * - Info note about the DLNA server
 *
 * @since 2.2
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { DlnaServerApi, type DlnaServerStatus } from '../api/dlnaServer';
import { useToast } from '../components/Toast';

export interface DlnaServerPageProps {
  client: ApiClient;
}

export function DlnaServerPage({ client }: DlnaServerPageProps): JSX.Element {
  const apiRef = useRef(new DlnaServerApi(client));
  // Destructure the stable `push` callback — the whole `useToast()`
  // context value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // Server status
  const [status, setStatus] = useState<DlnaServerStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [acting, setActing] = useState(false);

  const loadStatus = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const s = await apiRef.current.getStatus();
      setStatus(s);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load DLNA server status.';
      pushToast(msg, 'error');
    } finally {
      setLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadStatus();
  }, [loadStatus]);

  const handleStart = useCallback(async (): Promise<void> => {
    if (acting) return;
    setActing(true);
    try {
      const result = await apiRef.current.start();
      if (!result.success) {
        pushToast(result.message || 'Failed to start DLNA server.', 'error');
        return;
      }
      pushToast('DLNA server started.', 'success');
      await loadStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to start DLNA server.';
      pushToast(msg, 'error');
    } finally {
      setActing(false);
    }
  }, [acting, pushToast, loadStatus]);

  const handleStop = useCallback(async (): Promise<void> => {
    if (acting) return;
    setActing(true);
    try {
      const result = await apiRef.current.stop();
      if (!result.success) {
        pushToast(result.message || 'Failed to stop DLNA server.', 'error');
        return;
      }
      pushToast('DLNA server stopped.', 'success');
      await loadStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to stop DLNA server.';
      pushToast(msg, 'error');
    } finally {
      setActing(false);
    }
  }, [acting, pushToast, loadStatus]);

  const isRunning = status?.running ?? false;
  const isEnabled = status?.enabled ?? false;

  return (
    <div className="page page--dlna-server">
      <div className="page__header">
        <h1>DLNA Server</h1>
      </div>

      {/* Status card */}
      <div className="dlna-server-card" aria-live="polite">
        {loading ? (
          <p role="status">Loading DLNA server status…</p>
        ) : !isEnabled ? (
          <p className="dlna-server-card__not-configured">
            DLNA server is not configured.
            {status?.message && <span> {status.message}</span>}
          </p>
        ) : (
          <>
            <div className="dlna-server-card__status">
              <span
                className="dlna-server-card__indicator"
                aria-hidden="true"
              >
                {isRunning ? '🟢' : '🔴'}
              </span>
              <span className="dlna-server-card__label">
                {isRunning ? 'Running' : 'Stopped'}
              </span>
            </div>

            {isRunning && status !== null && (
              <dl className="dlna-server-card__details">
                {status.friendlyName && (
                  <>
                    <dt>Friendly Name</dt>
                    <dd>{status.friendlyName}</dd>
                  </>
                )}
                {status.serverId && (
                  <>
                    <dt>UDN</dt>
                    <dd>{status.serverId}</dd>
                  </>
                )}
                {status.port !== null && (
                  <>
                    <dt>Port</dt>
                    <dd>{status.port}</dd>
                  </>
                )}
                {status.baseUrl && (
                  <>
                    <dt>Base URL</dt>
                    <dd>{status.baseUrl}</dd>
                  </>
                )}
              </dl>
            )}

            <div className="dlna-server-card__actions">
              {!isRunning ? (
                <button
                  type="button"
                  className="btn btn--primary"
                  onClick={() => void handleStart()}
                  disabled={acting}
                  aria-busy={acting}
                >
                  {acting ? 'Starting…' : 'Start Server'}
                </button>
              ) : (
                <button
                  type="button"
                  className="btn btn--secondary"
                  onClick={() => void handleStop()}
                  disabled={acting}
                  aria-busy={acting}
                >
                  {acting ? 'Stopping…' : 'Stop Server'}
                </button>
              )}
            </div>
          </>
        )}
      </div>

      {/* Info note */}
      <p className="page__note">
        The DLNA server announces this Phlix instance on the local network as a
        UPnP MediaServer. Restart the server to apply configuration changes.
      </p>
    </div>
  );
}
