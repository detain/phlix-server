/**
 * TraktApi — typed wrapper for Trakt.tv OAuth + status endpoints.
 *
 * OAuth is initiated via full-page redirect to `/api/v1/oauth/trakt`
 * (not a fetch call).
 *
 * @since 1.4c
 */
import type { ApiClient } from './client';

/**
 * Response shape from `GET /api/v1/admin/services/trakt/status`.
 *
 * @since 1.4c
 */
export interface TraktStatus {
  connected: boolean;
  username: string | null;
}

/**
 * Response shape from `POST /api/v1/admin/services/trakt/disconnect`.
 *
 * @since 1.4c
 */
export interface TraktDisconnectResult {
  message: string;
}

/**
 * Typed client for the Trakt.tv admin service endpoints.
 *
 * @since 1.4c
 */
export class TraktApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/services/trakt/status`
   * → `{ connected, username }`.
   */
  async getStatus(): Promise<TraktStatus> {
    return this.client.get<TraktStatus>('/api/v1/admin/services/trakt/status');
  }

  /**
   * `POST /api/v1/admin/services/trakt/disconnect`
   * → `{ message }`.
   */
  async disconnect(): Promise<TraktDisconnectResult> {
    return this.client.post<TraktDisconnectResult>('/api/v1/admin/services/trakt/disconnect');
  }

  /**
   * Navigate the browser to the Trakt OAuth authorisation URL.
   * This is NOT a fetch call — it triggers a full-page redirect.
   *
   * @since 1.4c
   */
  navigateToAuthorize(): void {
    if (typeof window !== 'undefined') {
      window.location.href = '/api/v1/oauth/trakt';
    }
  }
}
