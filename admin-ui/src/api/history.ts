/**
 * HistoryApi — typed wrapper over the watch history endpoints.
 *
 * @since 3.4
 */
import type { ApiClient } from './client';

/**
 * A recently watched item as returned by the API.
 *
 * @since 3.4
 */
export interface RecentlyWatchedItem {
  id: string;
  media_item_id?: string;
  name?: string;
  title?: string;
  media_type?: string;
  type?: string;
  progress_percent?: number;
  last_watched_at?: string;
  thumbnail_url?: string;
  poster_url?: string;
  [k: string]: unknown;
}

/**
 * Response envelope for recently-watched list.
 *
 * @since 3.4
 */
export interface RecentlyWatchedResponse {
  items: RecentlyWatchedItem[];
}

/**
 * Typed client for the watch history endpoints.
 *
 * @since 3.4
 */
export class HistoryApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/users/me/recently-watched` → unwraps `{ items }`.
   *
   * @since 3.4
   */
  async getRecentlyWatched(): Promise<RecentlyWatchedItem[]> {
    const { items } = await this.client.get<RecentlyWatchedResponse>(
      '/api/v1/users/me/recently-watched',
    );
    return items;
  }

  /**
   * `DELETE /api/v1/users/me/history/{mediaItemId}` → `{ message }`.
   *
   * @since 3.4
   */
  async removeFromHistory(mediaItemId: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/users/me/history/${encodeURIComponent(mediaItemId)}`,
    );
  }

  /**
   * `DELETE /api/v1/users/me/history` → `{ message }`.
   *
   * @since 3.4
   */
  async clearHistory(): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      '/api/v1/users/me/history',
    );
  }
}
