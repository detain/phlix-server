/**
 * ServicesPage — admin "Services" page with two sections:
 *   1. Trakt.tv OAuth connect/disconnect
 *   2. Last.fm scrobbling connect/disconnect
 *
 * Security:
 *  - No `dangerouslySetInnerHTML` — all server/API strings as text.
 *  - Admin gate handled server-side + `useAdminGuard` in App shell.
 *
 * Async/resident rules:
 *  - Status polling via GET status on mount.
 *  - useEffect cleans up on unmount.
 *
 * @since 1.4c
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { TraktApi, type TraktStatus } from '../api/trakt';
import { LastfmApi, type LastfmStatus } from '../api/lastfm';
import { useToast } from '../components/Toast';

export interface ServicesPageProps {
  client: ApiClient;
}

export function ServicesPage({ client }: ServicesPageProps): JSX.Element {
  const traktApiRef = useRef(new TraktApi(client));
  const lastfmApiRef = useRef(new LastfmApi(client));
  // Destructure the stable `push` callback — the whole `useToast()`
  // context value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // ─── Trakt state ─────────────────────────────────────────────────────────────
  const [traktStatus, setTraktStatus] = useState<TraktStatus | null>(null);
  const [traktLoading, setTraktLoading] = useState(true);
  const [traktDisconnecting, setTraktDisconnecting] = useState(false);

  const loadTraktStatus = useCallback(async (): Promise<void> => {
    try {
      const status = await traktApiRef.current.getStatus();
      setTraktStatus(status);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load Trakt status.';
      pushToast(msg, 'error');
    } finally {
      setTraktLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadTraktStatus();
  }, [loadTraktStatus]);

  const connectTrakt = useCallback((): void => {
    // Initiate OAuth flow via full-page redirect to Trakt
    traktApiRef.current.navigateToAuthorize();
  }, []);

  const disconnectTrakt = useCallback(async (): Promise<void> => {
    if (traktDisconnecting) return;
    setTraktDisconnecting(true);
    try {
      await traktApiRef.current.disconnect();
      pushToast('Trakt disconnected.', 'success');
      await loadTraktStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to disconnect Trakt.';
      pushToast(msg, 'error');
    } finally {
      setTraktDisconnecting(false);
    }
  }, [traktDisconnecting, pushToast, loadTraktStatus]);

  // ─── Last.fm state ───────────────────────────────────────────────────────────
  const [lastfmStatus, setLastfmStatus] = useState<LastfmStatus | null>(null);
  const [lastfmLoading, setLastfmLoading] = useState(true);
  const [lastfmDisconnecting, setLastfmDisconnecting] = useState(false);

  const loadLastfmStatus = useCallback(async (): Promise<void> => {
    try {
      const status = await lastfmApiRef.current.getStatus();
      setLastfmStatus(status);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load Last.fm status.';
      pushToast(msg, 'error');
    } finally {
      setLastfmLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadLastfmStatus();
  }, [loadLastfmStatus]);

  const connectLastfm = useCallback((): void => {
    // Navigate to the existing Last.fm OAuth page
    lastfmApiRef.current.navigateToConnect();
  }, []);

  const disconnectLastfm = useCallback(async (): Promise<void> => {
    if (lastfmDisconnecting) return;
    setLastfmDisconnecting(true);
    try {
      await lastfmApiRef.current.disconnect();
      pushToast('Last.fm disconnected.', 'success');
      await loadLastfmStatus();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to disconnect Last.fm.';
      pushToast(msg, 'error');
    } finally {
      setLastfmDisconnecting(false);
    }
  }, [lastfmDisconnecting, pushToast, loadLastfmStatus]);

  return (
    <section className="page page--services" aria-labelledby="services-heading">
      <h1 id="services-heading">Services</h1>

      {/* ── Section 1: Trakt.tv ──────────────────────────────────────────── */}
      <section className="services__section" aria-labelledby="trakt-heading">
        <div className="services__section-header">
          <h2 id="trakt-heading">Trakt.tv</h2>
          {traktStatus !== null && (
            <span className={`status-badge ${traktStatus.connected ? 'status-badge--running' : ''}`}>
              {traktStatus.connected ? 'Connected' : 'Not connected'}
            </span>
          )}
        </div>

        <div className="services__card">
          {traktLoading ? (
            <p role="status" aria-live="polite">Loading Trakt status…</p>
          ) : traktStatus === null ? (
            <p className="services__empty">Unable to load Trakt status.</p>
          ) : (
            <>
              {traktStatus.connected && traktStatus.username !== null && (
                <dl className="services__dl">
                  <dt>Username</dt>
                  <dd>{traktStatus.username}</dd>
                </dl>
              )}

              <div className="services__card-actions">
                {!traktStatus.connected ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={connectTrakt}
                  >
                    Connect to Trakt
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn--danger"
                    onClick={() => void disconnectTrakt()}
                    disabled={traktDisconnecting}
                    aria-busy={traktDisconnecting}
                  >
                    {traktDisconnecting ? 'Disconnecting…' : 'Disconnect'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </section>

      {/* ── Section 2: Last.fm ───────────────────────────────────────────── */}
      <section className="services__section" aria-labelledby="lastfm-heading">
        <div className="services__section-header">
          <h2 id="lastfm-heading">Last.fm</h2>
          {lastfmStatus !== null && (
            <span className={`status-badge ${lastfmStatus.connected ? 'status-badge--running' : ''}`}>
              {lastfmStatus.connected ? 'Connected' : 'Not connected'}
            </span>
          )}
        </div>

        <div className="services__card">
          {lastfmLoading ? (
            <p role="status" aria-live="polite">Loading Last.fm status…</p>
          ) : lastfmStatus === null ? (
            <p className="services__empty">Unable to load Last.fm status.</p>
          ) : (
            <>
              {lastfmStatus.connected && lastfmStatus.username !== null && (
                <dl className="services__dl">
                  <dt>Username</dt>
                  <dd>{lastfmStatus.username}</dd>
                  <dt>API key</dt>
                  <dd>{lastfmStatus.api_key_set ? 'Set' : 'Not set'}</dd>
                </dl>
              )}

              <div className="services__card-actions">
                {!lastfmStatus.connected ? (
                  <button
                    type="button"
                    className="btn--primary"
                    onClick={connectLastfm}
                  >
                    Connect Last.fm
                  </button>
                ) : (
                  <button
                    type="button"
                    className="btn--danger"
                    onClick={() => void disconnectLastfm()}
                    disabled={lastfmDisconnecting}
                    aria-busy={lastfmDisconnecting}
                  >
                    {lastfmDisconnecting ? 'Disconnecting…' : 'Disconnect'}
                  </button>
                )}
              </div>
            </>
          )}
        </div>
      </section>
    </section>
  );
}
