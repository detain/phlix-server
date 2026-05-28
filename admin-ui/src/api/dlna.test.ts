import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { DlnaApi, type DlnaDevice, type DlnaPlaybackState } from './dlna';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: DlnaApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new DlnaApi(client), calls };
}

const dlnaDevice: DlnaDevice = {
  device_id: 'dlna-1',
  name: 'Smart TV',
  host: '192.168.1.103',
  port: 1900,
  model: 'Samsung Smart TV',
  address: 'aa:bb:cc:dd:ee:03',
};

const dlnaPlaybackState: DlnaPlaybackState = {
  device_id: 'dlna-1',
  media_title: 'My Video',
  media_item_id: 'v1',
  transport_state: 'PLAYING',
  volume_level: 0.8,
  muted: false,
  duration_seconds: 5400,
  position_seconds: 1200,
};

describe('DlnaApi', () => {
  describe('listDevices()', () => {
    it('GETs /api/v1/dlna/devices and returns device list', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: [dlnaDevice] } },
      ]);

      const result = await api.listDevices();

      expect(calls[0]!.url).toBe('/api/v1/dlna/devices');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([dlnaDevice]);
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
    it('GETs /api/v1/dlna/devices/:id/status and returns playback state', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: dlnaPlaybackState } },
      ]);

      const result = await api.getStatus('dlna-1');

      expect(calls[0]!.url).toContain('/api/v1/dlna/devices/dlna-1/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(dlnaPlaybackState);
    });

    it('encodes deviceId in URL', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: dlnaPlaybackState } },
      ]);

      await api.getStatus('device with spaces');

      expect(calls[0]!.url).toContain('device%20with%20spaces');
    });
  });

  describe('play()', () => {
    it('POSTs to /api/v1/dlna/devices/:id/play', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Playing' } },
      ]);

      const result = await api.play('dlna-1');

      expect(calls[0]!.url).toContain('/api/v1/dlna/devices/dlna-1/play');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('pause()', () => {
    it('POSTs to /api/v1/dlna/devices/:id/pause', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Paused' } },
      ]);

      const result = await api.pause('dlna-1');

      expect(calls[0]!.url).toContain('/api/v1/dlna/devices/dlna-1/pause');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('stop()', () => {
    it('POSTs to /api/v1/dlna/devices/:id/stop', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.stop('dlna-1');

      expect(calls[0]!.url).toContain('/api/v1/dlna/devices/dlna-1/stop');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('seek()', () => {
    it('POSTs to /api/v1/dlna/devices/:id/seek with position_seconds', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.seek('dlna-1', 2700);

      expect(calls[0]!.url).toContain('/api/v1/dlna/devices/dlna-1/seek');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(calls[0]!.init!.body).toBe(JSON.stringify({ position_seconds: 2700 }));
      expect(result.success).toBe(true);
    });

    it('handles seek to position 0', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      await api.seek('dlna-1', 0);

      expect(calls[0]!.init!.body).toBe(JSON.stringify({ position_seconds: 0 }));
    });
  });

  it('throws ApiError on non-2xx response', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.listDevices()).rejects.toBeInstanceOf(ApiError);
  });
});
