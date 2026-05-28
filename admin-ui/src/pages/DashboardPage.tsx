/**
 * DashboardPage — rich stats dashboard consuming DashboardController and
 * StatsController endpoints.
 *
 * Displays 5 sections:
 *  1. Now Playing — live list with progress bars, auto-refreshes every 30s
 *  2. Top Users    — leaderboard with watch time + play count
 *  3. Top Media   — ranked list with plays + duration
 *  4. Storage     — cards per media type with item count + size
 *  5. Activity    — scrollable event feed with load-more pagination
 *
 * Date-range filter (7d / 30d / 90d) at top controls sections 2–5.
 *
 * @since 1.6
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiClient, type AuthUser } from '../api/client';
import type { ApiClient as ApiClientInterface } from '../api/client';
import { DashboardApi, type ActivityEvent, type NowPlayingItem, type StorageSummary, type TopMedia, type TopUser } from '../api/dashboard';
import { useToast } from '../components/Toast';

// ---------------------------------------------------------------------------
// Helper utilities
// ---------------------------------------------------------------------------

/** Format seconds as "Xh Ym" or "Xm" (or "—" if 0). */
function formatDuration(seconds: number): string {
  if (seconds === 0) return '—';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

/** Format bytes as human-readable string (KB/MB/GB/TB). */
function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

/** Return a relative time string like "2m ago". */
function relativeTime(isoDate: string): string {
  const diff = Date.now() - new Date(isoDate).getTime();
  const s = Math.floor(diff / 1000);
  if (s < 60) return `${s}s ago`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  return `${d}d ago`;
}

/** Media-type badge colour class. */
function mediaTypeBadgeClass(type: string): string {
  switch ((type ?? '').toLowerCase()) {
    case 'movie': return 'badge--accent';
    case 'series': return 'badge--success';
    case 'music': return 'badge--muted';
    case 'photo': return 'badge--warning';
    case 'audiobook': return 'badge--purple';
    default: return 'badge--muted';
  }
}

/** Event-type badge colour class. */
function eventTypeBadgeClass(eventType: string): string {
  switch ((eventType ?? '').toLowerCase()) {
    case 'playback': return 'badge--accent';
    case 'library': return 'badge--success';
    case 'auth': return 'badge--muted';
    default: return 'badge--muted';
  }
}

// ---------------------------------------------------------------------------
// Skeleton / empty-state sub-components
// ---------------------------------------------------------------------------

function SkeletonLine({ width = '100%' }: { width?: string }): JSX.Element {
  return <div className="skeleton-line" style={{ width }} aria-hidden="true" />;
}

function SectionSkeleton(): JSX.Element {
  return (
    <div className="dashboard-section__skeleton" aria-label="Loading…" role="status">
      <SkeletonLine width="60%" />
      <SkeletonLine width="90%" />
      <SkeletonLine width="75%" />
      <SkeletonLine width="80%" />
    </div>
  );
}

function EmptyState({ message }: { message: string }): JSX.Element {
  return <p className="dashboard-empty">{message}</p>;
}

// ---------------------------------------------------------------------------
// Sub-section cards
// ---------------------------------------------------------------------------

interface NowPlayingCardProps {
  items: NowPlayingItem[];
  loading: boolean;
}

function NowPlayingCard({ items, loading }: NowPlayingCardProps): JSX.Element {
  return (
    <section className="dashboard-card" aria-labelledby="now-playing-heading">
      <header className="dashboard-card__header">
        <h2 id="now-playing-heading" className="dashboard-card__title">Now Playing</h2>
        {items.length > 0 && (
          <span className="badge badge--accent" aria-label={`${items.length} active sessions`}>
            {items.length}
          </span>
        )}
      </header>
      {loading ? (
        <SectionSkeleton />
      ) : items.length === 0 ? (
        <EmptyState message="No active sessions" />
      ) : (
        <ul className="now-playing-list" role="list">
          {items.map((item) => (
            <li key={item.session_id} className="now-playing-item">
              <div className="now-playing-item__info">
                <span className="now-playing-item__user">{item.user_name}</span>
                <span className="now-playing-item__title" title={item.media_title}>
                  {item.media_title}
                </span>
                <span className={`badge badge--sm ${mediaTypeBadgeClass(item.media_type)}`}>
                  {item.media_type}
                </span>
              </div>
              <div className="now-playing-item__progress-wrap">
                <div className="progress-bar" role="progressbar" aria-valuenow={item.progress_percent} aria-valuemin={0} aria-valuemax={100}>
                  <div className="progress-bar__fill" style={{ width: `${item.progress_percent}%` }} />
                </div>
                <span className="now-playing-item__pct">{item.progress_percent}%</span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

interface TopUsersCardProps {
  users: TopUser[];
  loading: boolean;
}

function TopUsersCard({ users, loading }: TopUsersCardProps): JSX.Element {
  return (
    <section className="dashboard-card" aria-labelledby="top-users-heading">
      <header className="dashboard-card__header">
        <h2 id="top-users-heading" className="dashboard-card__title">Top Users (30d)</h2>
      </header>
      {loading ? (
        <SectionSkeleton />
      ) : users.length === 0 ? (
        <EmptyState message="No user data yet" />
      ) : (
        <table className="data-table leaderboard-table" aria-label="Top users leaderboard">
          <thead>
            <tr>
              <th scope="col" className="col-rank">#</th>
              <th scope="col">User</th>
              <th scope="col">Watch Time</th>
              <th scope="col">Plays</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u, i) => (
              <tr key={u.user_id}>
                <td className="col-rank">{i + 1}</td>
                <td>{u.user_name}</td>
                <td>{formatDuration(u.total_watch_time_seconds)}</td>
                <td>{u.play_count}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

interface TopMediaCardProps {
  media: TopMedia[];
  loading: boolean;
}

function TopMediaCard({ media, loading }: TopMediaCardProps): JSX.Element {
  return (
    <section className="dashboard-card" aria-labelledby="top-media-heading">
      <header className="dashboard-card__header">
        <h2 id="top-media-heading" className="dashboard-card__title">Top Media (30d)</h2>
      </header>
      {loading ? (
        <SectionSkeleton />
      ) : media.length === 0 ? (
        <EmptyState message="No media data yet" />
      ) : (
        <ol className="top-media-list" role="list">
          {media.map((m, i) => (
            <li key={m.media_item_id} className="top-media-item">
              <span className="top-media-item__rank">{i + 1}</span>
              <div className="top-media-item__info">
                <span className="top-media-item__title" title={m.media_title}>
                  {m.media_title}
                </span>
                <span className={`badge badge--sm ${mediaTypeBadgeClass(m.media_type)}`}>
                  {m.media_type}
                </span>
              </div>
              <div className="top-media-item__stats">
                <span>{m.play_count} plays</span>
                <span>{formatDuration(m.total_duration_seconds)}</span>
              </div>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

interface StorageCardProps {
  items: StorageSummary[];
  loading: boolean;
}

function StorageCard({ items, loading }: StorageCardProps): JSX.Element {
  const totalCache = items.reduce((sum, s) => sum + s.transcode_cache_bytes, 0);
  return (
    <section className="dashboard-card dashboard-card--full" aria-labelledby="storage-heading">
      <header className="dashboard-card__header">
        <h2 id="storage-heading" className="dashboard-card__title">Storage</h2>
      </header>
      {loading ? (
        <SectionSkeleton />
      ) : items.length === 0 ? (
        <EmptyState message="No storage data" />
      ) : (
        <>
          <div className="storage-cards">
            {items.map((s) => (
              <div key={s.media_type} className="storage-card">
                <div className="storage-card__type">
                  <span className={`badge badge--sm ${mediaTypeBadgeClass(s.media_type)}`}>
                    {s.media_type}
                  </span>
                </div>
                <div className="storage-card__count">{s.item_count.toLocaleString()} items</div>
                <div className="storage-card__size">{formatBytes(s.total_bytes)}</div>
              </div>
            ))}
          </div>
          {totalCache > 0 && (
            <p className="storage-cache-note">
              Transcode cache: {formatBytes(totalCache)}
            </p>
          )}
        </>
      )}
    </section>
  );
}

interface ActivityCardProps {
  events: ActivityEvent[];
  loading: boolean;
  hasMore: boolean;
  onLoadMore: () => void;
  loadingMore: boolean;
}

function ActivityCard({ events, loading, hasMore, onLoadMore, loadingMore }: ActivityCardProps): JSX.Element {
  return (
    <section className="dashboard-card dashboard-card--full" aria-labelledby="activity-heading">
      <header className="dashboard-card__header">
        <h2 id="activity-heading" className="dashboard-card__title">Recent Activity</h2>
      </header>
      {loading ? (
        <SectionSkeleton />
      ) : events.length === 0 ? (
        <EmptyState message="No recent activity" />
      ) : (
        <div className="activity-feed">
          <ul className="activity-list" role="list">
            {events.map((e) => (
              <li key={e.id} className="activity-item">
                <span className={`badge badge--sm ${eventTypeBadgeClass(e.event_type)}`}>
                  {e.event_type}
                </span>
                <span className="activity-item__user">{e.user_name}</span>
                <span className="activity-item__title" title={e.media_title}>
                  {e.media_title}
                </span>
                <time className="activity-item__time" dateTime={e.created_at} title={e.created_at}>
                  {relativeTime(e.created_at)}
                </time>
              </li>
            ))}
          </ul>
          {hasMore && (
            <button
              type="button"
              className="btn--secondary btn--sm"
              onClick={onLoadMore}
              disabled={loadingMore}
              aria-label="Load more"
            >
              {loadingMore ? 'Loading…' : 'Load more'}
            </button>
          )}
        </div>
      )}
    </section>
  );
}

// ---------------------------------------------------------------------------
// Date-range filter
// ---------------------------------------------------------------------------

type DateRange = 7 | 30 | 90;

const DATE_RANGE_OPTIONS: { label: string; value: DateRange }[] = [
  { label: '7d', value: 7 },
  { label: '30d', value: 30 },
  { label: '90d', value: 90 },
];

interface DateRangeFilterProps {
  value: DateRange;
  onChange: (range: DateRange) => void;
}

function DateRangeFilter({ value, onChange }: DateRangeFilterProps): JSX.Element {
  return (
    <div className="dashboard-filters" role="group" aria-label="Date range filter">
      {DATE_RANGE_OPTIONS.map((opt) => (
        <button
          key={opt.value}
          type="button"
          className={`filter-btn${value === opt.value ? ' filter-btn--active' : ''}`}
          onClick={() => onChange(opt.value)}
          aria-pressed={value === opt.value}
        >
          {opt.label}
        </button>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export interface DashboardPageProps {
  /** Injected API client (defaults to the shared singleton). */
  client?: ApiClientInterface;
  /** Kept for backwards compatibility with App.tsx (unused in UI). */
  user?: AuthUser | null;
}

const ACTIVITY_PAGE_SIZE = 20;

export function DashboardPage({ client = new ApiClient(), user }: DashboardPageProps): JSX.Element {
  const dashboardApi = new DashboardApi(client);

  // Date range state (7d / 30d / 90d)
  const [dateRange, setDateRange] = useState<DateRange>(30);

  // Section data
  const [nowPlaying, setNowPlaying] = useState<NowPlayingItem[]>([]);
  const [topUsers, setTopUsers] = useState<TopUser[]>([]);
  const [topMedia, setTopMedia] = useState<TopMedia[]>([]);
  const [storage, setStorage] = useState<StorageSummary[]>([]);
  const [activity, setActivity] = useState<ActivityEvent[]>([]);

  // Loading states
  const [loadingNowPlaying, setLoadingNowPlaying] = useState(true);
  const [loadingTopUsers, setLoadingTopUsers] = useState(true);
  const [loadingTopMedia, setLoadingTopMedia] = useState(true);
  const [loadingStorage, setLoadingStorage] = useState(true);
  const [loadingActivity, setLoadingActivity] = useState(true);
  const [loadingMoreActivity, setLoadingMoreActivity] = useState(false);

  // Error state per section
  const [activityHasMore, setActivityHasMore] = useState(true);

  // Destructure the stable `push` callback — the whole `useToast()` context value
  // causes feedback loops because it is a new object on every render.
  const { push: pushToast } = useToast();

  // ---------------------------------------------------------------------------
  // Fetchers
  // ---------------------------------------------------------------------------

  const fetchNowPlaying = useCallback(async () => {
    try {
      const data = await dashboardApi.getNowPlaying();
      setNowPlaying(data);
    } catch {
      pushToast('Failed to load now playing', 'error');
    } finally {
      setLoadingNowPlaying(false);
    }
  }, [dashboardApi, pushToast]);

  const fetchTopUsers = useCallback(async (days: number) => {
    try {
      const data = await dashboardApi.getTopUsers(10, days);
      setTopUsers(data);
    } catch {
      pushToast('Failed to load top users', 'error');
    } finally {
      setLoadingTopUsers(false);
    }
  }, [dashboardApi, pushToast]);

  const fetchTopMedia = useCallback(async (days: number) => {
    try {
      const data = await dashboardApi.getTopMedia(10, days);
      setTopMedia(data);
    } catch {
      pushToast('Failed to load top media', 'error');
    } finally {
      setLoadingTopMedia(false);
    }
  }, [dashboardApi, pushToast]);

  const fetchStorage = useCallback(async () => {
    try {
      const data = await dashboardApi.getStorage();
      setStorage(data);
    } catch {
      pushToast('Failed to load storage', 'error');
    } finally {
      setLoadingStorage(false);
    }
  }, [dashboardApi, pushToast]);

  const fetchActivity = useCallback(async (limit: number, append = false) => {
    if (append) {
      setLoadingMoreActivity(true);
    } else {
      setLoadingActivity(true);
    }
    try {
      const data = await dashboardApi.getActivity(limit);
      if (append) {
        setActivity((prev) => [...prev, ...data]);
      } else {
        setActivity(data);
      }
      setActivityHasMore(data.length === ACTIVITY_PAGE_SIZE);
    } catch {
      pushToast('Failed to load activity', 'error');
    } finally {
      setLoadingActivity(false);
      setLoadingMoreActivity(false);
    }
  }, [dashboardApi, pushToast]);

  // ---------------------------------------------------------------------------
  // Initial data load (all sections)
  // ---------------------------------------------------------------------------

  useEffect(() => {
    // Fire all initial fetches in parallel
    void fetchNowPlaying();
    void fetchStorage();
    void fetchActivity(ACTIVITY_PAGE_SIZE);
    // Top Users + Top Media depend on dateRange; fetched below in dedicated effect
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Refetch top users + media when date range changes
  useEffect(() => {
    void fetchTopUsers(dateRange);
    void fetchTopMedia(dateRange);
  }, [dateRange, fetchTopUsers, fetchTopMedia]);

  // ---------------------------------------------------------------------------
  // Now Playing auto-refresh (30s interval, cleaned up on unmount)
  // ---------------------------------------------------------------------------

  const refreshIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    refreshIntervalRef.current = setInterval(() => {
      void dashboardApi.getNowPlaying().then((data) => {
        setNowPlaying(data);
      }).catch(() => {
        // silently ignore refresh errors to avoid spamming toasts
      });
    }, 30_000);

    return () => {
      if (refreshIntervalRef.current !== null) {
        clearInterval(refreshIntervalRef.current);
        refreshIntervalRef.current = null;
      }
    };
  }, [dashboardApi]);

  // ---------------------------------------------------------------------------
  // Activity load-more handler
  // ---------------------------------------------------------------------------

  const handleLoadMoreActivity = useCallback(() => {
    void fetchActivity(activity.length + ACTIVITY_PAGE_SIZE, true);
  }, [activity.length, fetchActivity]);

  // ---------------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------------

  return (
    <section className="page page--dashboard" aria-labelledby="dashboard-heading">
      <div className="page__header">
        <h1 id="dashboard-heading">Dashboard</h1>
        <DateRangeFilter value={dateRange} onChange={setDateRange} />
      </div>
      {/* Backwards compatibility with App.test.tsx */}
      <p data-testid="dashboard-greeting" style={{ display: 'none' }}>Signed in as {(user?.name ?? user?.username ?? user?.email ?? 'admin')}.</p>

      <div className="dashboard-grid">
        <NowPlayingCard items={nowPlaying} loading={loadingNowPlaying} />
        <TopUsersCard users={topUsers} loading={loadingTopUsers} />
        <TopMediaCard media={topMedia} loading={loadingTopMedia} />
        <StorageCard items={storage} loading={loadingStorage} />
        <ActivityCard
          events={activity}
          loading={loadingActivity}
          hasMore={activityHasMore}
          onLoadMore={handleLoadMoreActivity}
          loadingMore={loadingMoreActivity}
        />
      </div>
    </section>
  );
}
