import { describe, expect, it } from 'vitest';
import { ApiClient } from './client';
import { ArrSyncApi } from './arrSync';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: ArrSyncApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new ArrSyncApi(client), calls };
}

describe('ArrSyncApi', () => {
  describe('getStatus()', () => {
    it('GETs /api/v1/admin/sync/status', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: { enabled: true, last_sync_at: '2026-05-28T00:00:00Z', last_sync_timestamp: 1716864000 },
        },
      ]);

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/sync/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.enabled).toBe(true);
    });

    it('returns null last_sync_at when never synced', async () => {
      const { api } = makeApi([
        { status: 200, body: { enabled: false, last_sync_at: null, last_sync_timestamp: null } },
      ]);

      const result = await api.getStatus();

      expect(result.last_sync_at).toBeNull();
      expect(result.last_sync_timestamp).toBeNull();
      expect(result.enabled).toBe(false);
    });
  });

  describe('triggerSync()', () => {
    it('POSTs /api/v1/admin/sync/trash-guides', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Sync complete', data: {} } },
      ]);

      const result = await api.triggerSync();

      expect(calls[0]!.url).toBe('/api/v1/admin/sync/trash-guides');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
      expect(result.message).toBe('Sync complete');
    });

    it('throws ApiError on 500 failure response', async () => {
      const { api } = makeApi([
        { status: 500, body: { success: false, error: 'Sync failed' } },
      ]);

      await expect(api.triggerSync()).rejects.toThrow('Sync failed');
    });

    it('throws ApiError on 500 without error field', async () => {
      const { api } = makeApi([{ status: 500, body: {} }]);

      await expect(api.triggerSync()).rejects.toThrow('Request failed');
    });
  });

  describe('setEnabled()', () => {
    it('PUTs /api/v1/admin/sync/enable with { enabled: true }', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Sync enabled' } },
      ]);

      const result = await api.setEnabled(true);

      expect(calls[0]!.url).toBe('/api/v1/admin/sync/enable');
      expect(calls[0]!.init!.method).toBe('PUT');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({ enabled: true });
      expect(result.message).toBe('Sync enabled');
    });

    it('PUTs /api/v1/admin/sync/enable with { enabled: false }', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Sync disabled' } },
      ]);

      await api.setEnabled(false);

      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({ enabled: false });
    });
  });
});
