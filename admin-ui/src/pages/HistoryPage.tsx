/**
 * HistoryPage — the user-facing "Watch History" page.
 *
 * Displays the user's watch history with support for removing individual
 * items or clearing all history. Shows progress bars for items that are
 * in progress (0 < progress < 100).
 *
 * Security: every server/API string is rendered as a React text child —
 * no `dangerouslySetInnerHTML`.
 *
 * @since 3.4
 */
import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { HistoryApi, type RecentlyWatchedItem } from '../api/history';
import { useToast } from '../components/Toast';
import { Modal } from '../components/Modal';

export interface HistoryPageProps {
  client: ApiClient;
  /** Optional HistoryApi instance for testing; if omitted, one is created internally. */
  api?: HistoryApi;
}

/**
 * Format a relative time string from an ISO timestamp.
 * Returns "X ago" format (e.g., "2 hours ago", "3 days ago").
 */
function formatTimeAgo(isoString: string): string {
  const date = new Date(isoString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);

  if (diffSec < 60) {
    return 'just now';
  }
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) {
    return `${diffMin} minute${diffMin === 1 ? '' : 's'} ago`;
  }
  const diffHour = Math.floor(diffMin / 60);
  if (diffHour < 24) {
    return `${diffHour} hour${diffHour === 1 ? '' : 's'} ago`;
  }
  const diffDay = Math.floor(diffHour / 24);
  if (diffDay < 30) {
    return `${diffDay} day${diffDay === 1 ? '' : 's'} ago`;
  }
  const diffMonth = Math.floor(diffDay / 30);
  return `${diffMonth} month${diffMonth === 1 ? '' : 's'} ago`;
}

export function HistoryPage({ client, api: apiProp }: HistoryPageProps): JSX.Element {
  const { push: pushToast } = useToast();
  const navigate = useNavigate();
  const api = apiProp ?? new HistoryApi(client);

  const [items, setItems] = useState<RecentlyWatchedItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [showClearConfirm, setShowClearConfirm] = useState(false);
  const [clearing, setClearing] = useState(false);

  const loadHistory = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const historyItems = await api.getRecentlyWatched();
      setItems(historyItems);
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to load watch history.';
      pushToast(msg, 'error');
    } finally {
      setLoading(false);
    }
  }, [api, pushToast]);

  useEffect(() => {
    void loadHistory();
  }, [loadHistory]);

  const handleRemoveItem = async (mediaItemId: string): Promise<void> => {
    try {
      await api.removeFromHistory(mediaItemId);
      pushToast('Removed from watch history.', 'success');
      await loadHistory();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to remove item.';
      pushToast(msg, 'error');
    }
  };

  const handleClearHistory = async (): Promise<void> => {
    setClearing(true);
    try {
      await api.clearHistory();
      pushToast('Watch history cleared.', 'success');
      setShowClearConfirm(false);
      await loadHistory();
    } catch (err) {
      const msg =
        err instanceof ApiError ? err.message : 'Failed to clear history.';
      pushToast(msg, 'error');
    } finally {
      setClearing(false);
    }
  };

  const handleContinueWatching = (mediaItemId: string): void => {
    // Navigate to the media item - the actual resume happens in the player
    navigate(`/media/${encodeURIComponent(mediaItemId)}`);
  };

  const getMediaTitle = (item: RecentlyWatchedItem): string => {
    return item.title ?? item.name ?? item.media_item_id ?? item.id;
  };

  const getMediaType = (item: RecentlyWatchedItem): string => {
    return item.media_type ?? item.type ?? 'media';
  };

  const getThumbnailUrl = (item: RecentlyWatchedItem): string | undefined => {
    return item.thumbnail_url ?? item.poster_url;
  };

  const showProgressBar = (item: RecentlyWatchedItem): boolean => {
    const progress = item.progress_percent;
    return progress !== undefined && progress > 0 && progress < 100;
  };

  return (
    <section className="page page--history" aria-labelledby="history-heading">
      <header className="page__header">
        <h1 id="history-heading">Watch History</h1>
        {Array.isArray(items) && items.length > 0 && (
          <button
            type="button"
            className="btn--danger btn--sm"
            onClick={() => setShowClearConfirm(true)}
          >
            Clear All
          </button>
        )}
      </header>

      {loading ? (
        <p role="status" aria-live="polite">Loading…</p>
      ) : !Array.isArray(items) || items.length === 0 ? (
        <div className="history-empty">
          <p>No watch history yet.</p>
          <p className="text-muted">Items you watch will appear here.</p>
        </div>
      ) : (
        <>
          <ul className="history-list" role="list">
            {items.map((item) => (
              <li key={item.id} className="history-item">
                <div className="history-item__thumbnail">
                  {getThumbnailUrl(item) ? (
                    <img
                      src={getThumbnailUrl(item)}
                      alt={`Thumbnail for ${getMediaTitle(item)}`}
                      className="history-item__img"
                    />
                  ) : (
                    <div className="history-item__placeholder" aria-hidden="true">
                      🎬
                    </div>
                  )}
                </div>

                <div className="history-item__info">
                  <div className="history-item__title-row">
                    <span className="history-item__title">{getMediaTitle(item)}</span>
                    <span className="badge badge--muted">{getMediaType(item)}</span>
                  </div>

                  {item.last_watched_at && (
                    <p className="history-item__time">
                      Watched {formatTimeAgo(item.last_watched_at)}
                    </p>
                  )}

                  {showProgressBar(item) && (
                    <div className="history-item__progress">
                      <div className="progress-bar">
                        <div
                          className="progress-bar__fill"
                          style={{ width: `${item.progress_percent}%` }}
                          role="progressbar"
                          aria-valuenow={item.progress_percent}
                          aria-valuemin={0}
                          aria-valuemax={100}
                        />
                      </div>
                      <span className="history-item__progress-label">
                        {Math.round(item.progress_percent ?? 0)}%
                      </span>
                    </div>
                  )}
                </div>

                <div className="history-item__actions">
                  {showProgressBar(item) && (
                    <button
                      type="button"
                      className="btn--primary btn--sm"
                      onClick={() => handleContinueWatching(item.media_item_id ?? item.id)}
                      aria-label={`Continue watching ${getMediaTitle(item)}`}
                    >
                      Continue
                    </button>
                  )}
                  <button
                    type="button"
                    className="history-item__remove"
                    onClick={() => handleRemoveItem(item.media_item_id ?? item.id)}
                    aria-label={`Remove ${getMediaTitle(item)} from history`}
                    title="Remove from history"
                  >
                    ×
                  </button>
                </div>
              </li>
            ))}
          </ul>

          {items.length >= 50 && (
            <p className="history-list__more text-muted">
              Showing {items.length} items. Older items are not shown.
            </p>
          )}
        </>
      )}

      {/* Clear history confirmation modal */}
      <Modal
        open={showClearConfirm}
        title="Clear Watch History"
        onClose={() => setShowClearConfirm(false)}
      >
        <p>Clear all items from your watch history? This cannot be undone.</p>
        <div className="form__actions">
          <button
            type="button"
            className="btn--danger"
            onClick={() => void handleClearHistory()}
            disabled={clearing}
          >
            {clearing ? 'Clearing…' : 'Clear All'}
          </button>
          <button
            type="button"
            onClick={() => setShowClearConfirm(false)}
            disabled={clearing}
          >
            Cancel
          </button>
        </div>
      </Modal>
    </section>
  );
}
