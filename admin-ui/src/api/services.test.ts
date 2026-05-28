import { describe, expect, it } from 'vitest';
import { ApiClient } from './client';
import { TraktApi } from './trakt';
import { LastfmApi } from './lastfm';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi<T>(responses: Array<{ status: number; body: unknown }>, factory: (c: ApiClient) => T): {
  api: T;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: factory(client), calls };
}

describe('TraktApi', () => {
  describe('getStatus()', () => {
    it('GETs /api/v1/admin/services/trakt/status', async () => {
      const { api, calls } = makeApi(
        [{ status: 200, body: { connected: false, username: null } }],
        (c) => new TraktApi(c),
      );

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/services/trakt/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.connected).toBe(false);
    });

    it('returns username when connected', async () => {
      const { api } = makeApi(
        [{ status: 200, body: { connected: true, username: 'johndoe' } }],
        (c) => new TraktApi(c),
      );

      const result = await api.getStatus();

      expect(result.connected).toBe(true);
      expect(result.username).toBe('johndoe');
    });
  });

  describe('disconnect()', () => {
    it('POSTs /api/v1/admin/services/trakt/disconnect', async () => {
      const { api, calls } = makeApi(
        [{ status: 200, body: { message: 'Disconnected' } }],
        (c) => new TraktApi(c),
      );

      const result = await api.disconnect();

      expect(calls[0]!.url).toBe('/api/v1/admin/services/trakt/disconnect');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.message).toBe('Disconnected');
    });
  });
});

describe('LastfmApi', () => {
  describe('getStatus()', () => {
    it('GETs /api/v1/admin/services/lastfm/status', async () => {
      const { api, calls } = makeApi(
        [{ status: 200, body: { connected: false, username: null, api_key_set: false } }],
        (c) => new LastfmApi(c),
      );

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/services/lastfm/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.connected).toBe(false);
    });

    it('returns username when connected', async () => {
      const { api } = makeApi(
        [{ status: 200, body: { connected: true, username: 'johndoe', api_key_set: true } }],
        (c) => new LastfmApi(c),
      );

      const result = await api.getStatus();

      expect(result.connected).toBe(true);
      expect(result.username).toBe('johndoe');
    });
  });

  describe('disconnect()', () => {
    it('POSTs /api/v1/admin/services/lastfm/disconnect', async () => {
      const { api, calls } = makeApi(
        [{ status: 200, body: { message: 'Disconnected' } }],
        (c) => new LastfmApi(c),
      );

      const result = await api.disconnect();

      expect(calls[0]!.url).toBe('/api/v1/admin/services/lastfm/disconnect');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.message).toBe('Disconnected');
    });
  });
});
