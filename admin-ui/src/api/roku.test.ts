import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { RokuApi, type RokuDevice, type RokuPlaybackState } from './roku';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: RokuApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new RokuApi(client), calls };
}

const rokuDevice: RokuDevice = {
  device_id: 'roku-1',
  name: 'Bedroom TV',
  host: '192.168.1.102',
  port: 8060,
  model: 'Roku Ultra',
  address: 'aa:bb:cc:dd:ee:02',
};

const rokuPlaybackState: RokuPlaybackState = {
  device_id: 'roku-1',
  media_title: 'My Show',
  media_item_id: 's1',
  transport_state: 'PLAYING',
  volume_level: 0.6,
  muted: false,
};

describe('RokuApi', () => {
  describe('listDevices()', () => {
    it('GETs /api/v1/roku/devices and returns device list', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: [rokuDevice] } },
      ]);

      const result = await api.listDevices();

      expect(calls[0]!.url).toBe('/api/v1/roku/devices');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([rokuDevice]);
    });

    it('returns empty array when no devices', async () => {
      const { api } = makeApi([
        { status: 200, body: { success: true, data: [] } },
      ]);

      const result = await api.listDevices();

      expect(result).toEqual([]);
    });
  });

  describe('getStatus()', () => {
    it('GETs /api/v1/roku/devices/:id/status and returns playback state', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: rokuPlaybackState } },
      ]);

      const result = await api.getStatus('roku-1');

      expect(calls[0]!.url).toContain('/api/v1/roku/devices/roku-1/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(rokuPlaybackState);
    });

    it('encodes deviceId in URL', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: rokuPlaybackState } },
      ]);

      await api.getStatus('device with spaces');

      expect(calls[0]!.url).toContain('device%20with%20spaces');
    });
  });

  describe('stop()', () => {
    it('POSTs to /api/v1/roku/devices/:id/stop', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.stop('roku-1');

      expect(calls[0]!.url).toContain('/api/v1/roku/devices/roku-1/stop');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  it('throws ApiError on non-2xx response', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.listDevices()).rejects.toBeInstanceOf(ApiError);
  });
});
