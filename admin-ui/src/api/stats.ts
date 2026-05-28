/**
 * StatsApi — typed wrapper over {@link ApiClient} for the admin stats
 * endpoints (`/api/v1/admin/stats/*`).
 *
 * Provides historical playback statistics, top users over time, and
 * top media by play count — consumed by the {@link DashboardPage}
 * component.
 *
 * @since 1.6
 */
import type { ApiClient } from './client';

/** A single day's aggregated playback metrics. */
export interface PlaybackStat {
  date: string;
  play_count: number;
  total_duration_seconds: number;
  completed_count: number;
}

/** A user aggregated over a stats window. */
export interface StatsTopUser {
  user_id: string;
  total_watch_time_seconds: number;
  play_count: number;
}

/** A media item aggregated over a stats window. */
export interface StatsTopMedia {
  media_item_id: string;
  play_count: number;
  total_duration_seconds: number;
}

/**
 * Typed client for the admin stats endpoints.
 *
 * @since 1.6
 */
export class StatsApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/stats/playback?from=&to=` → `{ data: PlaybackStat[] }`
   * @param from - ISO date string for range start (e.g. "-30 days" resolves server-side)
   * @param to - ISO date string for range end (e.g. "now")
   */
  async getPlaybackStats(from?: string, to?: string): Promise<PlaybackStat[]> {
    const params: Record<string, string> = {};
    if (from !== undefined) params['from'] = from;
    if (to !== undefined) params['to'] = to;
    const { data } = await this.client.get<{ data: PlaybackStat[] }>(
      '/api/v1/admin/stats/playback',
      Object.keys(params).length ? params : undefined,
    );
    return data;
  }

  /**
   * `GET /api/v1/admin/stats/top-users?limit=&since=` → `{ data: StatsTopUser[] }`
   * @param limit - Max results (default 10)
   * @param since - ISO date string (e.g. "2024-01-01")
   */
  async getTopUsers(limit?: number, since?: string): Promise<StatsTopUser[]> {
    const params: Record<string, string> = {};
    if (limit !== undefined) params['limit'] = String(limit);
    if (since !== undefined) params['since'] = since;
    const { data } = await this.client.get<{ data: StatsTopUser[] }>(
      '/api/v1/admin/stats/top-users',
      Object.keys(params).length ? params : undefined,
    );
    return data;
  }

  /**
   * `GET /api/v1/admin/stats/top-media?limit=&since=` → `{ data: StatsTopMedia[] }`
   * @param limit - Max results (default 10)
   * @param since - ISO date string (e.g. "2024-01-01")
   */
  async getTopMedia(limit?: number, since?: string): Promise<StatsTopMedia[]> {
    const params: Record<string, string> = {};
    if (limit !== undefined) params['limit'] = String(limit);
    if (since !== undefined) params['since'] = since;
    const { data } = await this.client.get<{ data: StatsTopMedia[] }>(
      '/api/v1/admin/stats/top-media',
      Object.keys(params).length ? params : undefined,
    );
    return data;
  }

  /**
   * `GET /api/v1/admin/stats/storage` → `{ data: [] }`
   * Stubbed endpoint — always returns an empty array.
   */
  async getStorageStats(): Promise<[]> {
    const { data } = await this.client.get<{ data: [] }>(
      '/api/v1/admin/stats/storage',
    );
    return data;
  }
}
