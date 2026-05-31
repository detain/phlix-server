/**
 * DashboardApi — typed wrapper over {@link ApiClient} for the admin
 * dashboard endpoints (`/api/v1/admin/dashboard/*`).
 *
 * Wraps now-playing sessions, top-user leaderboard, top-media ranking,
 * storage breakdown, and activity feed — all consumed by the
 * {@link DashboardPage} component.
 *
 * @since 1.6
 */
import type { ApiClient } from './client';

/** A currently-playing playback session. */
export interface NowPlayingItem {
  session_id: string;
  user_id: string;
  user_name: string;
  media_item_id: string;
  media_title: string;
  media_type: string;
  progress_percent: number;
  started_at: string;
}

/** A user leaderboard entry. */
export interface TopUser {
  user_id: string;
  user_name: string;
  total_watch_time_seconds: number;
  play_count: number;
  last_seen: string;
}

/** A media ranking entry. */
export interface TopMedia {
  media_item_id: string;
  media_title: string;
  media_type: string;
  play_count: number;
  total_duration_seconds: number;
  last_played_at: string;
}

/** Storage summary per media type. */
export interface StorageSummary {
  media_type: string;
  item_count: number;
  total_bytes: number;
  transcode_cache_bytes: number;
}

/** An event in the activity feed. */
export interface ActivityEvent {
  id: string;
  event_type: string;
  user_id: string;
  user_name: string;
  media_item_id: string;
  media_title: string;
  created_at: string;
  details: string;
}

// ---------------------------------------------------------------------------
// Server→SPA normalisers
//
// The server (`DashboardService`) and these SPA interfaces drifted on field
// names: `username`↔`user_name`, `title`↔`media_title`, `type`↔`media_type`,
// `total_watch_time`↔`total_watch_time_seconds`, `total_duration`↔
// `total_duration_seconds`, `stream_id`↔`session_id`, `occurred_at`↔
// `created_at`, and activity nests `media_title`/`media_item_id` under
// `details`. Normalise to the exact shape the cards render. Each mapper
// accepts BOTH names, so it keeps working if the server is later aligned.
// ---------------------------------------------------------------------------

type Raw = Record<string, unknown>;

function asString(value: unknown, fallback = ''): string {
  if (typeof value === 'string') return value;
  if (value === null || value === undefined) return fallback;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  return fallback;
}

function asNumber(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value;
  if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
    return Number(value);
  }
  return fallback;
}

function asArray(value: unknown): Raw[] {
  return Array.isArray(value) ? (value as Raw[]) : [];
}

function toNowPlaying(r: Raw): NowPlayingItem {
  return {
    session_id: asString(r['session_id'] ?? r['stream_id']),
    user_id: asString(r['user_id']),
    user_name: asString(r['user_name'] ?? r['username']),
    media_item_id: asString(r['media_item_id']),
    media_title: asString(r['media_title']),
    media_type: asString(r['media_type']),
    progress_percent: asNumber(r['progress_percent']),
    started_at: asString(r['started_at']),
  };
}

function toTopUser(r: Raw): TopUser {
  return {
    user_id: asString(r['user_id']),
    user_name: asString(r['user_name'] ?? r['username']),
    total_watch_time_seconds: asNumber(r['total_watch_time_seconds'] ?? r['total_watch_time']),
    play_count: asNumber(r['play_count']),
    last_seen: asString(r['last_seen']),
  };
}

function toTopMedia(r: Raw): TopMedia {
  return {
    media_item_id: asString(r['media_item_id']),
    media_title: asString(r['media_title'] ?? r['title']),
    media_type: asString(r['media_type'] ?? r['type']),
    play_count: asNumber(r['play_count']),
    total_duration_seconds: asNumber(r['total_duration_seconds'] ?? r['total_duration']),
    last_played_at: asString(r['last_played_at']),
  };
}

function toActivityEvent(r: Raw): ActivityEvent {
  const details: Raw =
    typeof r['details'] === 'object' && r['details'] !== null ? (r['details'] as Raw) : {};
  return {
    id: asString(r['id']),
    event_type: asString(r['event_type']),
    user_id: asString(r['user_id']),
    user_name: asString(r['user_name'] ?? r['username']),
    media_item_id: asString(r['media_item_id'] ?? details['media_item_id']),
    media_title: asString(r['media_title'] ?? details['media_title']),
    created_at: asString(r['created_at'] ?? r['occurred_at']),
    // The SPA renders `details` as a short string; the server sends a
    // structured object, so keep the string form when present, else ''.
    details: typeof r['details'] === 'string' ? r['details'] : '',
  };
}

/**
 * Typed client for the admin dashboard endpoints.
 *
 * @since 1.6
 */
export class DashboardApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/admin/dashboard/now-playing` → `{ success, data: NowPlayingItem[] }` */
  async getNowPlaying(): Promise<NowPlayingItem[]> {
    const { data } = await this.client.get<{ success: boolean; data: unknown }>(
      '/api/v1/admin/dashboard/now-playing',
    );
    return asArray(data).map(toNowPlaying);
  }

  /**
   * `GET /api/v1/admin/dashboard/top-users?limit=&days=` → `{ success, data: TopUser[] }`
   * @param limit - Max results (default 10)
   * @param days - Lookback window in days (default 30)
   */
  async getTopUsers(limit?: number, days?: number): Promise<TopUser[]> {
    const params: Record<string, string> = {};
    if (limit !== undefined) params['limit'] = String(limit);
    if (days !== undefined) params['days'] = String(days);
    const { data } = await this.client.get<{ success: boolean; data: unknown }>(
      '/api/v1/admin/dashboard/top-users',
      Object.keys(params).length ? params : undefined,
    );
    return asArray(data).map(toTopUser);
  }

  /**
   * `GET /api/v1/admin/dashboard/top-media?limit=&days=` → `{ success, data: TopMedia[] }`
   * @param limit - Max results (default 10)
   * @param days - Lookback window in days (default 30)
   */
  async getTopMedia(limit?: number, days?: number): Promise<TopMedia[]> {
    const params: Record<string, string> = {};
    if (limit !== undefined) params['limit'] = String(limit);
    if (days !== undefined) params['days'] = String(days);
    const { data } = await this.client.get<{ success: boolean; data: unknown }>(
      '/api/v1/admin/dashboard/top-media',
      Object.keys(params).length ? params : undefined,
    );
    return asArray(data).map(toTopMedia);
  }

  /**
   * `GET /api/v1/admin/dashboard/storage`.
   *
   * The server's `DashboardService::getStorageSummary()` returns an OBJECT —
   * `{ movie_bytes, …, items: StorageSummary[], formatted_transcode_cache }` —
   * NOT a bare list. The per-media-type rows the UI renders live under
   * `data.items`; unwrap that (and default to `[]`) so callers always get the
   * array they expect. Returning `data` verbatim handed the page an object and
   * crashed `StorageCard` with "items.reduce is not a function".
   */
  async getStorage(): Promise<StorageSummary[]> {
    const { data } = await this.client.get<{
      success: boolean;
      data: { items?: StorageSummary[] } | StorageSummary[];
    }>('/api/v1/admin/dashboard/storage');
    if (Array.isArray(data)) {
      return data;
    }
    return Array.isArray(data?.items) ? data.items : [];
  }

  /**
   * `GET /api/v1/admin/dashboard/activity?limit=` → `{ success, data: ActivityEvent[] }`
   * @param limit - Max results (default 20)
   */
  async getActivity(limit?: number): Promise<ActivityEvent[]> {
    const params = limit !== undefined ? { limit: String(limit) } : undefined;
    const { data } = await this.client.get<{ success: boolean; data: unknown }>(
      '/api/v1/admin/dashboard/activity',
      params,
    );
    return asArray(data).map(toActivityEvent);
  }
}
