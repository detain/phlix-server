/**
 * Typed API client for the Phlix admin SPA.
 *
 * Wraps `fetch` against the existing JSON API (`/api/v1/*`) and reuses the
 * EXISTING JWT/token mechanism from `public/assets/js/api-client.js`
 * (see {@link TokenStore}): the same `localStorage` keys, the same
 * `Authorization: Bearer <access_token>` header, the same
 * `POST /auth/refresh { refresh_token }` refresh flow, and the same
 * single-retry-on-401 behaviour. We deliberately do NOT invent a new auth
 * mechanism.
 *
 * Behaviour mirrored from the legacy client:
 *  - Adds the bearer header when an access token is present.
 *  - On a 401 response, attempts one token refresh; if it succeeds, retries
 *    the original request exactly once with the new token.
 *  - Throws an {@link ApiError} for non-2xx responses, preferring the JSON
 *    `error`/`message` field for the message (matching the legacy client's
 *    `data.error || data.message || 'Request failed'`).
 */

import { LocalStorageTokenStore, type TokenStore } from './tokenStore';

/** Shape of the authenticated user returned by `GET /api/v1/auth/me`. */
export interface AuthUser {
  id: string;
  email?: string;
  username?: string;
  name?: string;
  is_admin?: boolean;
  [key: string]: unknown;
}

/**
 * Coerce a backend "boolean" to a real boolean.
 *
 * The API serializes flags like `is_admin` straight from MySQL, where the
 * column is `TINYINT(1)` — so the wire value is `1`/`0` (or the string
 * `"1"`/`"0"`), NEVER a JSON boolean. Treat any of `true`, `1`, `"1"`,
 * `"true"` as true; everything else (incl. `0`, `"0"`, `undefined`) as false.
 */
export function normalizeBool(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true';
}

/** Error thrown for non-2xx API responses; carries the HTTP status. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly body: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface ApiClientOptions {
  /** Base URL; defaults to the current origin (same as the legacy client). */
  baseUrl?: string;
  /** Token storage adapter; defaults to {@link LocalStorageTokenStore}. */
  tokenStore?: TokenStore;
  /** Injectable fetch (for tests); defaults to the global `fetch`. */
  fetchImpl?: typeof fetch;
}

export class ApiClient {
  private readonly baseUrl: string;
  private readonly tokens: TokenStore;
  private readonly doFetch: typeof fetch;

  constructor(options: ApiClientOptions = {}) {
    this.baseUrl =
      options.baseUrl ??
      (typeof window !== 'undefined' ? window.location.origin : '');
    this.tokens = options.tokenStore ?? new LocalStorageTokenStore();
    this.doFetch = options.fetchImpl ?? globalThis.fetch.bind(globalThis);
  }

  /**
   * Perform a request. Adds the bearer header, and on a 401 attempts a
   * single token refresh + retry — exactly matching the legacy client.
   */
  async request<T = unknown>(
    method: HttpMethod,
    endpoint: string,
    data: unknown = null,
  ): Promise<T> {
    const build = (): RequestInit => {
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
      };
      const token = this.tokens.getAccessToken();
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }
      const init: RequestInit = { method, headers };
      if (
        data !== null &&
        (method === 'POST' || method === 'PUT' || method === 'PATCH')
      ) {
        init.body = JSON.stringify(data);
      }
      return init;
    };

    const url = `${this.baseUrl}${endpoint}`;
    let response = await this.doFetch(url, build());

    if (response.status === 401) {
      const refreshed = await this.refreshToken();
      if (refreshed) {
        // Rebuild so the new access token is attached.
        response = await this.doFetch(url, build());
      }
    }

    return this.handleResponse<T>(response);
  }

  private async handleResponse<T>(response: Response): Promise<T> {
    const contentType = response.headers.get('content-type') ?? '';
    const isJson = contentType.includes('application/json');
    const payload: unknown = isJson
      ? await response.json()
      : await response.text();

    if (!response.ok) {
      const message = this.extractError(payload);
      throw new ApiError(message, response.status, payload);
    }

    return payload as T;
  }

  private extractError(payload: unknown): string {
    if (payload && typeof payload === 'object') {
      const obj = payload as Record<string, unknown>;
      if (typeof obj['error'] === 'string') {
        return obj['error'];
      }
      if (typeof obj['message'] === 'string') {
        return obj['message'];
      }
    }
    return 'Request failed';
  }

  /**
   * Refresh the access token using the stored refresh token, posting to
   * `/auth/refresh` with `{ refresh_token }` — the same endpoint + shape
   * the legacy client uses. Returns true when a new access token was
   * stored. Never throws (network errors resolve to `false`).
   */
  async refreshToken(): Promise<boolean> {
    const refreshToken = this.tokens.getRefreshToken();
    if (!refreshToken) {
      return false;
    }
    try {
      const response = await this.doFetch(`${this.baseUrl}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refreshToken }),
      });
      if (!response.ok) {
        return false;
      }
      const data = (await response.json()) as {
        access_token?: string;
        refresh_token?: string;
      };
      if (typeof data.access_token !== 'string') {
        return false;
      }
      this.tokens.setAccessToken(data.access_token);
      if (typeof data.refresh_token === 'string') {
        this.tokens.setRefreshToken(data.refresh_token);
      }
      return true;
    } catch {
      // Network/parse failure — surface as "not refreshed", same as legacy.
      return false;
    }
  }

  get<T = unknown>(endpoint: string, params?: Record<string, string>): Promise<T> {
    const query = params
      ? '?' + new URLSearchParams(params).toString()
      : '';
    return this.request<T>('GET', endpoint + query);
  }

  post<T = unknown>(endpoint: string, data?: unknown): Promise<T> {
    return this.request<T>('POST', endpoint, data ?? null);
  }

  put<T = unknown>(endpoint: string, data?: unknown): Promise<T> {
    return this.request<T>('PUT', endpoint, data ?? null);
  }

  patch<T = unknown>(endpoint: string, data?: unknown): Promise<T> {
    return this.request<T>('PATCH', endpoint, data ?? null);
  }

  delete<T = unknown>(endpoint: string): Promise<T> {
    return this.request<T>('DELETE', endpoint);
  }

  /** Whether an access token is currently stored. */
  isLoggedIn(): boolean {
    return this.tokens.getAccessToken() !== null;
  }

  /**
   * Fetch the authenticated user via `GET /api/v1/auth/me`.
   *
   * The endpoint wraps the user in a `{ user: {...} }` envelope (see
   * `AuthController::me()`), and serializes `is_admin` as the DB's
   * `TINYINT` (`1`/`0`), never a JSON boolean. Unwrap the envelope and
   * normalise `is_admin` to a real boolean so callers can rely on
   * `user.is_admin === true`.
   */
  async getCurrentUser(): Promise<AuthUser> {
    const { user } = await this.get<{ user: Record<string, unknown> }>(
      '/api/v1/auth/me',
    );
    return { ...(user as AuthUser), is_admin: normalizeBool(user['is_admin']) };
  }

  /** Clear all tokens (logout) and redirect to the SSR login page. */
  logout(redirect = true): void {
    this.tokens.clear();
    if (redirect && typeof window !== 'undefined') {
      window.location.href = '/login';
    }
  }
}

/** Shared singleton used by the app (tests construct their own instance). */
export const api = new ApiClient();
