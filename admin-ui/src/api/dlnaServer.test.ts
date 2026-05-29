import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { DlnaServerApi, type DlnaServerStatus } from './dlnaServer';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown; urlMatch?: string }>,
): {
  api: DlnaServerApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new DlnaServerApi(client), calls };
}

const dlnaServerStatusRunning: DlnaServerStatus = {
  enabled: true,
  running: true,
  serverId: 'uuid:phlix-server-main',
  friendlyName: 'Phlix Media Server',
  port: 8200,
  baseUrl: '192.168.1.100',
};

const dlnaServerStatusStopped: DlnaServerStatus = {
  enabled: true,
  running: false,
  serverId: 'uuid:phlix-server-main',
  friendlyName: 'Phlix Media Server',
  port: 8200,
  baseUrl: '192.168.1.100',
};

const dlnaServerStatusNotConfigured: DlnaServerStatus = {
  enabled: false,
  running: false,
  serverId: null,
  friendlyName: null,
  port: null,
  baseUrl: null,
  message: 'DLNA server not configured',
};

describe('DlnaServerApi', () => {
  describe('getStatus()', () => {
    it('GETs /api/v1/admin/dlna/status and returns server status (running)', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: dlnaServerStatusRunning, urlMatch: '/api/v1/admin/dlna/status' },
      ]);

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/dlna/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(dlnaServerStatusRunning);
    });

    it('GETs /api/v1/admin/dlna/status and returns server status (stopped)', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: dlnaServerStatusStopped, urlMatch: '/api/v1/admin/dlna/status' },
      ]);

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/dlna/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(dlnaServerStatusStopped);
    });

    it('GETs /api/v1/admin/dlna/status and returns not-configured status', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: dlnaServerStatusNotConfigured, urlMatch: '/api/v1/admin/dlna/status' },
      ]);

      const result = await api.getStatus();

      expect(calls[0]!.url).toBe('/api/v1/admin/dlna/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result.enabled).toBe(false);
      expect(result.message).toBe('DLNA server not configured');
    });
  });

  describe('start()', () => {
    it('POSTs to /api/v1/admin/dlna/start and returns success', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true }, urlMatch: '/api/v1/admin/dlna/start' },
      ]);

      const result = await api.start();

      expect(calls[0]!.url).toBe('/api/v1/admin/dlna/start');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });

    it('throws ApiError on non-2xx response', async () => {
      const { api } = makeApi([
        { status: 409, body: { success: false, message: 'DLNA server is already running' }, urlMatch: '/api/v1/admin/dlna/start' },
      ]);

      await expect(api.start()).rejects.toBeInstanceOf(ApiError);
    });
  });

  describe('stop()', () => {
    it('POSTs to /api/v1/admin/dlna/stop and returns success', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true }, urlMatch: '/api/v1/admin/dlna/stop' },
      ]);

      const result = await api.stop();

      expect(calls[0]!.url).toBe('/api/v1/admin/dlna/stop');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });

    it('throws ApiError on non-2xx response', async () => {
      const { api } = makeApi([
        { status: 409, body: { success: false, message: 'DLNA server is not running' }, urlMatch: '/api/v1/admin/dlna/stop' },
      ]);

      await expect(api.stop()).rejects.toBeInstanceOf(ApiError);
    });
  });

  it('throws ApiError on non-2xx getStatus response', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' }, urlMatch: '/api/v1/admin/dlna/status' },
    ]);

    await expect(api.getStatus()).rejects.toBeInstanceOf(ApiError);
  });
});
