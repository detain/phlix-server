/**
 * Token storage adapter.
 *
 * This is the single source of truth for where the admin SPA reads and
 * writes auth tokens. It MUST stay byte-for-byte compatible with the
 * legacy `public/assets/js/api-client.js`, which uses the following
 * `localStorage` keys:
 *
 *   - `access_token`  — JWT bearer access token (1h TTL server-side)
 *   - `refresh_token` — refresh token (7d TTL server-side)
 *   - `user`          — JSON-serialised authenticated user object
 *
 * Sharing the keys means a user who logged in via the existing SSR login
 * page is already authenticated in the SPA, and vice versa — we do NOT
 * invent a new auth mechanism (per the step 0.4 spec).
 *
 * The store is injectable (see {@link TokenStore}) so tests can supply an
 * in-memory implementation instead of touching the real `localStorage`.
 */

export const ACCESS_TOKEN_KEY = 'access_token';
export const REFRESH_TOKEN_KEY = 'refresh_token';
export const USER_KEY = 'user';

export interface TokenStore {
  getAccessToken(): string | null;
  setAccessToken(token: string): void;
  getRefreshToken(): string | null;
  setRefreshToken(token: string): void;
  getUser(): unknown | null;
  setUser(user: unknown): void;
  clear(): void;
}

/**
 * Default {@link TokenStore} backed by `window.localStorage`, using the
 * exact keys the legacy client uses.
 */
export class LocalStorageTokenStore implements TokenStore {
  constructor(private readonly storage: Storage = window.localStorage) {}

  getAccessToken(): string | null {
    return this.storage.getItem(ACCESS_TOKEN_KEY);
  }

  setAccessToken(token: string): void {
    this.storage.setItem(ACCESS_TOKEN_KEY, token);
  }

  getRefreshToken(): string | null {
    return this.storage.getItem(REFRESH_TOKEN_KEY);
  }

  setRefreshToken(token: string): void {
    this.storage.setItem(REFRESH_TOKEN_KEY, token);
  }

  getUser(): unknown | null {
    const raw = this.storage.getItem(USER_KEY);
    if (raw === null) {
      return null;
    }
    try {
      return JSON.parse(raw) as unknown;
    } catch {
      // Corrupt value — treat as absent rather than throwing.
      return null;
    }
  }

  setUser(user: unknown): void {
    this.storage.setItem(USER_KEY, JSON.stringify(user));
  }

  clear(): void {
    this.storage.removeItem(ACCESS_TOKEN_KEY);
    this.storage.removeItem(REFRESH_TOKEN_KEY);
    this.storage.removeItem(USER_KEY);
  }
}
