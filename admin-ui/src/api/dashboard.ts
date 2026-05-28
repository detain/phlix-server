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

/**
 * Typed client for the admin dashboard endpoints.
 *
 * @since 1.6
 */
export class DashboardApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/admin/dashboard/now-playing` → `{ success, data: NowPlayingItem[] }` */
  async getNowPlaying(): Promise<NowPlayingItem[]> {
    const { data } = await this.client.get<{ success: boolean; data: NowPlayingItem[] }>(
      '/api/v1/admin/dashboard/now-playing',
    );
    return data;
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
    const { data } = await this.client.get<{ success: boolean; data: TopUser[] }>(
      '/api/v1/admin/dashboard/top-users',
      Object.keys(params).length ? params : undefined,
    );
    return data;
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
    const { data } = await this.client.get<{ success: boolean; data: TopMedia[] }>(
      '/api/v1/admin/dashboard/top-media',
      Object.keys(params).length ? params : undefined,
    );
    return data;
  }

  /** `GET /api/v1/admin/dashboard/storage` → `{ success, data: StorageSummary[] }` */
  async getStorage(): Promise<StorageSummary[]> {
    const { data } = await this.client.get<{ success: boolean; data: StorageSummary[] }>(
      '/api/v1/admin/dashboard/storage',
    );
    return data;
  }

  /**
   * `GET /api/v1/admin/dashboard/activity?limit=` → `{ success, data: ActivityEvent[] }`
   * @param limit - Max results (default 20)
   */
  async getActivity(limit?: number): Promise<ActivityEvent[]> {
    const params = limit !== undefined ? { limit: String(limit) } : undefined;
    const { data } = await this.client.get<{ success: boolean; data: ActivityEvent[] }>(
      '/api/v1/admin/dashboard/activity',
      params,
    );
    return data;
  }
}
