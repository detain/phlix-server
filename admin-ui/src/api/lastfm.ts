/**
 * LastfmApi — typed wrapper for Last.fm scrobbling admin endpoints.
 *
 * @since 1.4c
 */
import type { ApiClient } from './client';

/**
 * Response shape from `GET /api/v1/admin/services/lastfm/status`.
 *
 * @since 1.4c
 */
export interface LastfmStatus {
  connected: boolean;
  username: string | null;
  api_key_set: boolean;
}

/**
 * Response shape from `POST /api/v1/admin/services/lastfm/disconnect`.
 *
 * @since 1.4c
 */
export interface LastfmDisconnectResult {
  message: string;
}

/**
 * Typed client for the Last.fm admin service endpoints.
 *
 * @since 1.4c
 */
export class LastfmApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/services/lastfm/status`
   * → `{ connected, username, api_key_set }`.
   */
  async getStatus(): Promise<LastfmStatus> {
    return this.client.get<LastfmStatus>('/api/v1/admin/services/lastfm/status');
  }

  /**
   * Initiate the Last.fm OAuth flow by navigating the browser to `/admin/lastfm`.
   * This is NOT a fetch call — it triggers a full-page redirect.
   *
   * @since 1.4c
   */
  navigateToConnect(): void {
    if (typeof window !== 'undefined') {
      window.location.href = '/admin/lastfm';
    }
  }

  /**
   * `POST /api/v1/admin/services/lastfm/disconnect`
   * → `{ message }`.
   */
  async disconnect(): Promise<LastfmDisconnectResult> {
    return this.client.post<LastfmDisconnectResult>('/api/v1/admin/services/lastfm/disconnect');
  }
}
