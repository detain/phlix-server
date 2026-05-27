import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

describe('ApiClient', () => {
  it('attaches the bearer header when an access token is present', async () => {
    const tokens = new MemoryTokenStore({ access: 'tok-123' });
    const { fetch, calls } = makeFetch([{ status: 200, body: { ok: true } }]);
    const client = new ApiClient({ baseUrl: 'https://h', tokenStore: tokens, fetchImpl: fetch });

    await client.get('/api/v1/auth/me');

    expect(calls).toHaveLength(1);
    expect(calls[0]!.url).toBe('https://h/api/v1/auth/me');
    const headers = (calls[0]!.init!.headers ?? {}) as Record<string, string>;
    expect(headers['Authorization']).toBe('Bearer tok-123');
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('omits the bearer header when no access token is stored', async () => {
    const tokens = new MemoryTokenStore();
    const { fetch, calls } = makeFetch([{ status: 200, body: {} }]);
    const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

    await client.get('/api/v1/libraries');

    const headers = (calls[0]!.init!.headers ?? {}) as Record<string, string>;
    expect(headers['Authorization']).toBeUndefined();
  });

  it('serialises a JSON body for POST/PUT/PATCH but not GET/DELETE', async () => {
    const tokens = new MemoryTokenStore({ access: 't' });
    const { fetch, calls } = makeFetch([
      { status: 200, body: {} },
      { status: 200, body: {} },
      { status: 200, body: {} },
      { status: 200, body: {} },
      { status: 200, body: {} },
    ]);
    const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

    await client.post('/p', { a: 1 });
    await client.put('/p', { b: 2 });
    await client.patch('/p', { c: 3 });
    await client.get('/p');
    await client.delete('/p');

    expect(calls[0]!.init!.body).toBe(JSON.stringify({ a: 1 }));
    expect(calls[1]!.init!.body).toBe(JSON.stringify({ b: 2 }));
    expect(calls[2]!.init!.body).toBe(JSON.stringify({ c: 3 }));
    expect(calls[3]!.init!.body).toBeUndefined();
    expect(calls[4]!.init!.body).toBeUndefined();
  });

  describe('token refresh on 401', () => {
    it('refreshes and retries once with the new token, then succeeds', async () => {
      const tokens = new MemoryTokenStore({ access: 'old', refresh: 'r-1' });
      // 1) original 401, 2) /auth/refresh 200, 3) retry 200
      const { fetch, calls } = makeFetch([
        { status: 401, body: { error: 'expired' } },
        { status: 200, body: { access_token: 'new', refresh_token: 'r-2' } },
        { status: 200, body: { id: 'u1' } },
      ]);
      const client = new ApiClient({ baseUrl: 'https://h', tokenStore: tokens, fetchImpl: fetch });

      const result = await client.get<{ id: string }>('/api/v1/auth/me');

      expect(result).toEqual({ id: 'u1' });
      // refresh call hit /auth/refresh with the refresh token body
      expect(calls[1]!.url).toBe('https://h/auth/refresh');
      expect(calls[1]!.init!.body).toBe(JSON.stringify({ refresh_token: 'r-1' }));
      // new tokens persisted
      expect(tokens.getAccessToken()).toBe('new');
      expect(tokens.getRefreshToken()).toBe('r-2');
      // retry carried the NEW bearer
      const retryHeaders = (calls[2]!.init!.headers ?? {}) as Record<string, string>;
      expect(retryHeaders['Authorization']).toBe('Bearer new');
    });

    it('does not retry when there is no refresh token (throws the 401)', async () => {
      const tokens = new MemoryTokenStore({ access: 'old' });
      const { fetch, calls } = makeFetch([{ status: 401, body: { error: 'nope' } }]);
      const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

      await expect(client.get('/api/v1/auth/me')).rejects.toBeInstanceOf(ApiError);
      expect(calls).toHaveLength(1);
    });

    it('throws the 401 when the refresh call itself fails', async () => {
      const tokens = new MemoryTokenStore({ access: 'old', refresh: 'r' });
      const { fetch, calls } = makeFetch([
        { status: 401, body: { error: 'expired' } },
        { status: 401, body: { error: 'bad refresh' } },
      ]);
      const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

      await expect(client.get('/api/v1/auth/me')).rejects.toMatchObject({ status: 401 });
      // original + refresh, but no third (retry) call
      expect(calls).toHaveLength(2);
    });

    it('keeps the old refresh token if the refresh response omits a new one', async () => {
      const tokens = new MemoryTokenStore({ access: 'old', refresh: 'keep-me' });
      const { fetch } = makeFetch([
        { status: 401, body: {} },
        { status: 200, body: { access_token: 'fresh' } },
        { status: 200, body: { ok: true } },
      ]);
      const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

      await client.get('/x');

      expect(tokens.getAccessToken()).toBe('fresh');
      expect(tokens.getRefreshToken()).toBe('keep-me');
    });

    it('refreshToken() returns false on a network error', async () => {
      const tokens = new MemoryTokenStore({ refresh: 'r' });
      const fetchImpl = (async () => {
        throw new Error('network down');
      }) as unknown as typeof fetch;
      const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl });

      await expect(client.refreshToken()).resolves.toBe(false);
    });

    it('refreshToken() returns false when the response has no access_token', async () => {
      const tokens = new MemoryTokenStore({ refresh: 'r' });
      const { fetch } = makeFetch([{ status: 200, body: { nothing: true } }]);
      const client = new ApiClient({ baseUrl: '', tokenStore: tokens, fetchImpl: fetch });

      await expect(client.refreshToken()).resolves.toBe(false);
    });
  });

  describe('error handling', () => {
    it('throws ApiError with the JSON `error` message and status', async () => {
      const { fetch } = makeFetch([{ status: 403, body: { error: 'Forbidden', code: 'auth.not_admin' } }]);
      const client = new ApiClient({ baseUrl: '', tokenStore: new MemoryTokenStore(), fetchImpl: fetch });

      await expect(client.get('/api/v1/admin/x')).rejects.toMatchObject({
        message: 'Forbidden',
        status: 403,
      });
    });

    it('falls back to the JSON `message` field then to a generic message', async () => {
      const c1 = new ApiClient({
        baseUrl: '',
        tokenStore: new MemoryTokenStore(),
        fetchImpl: makeFetch([{ status: 500, body: { message: 'boom' } }]).fetch,
      });
      await expect(c1.get('/x')).rejects.toMatchObject({ message: 'boom' });

      const c2 = new ApiClient({
        baseUrl: '',
        tokenStore: new MemoryTokenStore(),
        fetchImpl: makeFetch([{ status: 500, body: {} }]).fetch,
      });
      await expect(c2.get('/x')).rejects.toMatchObject({ message: 'Request failed' });
    });

    it('handles non-JSON (text) error bodies', async () => {
      const { fetch } = makeFetch([{ status: 502, body: 'gateway', json: false }]);
      const client = new ApiClient({ baseUrl: '', tokenStore: new MemoryTokenStore(), fetchImpl: fetch });

      await expect(client.get('/x')).rejects.toMatchObject({ message: 'Request failed', status: 502 });
    });
  });

  describe('helpers', () => {
    it('isLoggedIn reflects the stored access token', () => {
      const loggedOut = new ApiClient({ tokenStore: new MemoryTokenStore() });
      const loggedIn = new ApiClient({ tokenStore: new MemoryTokenStore({ access: 't' }) });
      expect(loggedOut.isLoggedIn()).toBe(false);
      expect(loggedIn.isLoggedIn()).toBe(true);
    });

    it('getCurrentUser unwraps the { user } envelope and normalizes is_admin (TINYINT 1 → true)', async () => {
      // Real wire shape: wrapped in { user }, is_admin as the DB TINYINT (1).
      const { fetch, calls } = makeFetch([
        { status: 200, body: { user: { id: 'me', is_admin: 1 } } },
      ]);
      const client = new ApiClient({ baseUrl: 'https://h', tokenStore: new MemoryTokenStore({ access: 't' }), fetchImpl: fetch });

      const user = await client.getCurrentUser();
      expect(user).toMatchObject({ id: 'me', is_admin: true });
      expect(calls[0]!.url).toBe('https://h/api/v1/auth/me');
    });

    it('appends query params on GET', async () => {
      const { fetch, calls } = makeFetch([{ status: 200, body: [] }]);
      const client = new ApiClient({ baseUrl: '', tokenStore: new MemoryTokenStore(), fetchImpl: fetch });
      await client.get('/api/v1/search', { q: 'star wars', page: '2' });
      expect(calls[0]!.url).toContain('/api/v1/search?');
      expect(calls[0]!.url).toContain('q=star+wars');
      expect(calls[0]!.url).toContain('page=2');
    });

    it('logout clears tokens', () => {
      const tokens = new MemoryTokenStore({ access: 'a', refresh: 'r', user: { id: 1 } });
      const client = new ApiClient({ tokenStore: tokens });
      client.logout(false);
      expect(tokens.getAccessToken()).toBeNull();
      expect(tokens.getRefreshToken()).toBeNull();
      expect(tokens.getUser()).toBeNull();
    });
  });
});
